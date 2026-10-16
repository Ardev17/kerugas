<?php

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AppNotification;
use App\Models\CommunityMessage;
use App\Models\Favorite;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Prova de isolamento entre contas (Outubro 2026).
 *
 * Em cada recurso, a conta A cria um registo e a conta B tenta LER, ALTERAR
 * e APAGAR esse registo. Todas as tentativas têm de dar 403 ou 404, e no fim
 * o registo de A tem de estar exatamente como estava.
 *
 * Recursos sem ID público (favoritos, perfil) não podem ser apontados por
 * outra conta — aí prova-se que a tentativa de B nunca toca nos dados de A.
 */
beforeEach(function () {
    CommunityAccess::flush();
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);

    $this->userA = User::factory()->create([
        'role' => UserRole::USER,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'community_accepted_at' => now(),
    ]);
    $this->userB = User::factory()->create([
        'role' => UserRole::USER,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'community_accepted_at' => now(),
    ]);
});

/** A resposta tem de ser 403 ou 404 — nunca 200/201/204. */
function xuDenied(TestResponse $response, string $what): void
{
    expect($response->status())->toBeIn([403, 404], "{$what}: esperado 403/404, recebido {$response->status()}");
}

// ---------------------------------------------------------------- pedidos de gás

it('pedido de gás: B não lê, não cancela e não acompanha o pedido de A', function () {
    $order = GasOrder::factory()->create([
        'user_id' => $this->userA->id,
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'customer_phone' => '923111222',
        'status' => 'RECEIVED',
    ]);

    Sanctum::actingAs($this->userB);

    xuDenied($this->getJson("/api/v1/orders/reference/{$order->order_reference}"), 'ler pedido');
    xuDenied($this->patchJson("/api/v1/orders/{$order->id}/cancel"), 'cancelar pedido');
    // Sem conta: só com referência + telefone certo.
    xuDenied($this->getJson("/api/v1/orders/reference/{$order->order_reference}/track?customer_phone=923999888"), 'acompanhar com outro telefone');

    // A lista "Os meus pedidos" de B não mostra o pedido de A.
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonMissing(['reference' => $order->order_reference]);

    expect($order->fresh()->status->value ?? $order->fresh()->status)->toBe('RECEIVED');
});

// ---------------------------------------------------------------- alertas

it('alertas: B não altera nem apaga o alerta de A, nem o vê na sua lista', function () {
    $alert = Alert::create([
        'user_id' => $this->userA->id,
        'municipality_id' => $this->municipality->id,
        'channels' => ['in_app'],
        'active' => true,
    ]);

    Sanctum::actingAs($this->userB);

    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
    xuDenied($this->putJson("/api/v1/alerts/{$alert->id}", [
        'municipality_id' => $this->municipality->id,
        'channels' => ['sms'],
    ]), 'alterar alerta');
    xuDenied($this->deleteJson("/api/v1/alerts/{$alert->id}"), 'apagar alerta');

    $fresh = $alert->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->channels)->toBe(['in_app'])
        ->and($fresh->user_id)->toBe($this->userA->id);
});

// ---------------------------------------------------------------- notificações

it('notificações: B não lê nem marca como lida a notificação de A', function () {
    $notification = AppNotification::create([
        'user_id' => $this->userA->id,
        'category' => 'order',
        'title' => 'O seu pedido foi confirmado',
        'body' => 'Privado da conta A',
    ]);

    Sanctum::actingAs($this->userB);

    xuDenied($this->getJson("/api/v1/notifications/{$notification->id}"), 'ler notificação');
    xuDenied($this->patchJson("/api/v1/notifications/{$notification->id}/read"), 'marcar como lida');
    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['body' => 'Privado da conta A']);
    // "Marcar todas como lidas" de B só mexe nas notificações de B.
    $this->postJson('/api/v1/notifications/read-all')->assertSuccessful();

    expect($notification->fresh()->read_at)->toBeNull();
});

// ---------------------------------------------------------------- Vizinhos

