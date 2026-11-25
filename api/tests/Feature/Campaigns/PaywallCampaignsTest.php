<?php

use App\Enums\GasOrderStatus;
use App\Enums\OperationStatus;
use App\Enums\SmsTemplateType;
use App\Enums\StationDataAccessEffect;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Jobs\NotifyMunicipalityReopenedJob;
use App\Models\CampaignDelivery;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\Province;
use App\Models\SmsTemplate;
use App\Models\Station;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Campaigns\AutomaticCampaignRunner;
use App\Services\Campaigns\CampaignCatalog;
use App\Services\Sms\SmsTemplateRenderer;
use App\Services\Subscriptions\SubscriptionSettings;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-11-18 11:00', 'Africa/Luanda')->utc()); // quarta-feira
    $this->seed(SmsTemplateSeeder::class);
    $this->runner = app(AutomaticCampaignRunner::class);

    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id, 'name' => 'Lobito']);
    $this->customer = User::factory()->create([
        'role' => UserRole::USER, 'phone' => '+244923000111',
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id,
    ]);
    $this->station = Station::factory()->create([
        'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'status' => 'ACTIVE',
    ]);
    $this->operator = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    assignOperator($this->operator, $this->station);
    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
});

function got(User $u, string $key): int
{
    return CampaignDelivery::where('user_id', $u->id)->where('campaign_key', $key)->count();
}

function consult(string $phone, int $times, $at = null): void
{
    $template = SmsTemplate::where('code', 'AVAILABILITY')->firstOrFail();
    for ($i = 0; $i < $times; $i++) {
        $op = Operation::create(['admin_id' => test()->super->id, 'phone' => $phone,
            'sms_template_id' => $template->id, 'status' => OperationStatus::SENT]);
        if ($at) {
            Operation::whereKey($op->id)->update(['updated_at' => $at, 'created_at' => $at]);
        }
    }
}

it('tem todos os novos modelos de SMS com ≤160 carateres, sem links e só variáveis permitidas', function () {
    $renderer = app(SmsTemplateRenderer::class);
    $novos = [
        SmsTemplateType::FREE_CONSULTS_LOW, SmsTemplateType::FREE_CONSULTS_EXHAUSTED, SmsTemplateType::FREE_CONSULTS_RENEWED,
        SmsTemplateType::MUNICIPALITY_PAID, SmsTemplateType::ACCESS_GRANTED, SmsTemplateType::ACCESS_REVOKED,
        SmsTemplateType::STATION_OFF_MAP, SmsTemplateType::ORDER_TAKEN_BY_OTHER,
    ];

    foreach ($novos as $type) {
        $t = SmsTemplate::where('type', $type->value)->firstOrFail();
        expect($renderer->validate($t->content))->toBe([]);
        expect($type->isConsult())->toBeFalse();

        $texto = $renderer->render($t->content, [
            'restantes' => '1', 'limite' => '3', 'contacto' => '+244 923 000 000', 'municipio' => 'Lobito',
            'data' => '30/11', 'validade' => 'até 31/12/2026', 'posto' => 'Posto Central',
        ]);
        expect(mb_strlen($texto))->toBeLessThanOrEqual(160)
            ->and(strtolower($texto))->not->toContain('http')->not->toContain('www');
    }
});

it('o catálogo tem os caminhos certos e todas as chaves agendadas correm no executor', function () {
    foreach (PaywallKeys() as $key) {
        expect(CampaignCatalog::find($key))->not->toBeNull();
    }
    foreach (\App\Services\Campaigns\PaywallCampaigns::keys() as $key) {
        expect(AutomaticCampaignRunner::keys())->toContain($key);
        expect($this->runner->run($key))->toBeInt(); // nenhuma rebenta numa base vazia
    }
});

function PaywallKeys(): array
{
    return ['municipality_paywall_soon', 'municipality_paywall_started', 'municipality_free_again', 'free_consults_low',
        'free_consults_exhausted', 'free_consults_renewed', 'access_granted', 'access_revoked', 'sms_subscription_expiring',
        'station_weekly_summary', 'order_unanswered', 'orders_missed', 'listing_invite', 'admin_subscriptions_expiring',
        'admin_grace_ending', 'admin_paywall_changed', 'admin_municipality_uncovered'];
}

// ------------------------------------------------------------- clientes

it('município a fechar em 3 dias: avisa uma vez e não avisa quem tem excepção ALLOW', function () {
    $this->municipality->forceFill(['station_data_blocked_from' => now()->addDays(2)])->save();
    $vip = User::factory()->create(['role' => UserRole::USER, 'municipality_id' => $this->municipality->id]);
    \App\Models\StationDataGrant::create(['user_id' => $vip->id, 'effect' => StationDataAccessEffect::ALLOW]);

    $this->runner->run('municipality_paywall_soon');
    $this->runner->run('municipality_paywall_soon');

    expect(got($this->customer, 'municipality_paywall_soon'))->toBe(1)
        ->and(got($vip, 'municipality_paywall_soon'))->toBe(0);
});

