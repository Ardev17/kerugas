<?php

use App\Enums\OperationStatus;
use App\Enums\SmsTemplateType;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\Province;
use App\Models\SmsDispatch;
use App\Models\SmsTemplate;
use App\Models\Station;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\SmsTemplateRenderer;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSmsProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SmsTemplateSeeder::class);

    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);

    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->operator = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'phone' => '+244923111222']);
    $this->station = Station::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'analytics_enabled' => true,
    ]);
    assignOperator($this->operator, $this->station);

    $this->sms = new FakeSmsProvider();
    app()->instance(SmsProviderInterface::class, $this->sms);

    $this->template = fn (string $code) => SmsTemplate::where('code', $code)->firstOrFail();

    // Um atendimento já ENVIADO ao cliente (gasta uma consulta se for de consulta).
    $this->sentConsult = function (string $phone, string $code = 'AVAILABILITY', $at = null): Operation {
        $op = Operation::create([
            'admin_id' => $this->super->id,
            'phone' => $phone,
            'sms_template_id' => ($this->template)($code)->id,
            'status' => OperationStatus::SENT,
        ]);
        if ($at) {
            Operation::whereKey($op->id)->update(['updated_at' => $at, 'created_at' => $at]);
        }

        return $op;
    };

    $this->draft = fn (string $phone = '+244923000111'): Operation => Operation::create([
        'admin_id' => $this->super->id,
        'phone' => $phone,
        'status' => OperationStatus::DRAFT,
    ]);

    $this->enforce = fn (bool $on = true) => app(SubscriptionService::class)->settings()->update(['sms_enforcement' => $on]);
});

// ------------------------------------------------------------------ templates

it('os templates de subscrição existem, sem links, sem preço e cabem em 160 carateres', function () {
    $renderer = new SmsTemplateRenderer();
    $codes = ['SUBSCRIPTION_REQUIRED', 'SUBSCRIPTION_EXPIRED', 'SUBSCRIPTION_ACTIVATED', 'OPERATOR_ANALYTICS'];

    foreach ($codes as $code) {
        $template = ($this->template)($code);
        expect($template->active)->toBeTrue();
        expect($template->content)->not->toMatch('/(https?:|www\.|\.com\b|\.ao\b)/i')
            ->and($template->content)->not->toContain('{{valor}}')
            ->and($template->content)->not->toMatch('/\d\s?Kz/i');

        $rendered = $renderer->render($template->content, ['contacto' => '+244923000000', 'validade' => '04/11/2026']);
        expect(mb_strlen($rendered))->toBeLessThanOrEqual(160);
    }

    expect(($this->template)('SUBSCRIPTION_REQUIRED')->type)->toBe(SmsTemplateType::SUBSCRIPTION_REQUIRED);
});

it('{{valor}} fica vazio sem preço definido e formatado quando o preço existe', function () {
    $service = app(SubscriptionService::class);

    expect($service->templateContext('+244923000111')['valor'])->toBe('');

    $service->settings()->update(['price_sms_kz' => 5000]);

    expect($service->templateContext('+244923000111')['valor'])->toBe('5.000 Kz');
});

// --------------------------------------------------------------------- acesso

it('só o Super Admin gere subscrições', function () {
    $this->getJson('/api/v1/admin/subscriptions')->assertUnauthorized();

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/admin/subscriptions')->assertForbidden();
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456'])->assertForbidden();
    $this->getJson('/api/v1/admin/subscriptions/settings')->assertForbidden();

    Sanctum::actingAs($this->super);
    $this->getJson('/api/v1/admin/subscriptions')->assertOk();
});

// ------------------------------------------------------------------- ativação

