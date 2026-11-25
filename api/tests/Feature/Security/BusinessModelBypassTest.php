<?php

/**
 * Auditoria de segurança dos modelos B2B e B2C (Novembro 2026).
 *
 * Cada teste aqui reproduz um caminho real que alguém mal-intencionado podia
 * usar para ver o estado dos postos sem pagar (B2C), para tirar proveito de
 * um posto sem presença ou sem pedidos pagos (B2B), ou para mexer onde não
 * tem autoridade. Todos falhavam antes da correcção.
 */

use App\Enums\QueueLevel;
use App\Enums\StationDataAccessEffect;
use App\Enums\StationListingOverride;
use App\Enums\StationOrdersOverride;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Events\GasAvailabilityChanged;
use App\Events\StationStatusUpdated;
use App\Listeners\DispatchStationAlertsListener;
use App\Listeners\StationCampaignListener;
use App\Models\Alert;
use App\Models\AppNotification;
use App\Models\CampaignDelivery;
use App\Models\CommunityMessage;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationDataGrant;
use App\Models\StationStatusUpdate;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Community\CommunityLocationService;
use App\Services\Sms\OperationService;
use App\Services\Subscriptions\SubscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);

    $this->posto = Station::factory()->create([
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
        'status' => 'ACTIVE', 'current_gas_available' => true, 'current_queue_level' => QueueLevel::LOW,
    ]);

    $this->cliente = User::factory()->create([
        'role' => UserRole::USER, 'status' => 'ACTIVE',
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
    ]);

    // O Lobito passou a pago.
    $this->lobito->forceFill(['station_data_blocked_from' => now()->subMinute()])->save();
});

function auditoriaExigirPresenca(): void
{
    app(SubscriptionSettings::class)->update(['listing_enforcement' => true]);
}

// ───────────────────────────── autorização

it('um Provincial sem província atribuída não mexe em município nenhum (falha fechada)', function () {
    $semProvincia = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => null]);
    Sanctum::actingAs($semProvincia);

    $this->putJson("/api/v1/admin/station-data-access/municipalities/{$this->lubango->id}", ['blocked' => false])
        ->assertForbidden();
    $this->getJson('/api/v1/admin/station-data-access/municipalities')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('as excepções só se aplicam a clientes e pessoal de posto, nunca a contas da equipa', function () {
    $colega = User::factory()->create(['role' => UserRole::ADMIN, 'province_id' => $this->benguela->id]);
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]));

    $this->putJson("/api/v1/admin/station-data-access/users/{$colega->id}", ['effect' => 'DENY'])
        ->assertStatus(422);
    expect(StationDataGrant::count())->toBe(0);
});

it('só o Super Admin geral marca um posto como da própria KeruGás', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]));

    $this->postJson("/api/v1/admin/stations/{$this->posto->id}/listing", ['override' => 'INHERIT', 'platform_owned' => true])
        ->assertForbidden();
    expect($this->posto->fresh()->platform_owned)->toBeFalse();

    // Mandar o valor que já lá está não é mudança nenhuma,o painel envia sempre o campo.
    $this->postJson("/api/v1/admin/stations/{$this->posto->id}/listing", ['override' => 'ALLOW', 'reason' => 'cortesia', 'platform_owned' => false])
        ->assertOk();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));
    $this->postJson("/api/v1/admin/stations/{$this->posto->id}/listing", ['override' => 'INHERIT', 'platform_owned' => true])
        ->assertOk();
    expect($this->posto->fresh()->platform_owned)->toBeTrue();
});

// ───────────────────────────── contornos do pagamento do cliente (B2C)

it('promover um amigo a operador de posto não lhe abre a consulta paga', function () {
    $amigo = User::factory()->create([
        'role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->posto->id,
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
    ]);
    Sanctum::actingAs($amigo);

    $this->getJson("/api/v1/stations/{$this->posto->id}")->assertForbidden();
    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(0, 'data');
});

it('o mesmo vale para condutores criados pelo gerente do posto', function () {
    Sanctum::actingAs(User::factory()->create([
        'role' => UserRole::STATION_DRIVER, 'driver_station_id' => $this->posto->id,
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
    ]));

    $this->getJson("/api/v1/stations/{$this->posto->id}")->assertForbidden();
});

it('uma conta da equipa suspensa perde o acesso livre, mesmo com o token antigo', function () {
    $suspenso = User::factory()->create(['role' => UserRole::ADMIN, 'status' => 'SUSPENDED', 'province_id' => $this->benguela->id]);
    Sanctum::actingAs($suspenso);

    $this->getJson("/api/v1/stations/{$this->posto->id}")->assertForbidden();
});

it('uma excepção ALLOW deixa de valer se a conta for suspensa', function () {
    StationDataGrant::create(['user_id' => $this->cliente->id, 'effect' => StationDataAccessEffect::ALLOW]);
    $this->cliente->update(['status' => 'SUSPENDED']);
    Sanctum::actingAs($this->cliente->fresh());

    $this->getJson("/api/v1/stations/{$this->posto->id}")->assertForbidden();
});

it('adicionar aos favoritos não devolve o estado de um posto que a pessoa não pode ver', function () {
    Sanctum::actingAs($this->cliente);

    $res = $this->postJson("/api/v1/favorites/{$this->posto->id}")->assertForbidden();
    expect($res->json('data.station.gas_available'))->toBeNull()
        ->and($res->json('reason'))->toBe('municipality_blocked');
});