it('Vizinhos: B não edita nem apaga a mensagem de A', function () {
    $message = CommunityMessage::create([
        'municipality_id' => $this->municipality->id,
        'user_id' => $this->userA->id,
        'body' => 'Mensagem original da conta A',
    ]);

    Sanctum::actingAs($this->userB);

    // Ler é público dentro do município (é uma conversa de bairro) — por isso
    // aqui prova-se só que B não altera nem apaga o que é de A.
    xuDenied($this->putJson("/api/v1/community/messages/{$message->id}", ['body' => 'Alterado por B']), 'editar mensagem');
    xuDenied($this->deleteJson("/api/v1/community/messages/{$message->id}"), 'apagar mensagem');

    $fresh = $message->fresh();
    expect($fresh->body)->toBe('Mensagem original da conta A')
        ->and($fresh->isVisible())->toBeTrue();
});

// ---------------------------------------------------------------- favoritos

it('favoritos: B nunca vê nem remove o favorito de A', function () {
    $station = Station::factory()->create();
    Favorite::create(['user_id' => $this->userA->id, 'station_id' => $station->id]);

    Sanctum::actingAs($this->userB);

    // Os favoritos não têm ID público: a rota usa sempre a conta da sessão.
    $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/favorites/{$station->id}")->assertSuccessful();

    expect(Favorite::where('user_id', $this->userA->id)->where('station_id', $station->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- entregas do condutor

it('condutor: o condutor B não conclui nem cancela a entrega atribuída ao condutor A', function () {
    $driverA = User::factory()->create([
        'role' => UserRole::DRIVER, 'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id, 'driver_available' => true,
    ]);
    $driverB = User::factory()->create([
        'role' => UserRole::DRIVER, 'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id, 'driver_available' => true,
    ]);
    giveDriverStock($driverA);
    giveDriverStock($driverB);

    $order = GasOrder::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'status' => 'ASSIGNED',
        'assigned_to' => $driverA->id,
    ]);

    Sanctum::actingAs($driverB);

    $this->getJson('/api/v1/driver/orders?status=ASSIGNED')->assertOk()->assertJsonCount(0, 'data');
    xuDenied($this->postJson("/api/v1/driver/orders/{$order->id}/complete"), 'concluir entrega de outro condutor');
    xuDenied($this->postJson("/api/v1/driver/orders/{$order->id}/cancel", ['reason' => 'teste']), 'cancelar entrega de outro condutor');

    $fresh = $order->fresh();
    expect($fresh->status->value ?? $fresh->status)->toBe('ASSIGNED')
        ->and((int) $fresh->assigned_to)->toBe($driverA->id);
});

// ---------------------------------------------------------------- foto de perfil

it('foto de perfil: um cliente comum não consegue apagar a foto de outra conta', function () {
    Sanctum::actingAs($this->userB);

    xuDenied($this->deleteJson("/api/v1/admin/users/{$this->userA->id}/avatar"), 'apagar foto de outra conta');
});

// ---------------------------------------------------------------- campos proibidos (mass assignment)

it('perfil: B não consegue promover-se nem mexer noutra conta através do pedido', function () {
    Sanctum::actingAs($this->userB);

    $this->putJson('/api/v1/users/me', [
        'name' => 'Conta B',
        // Campos que um utilizador nunca pode definir:
        'id' => $this->userA->id,
        'user_id' => $this->userA->id,
        'role' => 'SUPER_ADMIN',
        'status' => 'ACTIVE',
        'driver_available' => true,
        'can_promote_users' => true,
        'sms_daily_limit' => 999999,
    ])->assertOk();

    $b = $this->userB->fresh();
    $a = $this->userA->fresh();
    expect($b->name)->toBe('Conta B')
        ->and($b->role)->toBe(UserRole::USER)
        ->and((bool) $b->can_promote_users)->toBeFalse()
        ->and($a->name)->toBe($this->userA->name)
        ->and($a->role)->toBe(UserRole::USER);
});