it('ativa uma subscrição de SMS com telemóvel normalizado e validade de um mês', function () {
    Sanctum::actingAs($this->super);

    $res = $this->postJson('/api/v1/admin/subscriptions', [
        'type' => 'SMS_CONSULT',
        'phone' => '923 123 456',
        'months' => 1,
        'amount_kz' => 5000,
        'payment_reference' => 'Comprovativo via WhatsApp',
    ])->assertCreated();

    $res->assertJsonPath('data.phone', '+244923123456')
        ->assertJsonPath('data.state', 'ACTIVE')
        ->assertJsonPath('data.activated_by', $this->super->name);

    $sub = Subscription::firstOrFail();
    expect($sub->expires_at->isSameDay(now()->addMonthNoOverflow()))->toBeTrue();
    expect(AuditLog::where('action', 'subscription.activated')->exists())->toBeTrue();
});

it('recusa telemóvel inválido e tipo/posto em falta', function () {
    Sanctum::actingAs($this->super);

    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '12345'])->assertStatus(422);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT'])->assertStatus(422)->assertJsonValidationErrors('phone');
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS'])->assertStatus(422)->assertJsonValidationErrors('station_id');
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'OUTRO'])->assertStatus(422);
    expect(Subscription::count())->toBe(0);
});

it('renovar começa quando o período anterior acaba (nenhum dia pago se perde)', function () {
    Sanctum::actingAs($this->super);

    $first = $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456', 'months' => 1])->json('data');
    $second = $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456', 'months' => 2])->json('data');

    expect($second['starts_at'])->toBe($first['expires_at']);
    expect(Subscription::count())->toBe(2);
});

it('cancela uma subscrição e deixa de contar como ativa', function () {
    Sanctum::actingAs($this->super);
    $id = $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456'])->json('data.id');

    $this->postJson("/api/v1/admin/subscriptions/{$id}/cancel")->assertOk()->assertJsonPath('data.state', 'CANCELLED');
    $this->postJson("/api/v1/admin/subscriptions/{$id}/cancel")->assertStatus(422);

    expect(app(SubscriptionService::class)->activeSmsSubscription('+244923123456'))->toBeNull();
});