it('município que fechou hoje: avisa os clientes de lá e só esses', function () {
    $this->municipality->forceFill(['station_data_blocked_from' => now()->subHours(3)])->save();
    $outro = User::factory()->create(['role' => UserRole::USER,
        'municipality_id' => Municipality::factory()->create(['province_id' => $this->province->id])->id]);

    $this->runner->run('municipality_paywall_started');

    expect(got($this->customer, 'municipality_paywall_started'))->toBe(1)
        ->and(got($outro, 'municipality_paywall_started'))->toBe(0);
});

it('consultas grátis: avisa quando resta uma e quando esgotam, uma vez por mês, e nunca a subscritores', function () {
    app(SubscriptionSettings::class)->update(['sms_enforcement' => true, 'free_consults_per_month' => 3]);
    consult('+244923000111', 2);

    $this->runner->run('free_consults_low');
    $this->runner->run('free_consults_low');
    expect(got($this->customer, 'free_consults_low'))->toBe(1)
        ->and(got($this->customer, 'free_consults_exhausted'))->toBe(0);

    consult('+244923000111', 1);
    $this->runner->run('free_consults_exhausted');
    $this->runner->run('free_consults_exhausted');
    expect(got($this->customer, 'free_consults_exhausted'))->toBe(1);

    $sub = User::factory()->create(['role' => UserRole::USER, 'phone' => '+244923000222']);
    Subscription::create(['type' => SubscriptionType::SMS_CONSULT, 'phone' => '+244923000222', 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(20), 'months' => 1]);
    consult('+244923000222', 3);
    $this->runner->run('free_consults_exhausted');
    expect(got($sub, 'free_consults_exhausted'))->toBe(0);
});

it('consultas grátis: sem o limite ligado, nada é enviado', function () {
    app(SubscriptionSettings::class)->update(['sms_enforcement' => false, 'free_consults_per_month' => 3]);
    consult('+244923000111', 3);

    expect($this->runner->run('free_consults_exhausted'))->toBe(0)
        ->and($this->runner->run('free_consults_low'))->toBe(0);
});

it('consultas renovadas: no mês novo, a quem esgotou o anterior', function () {
    app(SubscriptionSettings::class)->update(['sms_enforcement' => true, 'free_consults_per_month' => 3]);
    $this->travelTo(Carbon::parse('2026-12-01 10:00', 'Africa/Luanda')->utc());
    consult('+244923000111', 3, Carbon::parse('2026-11-20 10:00', 'Africa/Luanda')->utc());

    $this->runner->run('free_consults_renewed');

    expect(got($this->customer, 'free_consults_renewed'))->toBe(1);
});

it('subscrição de consultas a acabar: avisa uma vez e não avisa se já renovou', function () {
    Subscription::create(['type' => SubscriptionType::SMS_CONSULT, 'phone' => '+244923000111', 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDays(25), 'expires_at' => now()->addDays(4), 'months' => 1]);

    $this->runner->run('sms_subscription_expiring');
    $this->runner->run('sms_subscription_expiring');
    expect(got($this->customer, 'sms_subscription_expiring'))->toBe(1);

    CampaignDelivery::query()->delete();
    Subscription::create(['type' => SubscriptionType::SMS_CONSULT, 'phone' => '+244923000111', 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->addDays(4), 'expires_at' => now()->addDays(34), 'months' => 1]);
    $this->runner->run('sms_subscription_expiring');
    expect(got($this->customer, 'sms_subscription_expiring'))->toBe(0);
});

// ------------------------------------------------------ postos e pedidos

it('pedido por aceitar: avisa o posto que pode receber, e não o que não pode', function () {
    GasOrder::factory()->create(['municipality_id' => $this->municipality->id, 'province_id' => $this->province->id,
        'status' => GasOrderStatus::PENDING, 'station_id' => null, 'created_at' => now()->subMinutes(30)]);

    $this->runner->run('order_unanswered');
    expect(got($this->operator, 'order_unanswered'))->toBe(0); // sem subscrição de pedidos

    Subscription::create(['type' => SubscriptionType::STATION_ORDERS, 'station_id' => $this->station->id, 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(5), 'weeks' => 1]);
    $this->runner->run('order_unanswered');
    $this->runner->run('order_unanswered');

    expect(got($this->operator, 'order_unanswered'))->toBe(1);
});

