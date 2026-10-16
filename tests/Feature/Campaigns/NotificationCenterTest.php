<?php

use App\Enums\UserRole;
use App\Jobs\SendBroadcastJob;
use App\Jobs\SendCampaignSmsJob;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\CampaignDelivery;
use App\Models\Municipality;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Africa/Luanda')->utc());
    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->municipality = Municipality::factory()->create();
    $this->customers = User::factory()->count(3)->create([
        'role' => UserRole::USER,
        'province_id' => $this->municipality->province_id,
        'municipality_id' => $this->municipality->id,
    ]);
    $this->driver = User::factory()->create(['role' => UserRole::DRIVER]);
});

it('só o Super Admin gere notificações — o Admin comum recebe 403', function () {
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson('/api/v1/admin/notifications-center/campaigns')->assertForbidden();
    $this->postJson('/api/v1/admin/notifications-center/broadcasts', [])->assertForbidden();
});

it('lista todas as campanhas com estado e resultados', function () {
    Sanctum::actingAs($this->super);

    $response = $this->getJson('/api/v1/admin/notifications-center/campaigns')->assertOk();

    expect(collect($response->json('data'))->pluck('key'))->toContain('gas_reminder', 'weekend_eve', 'operator_stale', 'admin_daily_summary');
    expect(collect($response->json('data'))->firstWhere('key', 'gas_reminder')['sms_enabled'])->toBeTrue();
});

it('o Super Admin desliga uma campanha e isso fica registado na auditoria', function () {
    Sanctum::actingAs($this->super);

    $this->patchJson('/api/v1/admin/notifications-center/campaigns/weekend_eve', ['enabled' => false])->assertOk();

    expect(NotificationCampaign::where('key', 'weekend_eve')->first()->enabled)->toBeFalse()
        ->and(AuditLog::where('action', 'notification_campaign.updated')->exists())->toBeTrue();
});

it('recusa SMS com links (a operadora bloqueia) ou com mais de 160 carateres', function () {
    Sanctum::actingAs($this->super);

    $this->patchJson('/api/v1/admin/notifications-center/campaigns/gas_reminder', [
        'templates' => [['title' => 'T', 'body' => 'B', 'sms' => 'Peca em www.kerugas.ao']],
    ])->assertStatus(422)->assertJsonValidationErrors(['templates.0.sms']);

    $this->postJson('/api/v1/admin/notifications-center/broadcasts', [
        'audience' => 'users', 'title' => 'T', 'body' => 'B', 'send_sms' => true, 'sms_text' => str_repeat('a', 161),
    ])->assertStatus(422)->assertJsonValidationErrors(['sms_text']);
});

it('pré-visualiza alcance e custo de SMS antes de enviar, com filtro por município', function () {
    Sanctum::actingAs($this->super);
    User::factory()->create(['role' => UserRole::USER]); // outro município

    $response = $this->postJson('/api/v1/admin/notifications-center/broadcasts/preview', [
        'audience' => 'users', 'municipality_id' => $this->municipality->id,
        'send_sms' => true, 'sms_text' => 'KeruGás: novidade para si!',
    ])->assertOk();

    expect($response->json('data.recipients'))->toBe(3)
        ->and($response->json('data.sms_recipients'))->toBe(3)
        ->and($response->json('data.sms.segments'))->toBe(1)
        ->and($response->json('data.sms.encoding'))->toBe('GSM-7')
        ->and((float) $response->json('data.sms_cost_estimate_kz'))->toBe(300.0);
});

it('envia já a um público: cria o envio, e o job entrega uma vez a cada pessoa', function () {
    Sanctum::actingAs($this->super);

    $this->postJson('/api/v1/admin/notifications-center/broadcasts', [
        'audience' => 'users', 'kind' => 'announcement', 'title' => 'Olá {nome}!', 'body' => 'Novidades no KeruGás.',
        'path' => '/pedir-gas', 'send_sms' => true, 'sms_text' => 'KeruGas: novidades na app!',
    ])->assertCreated();

    $broadcast = Broadcast::first();
    Queue::assertPushed(SendBroadcastJob::class);

    (new SendBroadcastJob($broadcast->id))->handle(app(CampaignService::class));
    (new SendBroadcastJob($broadcast->id))->handle(app(CampaignService::class)); // repetir não duplica

    expect(CampaignDelivery::where('broadcast_id', $broadcast->id)->count())->toBe(3)
        ->and($broadcast->fresh()->status)->toBe('SENT')
        ->and($broadcast->fresh()->recipients_count)->toBe(3)
        ->and(CampaignDelivery::where('user_id', $this->driver->id)->count())->toBe(0);
    Queue::assertPushed(SendCampaignSmsJob::class, 3);
});

it('promoções manuais respeitam quem desligou as promoções', function () {
    $this->customers[0]->update(['notification_preferences' => ['promos' => false]]);
    $broadcast = Broadcast::create([
        'created_by' => $this->super->id, 'audience' => 'users', 'kind' => 'promo',
        'title' => 'Promo', 'body' => 'Desconto', 'status' => 'SENDING', 'scheduled_at' => now(),
    ]);

    (new SendBroadcastJob($broadcast->id))->handle(app(CampaignService::class));

    expect(CampaignDelivery::where('broadcast_id', $broadcast->id)->count())->toBe(2);
});

it('agendar: só sai à hora marcada, e pode ser cancelado antes', function () {
    Sanctum::actingAs($this->super);

    $this->postJson('/api/v1/admin/notifications-center/broadcasts', [
        'audience' => 'drivers', 'title' => 'Reunião', 'body' => 'Amanhã às 9h.',
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
    ])->assertCreated();
    $broadcast = Broadcast::first();
    expect($broadcast->status)->toBe('SCHEDULED');
    Queue::assertNotPushed(SendBroadcastJob::class);

    $this->artisan('kerugas:broadcasts-due');
    Queue::assertNotPushed(SendBroadcastJob::class);

    $this->travel(4)->hours();
    $this->artisan('kerugas:broadcasts-due');
    Queue::assertPushed(SendBroadcastJob::class, 1);
    expect($broadcast->fresh()->status)->toBe('SENDING');
});

it('cancelar um envio agendado', function () {
    Sanctum::actingAs($this->super);
    $broadcast = Broadcast::create([
        'created_by' => $this->super->id, 'audience' => 'users', 'title' => 'T', 'body' => 'B',
        'status' => 'SCHEDULED', 'scheduled_at' => now()->addDay(),
    ]);

    $this->postJson("/api/v1/admin/notifications-center/broadcasts/{$broadcast->id}/cancel")->assertOk();

    expect($broadcast->fresh()->status)->toBe('CANCELLED');
});

it('testar uma campanha envia só para o próprio Super Admin', function () {
    Sanctum::actingAs($this->super);

    $this->postJson('/api/v1/admin/notifications-center/campaigns/gas_reminder/test')->assertOk()->assertJsonPath('success', true);

    expect(CampaignDelivery::pluck('user_id')->all())->toBe([$this->super->id]);
});