it('os comentários e avaliações de um posto fechado também ficam fechados', function () {
    Sanctum::actingAs($this->cliente);

    $this->getJson("/api/v1/stations/{$this->posto->id}/comments")->assertForbidden();
    $this->getJson("/api/v1/stations/{$this->posto->id}/reviews")->assertForbidden();
    $this->postJson("/api/v1/stations/{$this->posto->id}/confirmations", ['gas_available' => true])->assertForbidden();
});

it('o canal público de tempo real não transmite o estado do gás', function () {
    $update = StationStatusUpdate::factory()->create(['station_id' => $this->posto->id]);

    $payload = (new StationStatusUpdated($this->posto, $update))->broadcastWith();

    expect($payload)->not->toHaveKey('gas_available')
        ->and($payload)->not->toHaveKey('queue_level')
        ->and($payload)->not->toHaveKey('station_id');
});

it('os alertas de gás não chegam a quem não pode consultar o município', function () {
    Alert::create(['user_id' => $this->cliente->id, 'municipality_id' => $this->lobito->id, 'channels' => ['in_app'], 'active' => true]);

    app(DispatchStationAlertsListener::class)->handle(new GasAvailabilityChanged($this->posto, false, QueueLevel::HIGH));

    expect(AppNotification::where('user_id', $this->cliente->id)->count())->toBe(0);

    // Quem pagou (excepção ALLOW) continua a receber.
    StationDataGrant::create(['user_id' => $this->cliente->id, 'effect' => StationDataAccessEffect::ALLOW]);
    app(DispatchStationAlertsListener::class)->handle(new GasAvailabilityChanged($this->posto, false, QueueLevel::HIGH));

    expect(AppNotification::where('user_id', $this->cliente->id)->count())->toBe(1);
});

it('as campanhas "há gás perto de si" e "fila curta" respeitam o município fechado', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Africa/Luanda')->utc());
    $this->lobito->forceFill(['station_data_blocked_from' => now()->subMinute()])->save();
    $pagante = User::factory()->create(['role' => UserRole::USER, 'municipality_id' => $this->lobito->id]);
    StationDataGrant::create(['user_id' => $pagante->id, 'effect' => StationDataAccessEffect::ALLOW]);
    \App\Models\Favorite::create(['user_id' => $this->cliente->id, 'station_id' => $this->posto->id]);

    app(StationCampaignListener::class)->handle(new GasAvailabilityChanged($this->posto, false, QueueLevel::HIGH));

    expect(CampaignDelivery::where('campaign_key', 'gas_back_nearby')->pluck('user_id')->all())->toBe([$pagante->id])
        ->and(CampaignDelivery::where('user_id', $this->cliente->id)->count())->toBe(0);
});

it('a partilha de um posto nos Vizinhos não mostra o gás a quem não pode consultar', function () {
    $mensagem = new CommunityMessage([
        'municipality_id' => $this->lobito->id, 'user_id' => $this->cliente->id, 'body' => '',
        'location_kind' => 'station', 'location_station_id' => $this->posto->id,
        'location_expires_at' => now()->addHour(),
    ]);
    $mensagem->setRelation('locationStation', $this->posto);

    $visto = CommunityLocationService::present($mensagem, $this->cliente, 'visible');
    expect($visto['station']['gas_available'])->toBeNull()
        ->and($visto['station']['queue_level'])->toBeNull()
        ->and($visto['station']['name'])->toBe($this->posto->name);

    StationDataGrant::create(['user_id' => $this->cliente->id, 'effect' => StationDataAccessEffect::ALLOW]);
    $visto = CommunityLocationService::present($mensagem, $this->cliente->fresh(), 'visible');
    expect($visto['station']['gas_available'])->toBeTrue();
});

// ───────────────────────────── contornos do pagamento do posto (B2B)

it('um posto suspenso não recebe pedidos, nem com subscrição nem com o interruptor ligado', function () {
    $this->posto->update(['status' => 'SUSPENDED', 'orders_override' => StationOrdersOverride::ALLOW]);

    expect($this->posto->fresh()->canReceiveOrders(true))->toBeFalse();
});

it('com a presença exigida, um posto sem presença não sai pelos favoritos nem pela Central', function () {
    auditoriaExigirPresenca();
    $this->lobito->forceFill(['station_data_blocked_from' => null])->save();
    Sanctum::actingAs($this->cliente);

    $this->postJson("/api/v1/favorites/{$this->posto->id}")->assertNotFound();
    $this->getJson("/api/v1/stations/{$this->posto->id}/comments")->assertNotFound();

    expect(app(OperationService::class)->stationsForMunicipality($this->lobito))->toHaveCount(0);

    Subscription::create([
        'type' => SubscriptionType::STATION_LISTING, 'station_id' => $this->posto->id,
        'status' => Subscription::STATUS_ACTIVE, 'starts_at' => now()->subDay(),
        'expires_at' => now()->addWeek(), 'months' => 0, 'weeks' => 1, 'amount_kz' => 0,
    ]);
    expect(app(OperationService::class)->stationsForMunicipality($this->lobito))->toHaveCount(1);
});

it('um posto inactivo não tem ficha pública', function () {
    $this->lobito->forceFill(['station_data_blocked_from' => null])->save();
    $this->posto->update(['status' => 'PENDING']);

    $this->getJson("/api/v1/stations/{$this->posto->id}")->assertNotFound();
});