it('a listagem filtra por estado e mostra o resumo', function () {
    Sanctum::actingAs($this->super);
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456']);
    Subscription::create([
        'type' => 'SMS_CONSULT', 'phone' => '+244923999888', 'status' => 'ACTIVE',
        'starts_at' => now()->subMonths(2), 'expires_at' => now()->subMonth(), 'months' => 1,
    ]);

    $this->getJson('/api/v1/admin/subscriptions?state=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.active_sms', 1);
    $this->getJson('/api/v1/admin/subscriptions?state=expired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'EXPIRED');
    $this->getJson('/api/v1/admin/subscriptions?search=999888')->assertOk()->assertJsonCount(1, 'data');
});

// ------------------------------------------------- limite de 3 consultas grátis

it('com a obrigatoriedade desligada (omissão) nunca bloqueia, por muitas consultas que haja', function () {
    Sanctum::actingAs($this->super);
    foreach (range(1, 5) as $_) {
        ($this->sentConsult)('+244923000111');
    }

    $op = ($this->draft)();
    $this->postJson("/api/v1/admin/operations/{$op->id}/send", ['sms_template_id' => ($this->template)('AVAILABILITY')->id])
        ->assertCreated();
});

it('a 4.ª consulta do mês é bloqueada sem subscrição e nada é enviado', function () {
    Sanctum::actingAs($this->super);
    ($this->enforce)();
    foreach (range(1, 3) as $_) {
        ($this->sentConsult)('+244923000111');
    }

    $op = ($this->draft)();
    $this->postJson("/api/v1/admin/operations/{$op->id}/send", ['sms_template_id' => ($this->template)('AVAILABILITY')->id])
        ->assertStatus(402)
        ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED')
        ->assertJsonPath('data.used', 3)
        ->assertJsonPath('data.suggested_template_code', 'SUBSCRIPTION_REQUIRED');

    expect($this->sms->sent)->toBeEmpty();
    expect(SmsDispatch::count())->toBe(0);
    expect(AuditLog::where('action', 'sms_subscription_blocked')->exists())->toBeTrue();
});

it('as 3 primeiras consultas passam (a 3.ª ainda é grátis)', function () {
    Sanctum::actingAs($this->super);
    ($this->enforce)();
    ($this->sentConsult)('+244923000111');
    ($this->sentConsult)('+244923000111');

    $op = ($this->draft)();
    $this->postJson("/api/v1/admin/operations/{$op->id}/send", ['sms_template_id' => ($this->template)('NO_GAS')->id])
        ->assertCreated();
});

it('o template de subscrição passa sempre e não gasta consultas', function () {
    Sanctum::actingAs($this->super);
    ($this->enforce)();
    foreach (range(1, 3) as $_) {
        ($this->sentConsult)('+244923000111');
    }

    $op = ($this->draft)();
    $this->postJson("/api/v1/admin/operations/{$op->id}/send", ['sms_template_id' => ($this->template)('SUBSCRIPTION_REQUIRED')->id])
        ->assertCreated();

    expect($this->sms->sent)->toHaveCount(1);
    expect($this->sms->sent[0]['message'])->toContain('subscri');
    expect(app(SubscriptionService::class)->consultsUsed('+244923000111'))->toBe(3);
});

it('com subscrição ativa a consulta passa; terminada, volta a ser bloqueada com o template de renovação', function () {
    Sanctum::actingAs($this->super);
    ($this->enforce)();
    foreach (range(1, 3) as $_) {
        ($this->sentConsult)('+244923000111');
    }
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923000111']);

    $op = ($this->draft)();
    $this->postJson("/api/v1/admin/operations/{$op->id}/send", ['sms_template_id' => ($this->template)('AVAILABILITY')->id])
        ->assertCreated();

    // Termina a subscrição.
    Subscription::query()->update(['expires_at' => now()->subDay(), 'starts_at' => now()->subMonth()]);

    $op2 = ($this->draft)();
    $this->getJson("/api/v1/admin/operations/{$op2->id}/subscription")
        ->assertOk()
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.suggested_template_code', 'SUBSCRIPTION_EXPIRED')
        ->assertJsonPath('data.suggested_template_id', ($this->template)('SUBSCRIPTION_EXPIRED')->id);
});

it('as consultas do mês passado não contam (o limite repõe-se todos os meses)', function () {
    Sanctum::actingAs($this->super);
    ($this->enforce)();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);
    foreach (range(1, 4) as $_) {
        ($this->sentConsult)('+244923000111', 'AVAILABILITY', $lastMonth);
    }

    $op = ($this->draft)();
    $this->getJson("/api/v1/admin/operations/{$op->id}/subscription")
        ->assertOk()->assertJsonPath('data.used', 0)->assertJsonPath('data.remaining', 3)->assertJsonPath('data.blocked', false);
});

it('o limite é por telemóvel e é configurável', function () {
    Sanctum::actingAs($this->super);
    ($this->enforce)();
    app(SubscriptionService::class)->settings()->update(['free_consults_per_month' => 1]);
    ($this->sentConsult)('+244923000111');

    $other = ($this->draft)('+244923000222');
    $this->getJson("/api/v1/admin/operations/{$other->id}/subscription")->assertOk()->assertJsonPath('data.blocked', false);

    $same = ($this->draft)('+244923000111');
    $this->getJson("/api/v1/admin/operations/{$same->id}/subscription")->assertOk()->assertJsonPath('data.blocked', true);
});

it('mensagem de subscrição usa o contacto configurado e não revela preço', function () {
    Sanctum::actingAs($this->super);
    app(SubscriptionService::class)->settings()->update(['contact' => '+244999888777']);

    $op = ($this->draft)();
    $res = $this->postJson("/api/v1/admin/operations/{$op->id}/preview", ['sms_template_id' => ($this->template)('SUBSCRIPTION_REQUIRED')->id])->assertOk();

    expect($res->json('data.message'))->toContain('+244999888777')->not->toMatch('/\d\s?Kz/i');
    expect($res->json('data.characters'))->toBeLessThanOrEqual(160);
});

// ---------------------------------------------- analytics do operador (aviso)