it('pedidos perdidos e resumo semanal: o posto sem subscrição sabe quantos foram para outros', function () {
    $outro = Station::factory()->create(['province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'status' => 'ACTIVE']);
    GasOrder::factory()->count(2)->create(['municipality_id' => $this->municipality->id, 'province_id' => $this->province->id,
        'status' => GasOrderStatus::DELIVERED, 'station_id' => $outro->id, 'accepted_at' => now()->subDays(2), 'completed_at' => now()->subDay()]);

    $this->runner->run('orders_missed');
    $this->runner->run('station_weekly_summary');

    $n = \App\Models\AppNotification::where('user_id', $this->operator->id)->get();
    expect(got($this->operator, 'orders_missed'))->toBe(1)
        ->and(got($this->operator, 'station_weekly_summary'))->toBe(1)
        ->and($n->pluck('body')->implode(' '))->toContain('2 pedidos');
});

it('convite de presença: só com a presença paga ligada e só a postos fora do mapa', function () {
    expect($this->runner->run('listing_invite'))->toBe(0);

    app(SubscriptionSettings::class)->update(['listing_enforcement' => true]);
    $this->runner->run('listing_invite');
    expect(got($this->operator, 'listing_invite'))->toBe(1);

    CampaignDelivery::query()->delete();
    Subscription::create(['type' => SubscriptionType::STATION_LISTING, 'station_id' => $this->station->id, 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(20), 'months' => 1]);
    $this->runner->run('listing_invite');
    expect(got($this->operator, 'listing_invite'))->toBe(0);
});

// ------------------------------------------------------------------ admin

it('admin: subscrições a acabar, postos a sair do mapa e municípios sem posto', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $fora = User::factory()->admin()->create(['province_id' => Province::factory()->create()->id]);

    Subscription::create(['type' => SubscriptionType::STATION_LISTING, 'station_id' => $this->station->id, 'status' => Subscription::STATUS_ACTIVE,
        'starts_at' => now()->subDays(25), 'expires_at' => now()->addDays(3), 'months' => 1]);
    $this->runner->run('admin_subscriptions_expiring');
    expect(got($admin, 'admin_subscriptions_expiring'))->toBe(1)
        ->and(got($this->super, 'admin_subscriptions_expiring'))->toBe(1)
        ->and(got($fora, 'admin_subscriptions_expiring'))->toBe(0);

    // Presença expirada há 3 dias, tolerância de 4: sai do mapa dentro de ~1 dia.
    app(SubscriptionSettings::class)->update(['listing_enforcement' => true]);
    Subscription::query()->update(['starts_at' => now()->subDays(33), 'expires_at' => now()->subDays(3)]);
    $this->runner->run('admin_grace_ending');
    expect(got($admin, 'admin_grace_ending'))->toBe(1)->and(got($fora, 'admin_grace_ending'))->toBe(0);

    GasOrder::factory()->create(['municipality_id' => $this->municipality->id, 'province_id' => $this->province->id, 'created_at' => now()->subDay()]);
    $this->runner->run('admin_municipality_uncovered');
    expect(got($admin, 'admin_municipality_uncovered'))->toBe(1)->and(got($fora, 'admin_municipality_uncovered'))->toBe(0);
});

// -------------------------------------------------------------- eventos

it('acesso concedido e retirado: a pessoa é avisada na hora', function () {
    Sanctum::actingAs($this->super);

    $this->putJson("/api/v1/admin/station-data-access/users/{$this->customer->id}", ['effect' => 'ALLOW'])->assertOk();
    expect(got($this->customer, 'access_granted'))->toBe(1);

    $this->putJson("/api/v1/admin/station-data-access/users/{$this->customer->id}", ['effect' => 'DENY'])->assertOk();
    expect(got($this->customer, 'access_revoked'))->toBe(1);

    $this->putJson("/api/v1/admin/station-data-access/users/{$this->customer->id}", ['effect' => 'INHERIT'])->assertOk();
    expect(got($this->customer, 'access_revoked'))->toBe(1); // vir de DENY para INHERIT não é "retirado"
});

it('município reaberto: os clientes são avisados; os outros admins sabem da mudança', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->municipality->forceFill(['station_data_blocked_from' => now()->subDay()])->save();
    Sanctum::actingAs($this->super);

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->municipality->id}", ['blocked' => false])->assertOk();

    Queue::assertPushed(NotifyMunicipalityReopenedJob::class);
    expect(got($admin, 'admin_paywall_changed'))->toBe(1)
        ->and(got($this->super, 'admin_paywall_changed'))->toBe(0); // quem fez a mudança não é avisado

    (new NotifyMunicipalityReopenedJob($this->municipality->id, 'x'))->handle(app(\App\Services\Campaigns\CampaignService::class));
    expect(got($this->customer, 'municipality_free_again'))->toBe(1);
});