it('desativar o analytics notifica o operador automaticamente; reativar também', function () {
    Sanctum::actingAs($this->super);

    $this->postJson("/api/v1/admin/stations/{$this->station->id}/analytics-toggle")
        ->assertOk()->assertJsonPath('meta.operator_notified', true);

    $n = AppNotification::where('user_id', $this->operator->id)->latest('id')->firstOrFail();
    expect($n->title)->toBe('Analytics desativado')
        ->and($n->body)->toContain('Olá, caro operador')->toContain('Atualize a subscrição')
        ->and($n->data['path'])->toBe('/operator');

    $this->postJson("/api/v1/admin/stations/{$this->station->id}/analytics-toggle")->assertOk();
    expect(AppNotification::where('user_id', $this->operator->id)->latest('id')->first()->title)->toBe('Analytics ativado');
});

it('posto sem operador: alternar o analytics funciona e ninguém é notificado', function () {
    Sanctum::actingAs($this->super);
    $this->operator->update(['operated_station_id' => null]);

    $this->postJson("/api/v1/admin/stations/{$this->station->id}/analytics-toggle")
        ->assertOk()->assertJsonPath('meta.operator_notified', false);

    expect(AppNotification::count())->toBe(0);
});

it('o aviso manual por SMS vai para o telemóvel do operador, só pelo Super Admin', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/admin/stations/{$this->station->id}/subscription-notice")->assertForbidden();

    Sanctum::actingAs($this->super);
    $this->postJson("/api/v1/admin/stations/{$this->station->id}/subscription-notice")->assertCreated();

    expect($this->sms->sent)->toHaveCount(1);
    expect($this->sms->sent[0]['to'])->toBe('+244923111222');
    expect($this->sms->sent[0]['message'])->toContain('operador')->toContain('analytics');
    expect(AuditLog::where('action', 'station.subscription_notice_sent')->exists())->toBeTrue();
});

it('o aviso manual falha com clareza quando o posto não tem operador', function () {
    Sanctum::actingAs($this->super);
    $this->operator->update(['operated_station_id' => null]);

    $this->postJson("/api/v1/admin/stations/{$this->station->id}/subscription-notice")->assertStatus(422);
    expect($this->sms->sent)->toBeEmpty();
});

// ----------------------------------------------------- subscrição do posto

it('ativar a subscrição de um posto NÃO liga o analytics por omissão, mas notifica o operador', function () {
    Sanctum::actingAs($this->super);
    $this->station->update(['analytics_enabled' => false]);

    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS', 'station_id' => $this->station->id])
        ->assertCreated()->assertJsonPath('meta.operator_notified', true)->assertJsonPath('meta.analytics_enabled', false);

    expect($this->station->fresh()->analytics_enabled)->toBeFalse();
    expect(AppNotification::where('user_id', $this->operator->id)->first()->title)->toBe('Subscrição ativada');
});

it('pode ligar o analytics no mesmo passo quando o Super Admin o pede', function () {
    Sanctum::actingAs($this->super);
    $this->station->update(['analytics_enabled' => false]);

    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS', 'station_id' => $this->station->id, 'enable_analytics' => true])
        ->assertCreated()->assertJsonPath('meta.analytics_enabled', true);

    expect($this->station->fresh()->analytics_enabled)->toBeTrue();
    expect(AppNotification::where('user_id', $this->operator->id)->count())->toBe(1);
});

it('o SMS de confirmação só sai quando pedido', function () {
    Sanctum::actingAs($this->super);

    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456'])->assertCreated();
    expect($this->sms->sent)->toBeEmpty();

    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123457', 'send_confirmation_sms' => true])
        ->assertCreated()->assertJsonPath('meta.confirmation_sms', 'sent');
    expect($this->sms->sent)->toHaveCount(1);
    expect($this->sms->sent[0]['to'])->toBe('+244923123457');
    expect($this->sms->sent[0]['message'])->toContain('Subscri');
});

it('o operador vê a situação da sua subscrição e como pagar, só do próprio posto', function () {
    Sanctum::actingAs($this->super);
    $this->putJson('/api/v1/admin/subscriptions/settings', ['iban' => 'AO06 0040 0000 1234 5678 9012 3', 'bank_name' => 'BAI', 'price_analytics_kz' => 10000])->assertOk();
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'STATION_ANALYTICS', 'station_id' => $this->station->id]);

    Sanctum::actingAs($this->operator);
    $this->getJson('/api/v1/operator/station/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscribed', true)
        ->assertJsonPath('data.payment.iban', 'AO06 0040 0000 1234 5678 9012 3')
        ->assertJsonPath('data.payment.price', '10.000 Kz');

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::STATION_OPERATOR]));
    $this->getJson('/api/v1/operator/station/subscription')->assertNotFound();
});

// ------------------------------------------------------------- definições

it('as definições validam o IBAN e rejeitam links no contacto; preço 0 = por definir', function () {
    Sanctum::actingAs($this->super);

    $this->putJson('/api/v1/admin/subscriptions/settings', ['iban' => 'isto não é um iban'])->assertStatus(422)->assertJsonValidationErrors('iban');
    $this->putJson('/api/v1/admin/subscriptions/settings', ['contact' => 'https://wa.me/244'])->assertStatus(422)->assertJsonValidationErrors('contact');

    $this->putJson('/api/v1/admin/subscriptions/settings', ['sms_enforcement' => true, 'free_consults_per_month' => 3, 'price_sms_kz' => 0])
        ->assertOk()->assertJsonPath('data.sms_enforcement', true)->assertJsonPath('data.price_sms_kz', null);

    expect(AuditLog::where('action', 'subscription.settings_updated')->exists())->toBeTrue();
});

it('por omissão a obrigatoriedade vem desligada e com 3 consultas grátis', function () {
    Sanctum::actingAs($this->super);

    $this->getJson('/api/v1/admin/subscriptions/settings')
        ->assertOk()->assertJsonPath('data.sms_enforcement', false)->assertJsonPath('data.free_consults_per_month', 3);
});

it('a consulta de um telemóvel mostra consultas gastas e histórico', function () {
    Sanctum::actingAs($this->super);
    ($this->sentConsult)('+244923123456');
    $this->postJson('/api/v1/admin/subscriptions', ['type' => 'SMS_CONSULT', 'phone' => '923123456']);

    $this->getJson('/api/v1/admin/subscriptions/lookup?phone=923123456')
        ->assertOk()->assertJsonPath('data.status.used', 1)->assertJsonPath('data.status.subscribed', true)->assertJsonCount(1, 'data.history');
    $this->getJson('/api/v1/admin/subscriptions/lookup?phone=abc')->assertStatus(422);
});

// ------------------------------------------------------ lembrete automático

it('o comando avisa o operador uma só vez quando a subscrição está a terminar', function () {
    Subscription::create([
        'type' => 'STATION_ANALYTICS', 'station_id' => $this->station->id, 'status' => 'ACTIVE',
        'starts_at' => now()->subMonth(), 'expires_at' => now()->addDays(3), 'months' => 1,
    ]);

    Artisan::call('kerugas:subscriptions-check');
    Artisan::call('kerugas:subscriptions-check');

    $notes = AppNotification::where('user_id', $this->operator->id)->get();
    expect($notes)->toHaveCount(1);
    expect($notes->first()->title)->toBe('A subscrição está a terminar');
});

it('o comando não avisa quem já renovou nem quem ainda tem muito tempo', function () {
    Subscription::create([
        'type' => 'STATION_ANALYTICS', 'station_id' => $this->station->id, 'status' => 'ACTIVE',
        'starts_at' => now()->subMonth(), 'expires_at' => now()->addDays(3), 'months' => 1,
    ]);
    Subscription::create([
        'type' => 'STATION_ANALYTICS', 'station_id' => $this->station->id, 'status' => 'ACTIVE',
        'starts_at' => now()->addDays(3), 'expires_at' => now()->addDays(33), 'months' => 1,
    ]);

    Artisan::call('kerugas:subscriptions-check');

    expect(AppNotification::count())->toBe(0);
});
