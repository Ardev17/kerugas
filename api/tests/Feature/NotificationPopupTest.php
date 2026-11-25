<?php

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Push\PushMessageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Popup das notificações sem ecrã próprio (Outubro 2026). */
function plainNotification(User $user, array $data = []): AppNotification
{
    return AppNotification::create([
        'user_id' => $user->id,
        'category' => NotificationCategory::SYSTEM,
        'title' => 'Aviso',
        'body' => str_repeat('Mensagem longa. ', 30),
        'data' => $data,
    ]);
}

it('devolve a notificação completa ao dono e marca-a como lida', function () {
    $user = User::factory()->create();
    $n = plainNotification($user);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/notifications/{$n->id}")->assertOk()->assertJsonPath('data.title', 'Aviso');
    expect($n->fresh()->read_at)->not->toBeNull();
});

it('nunca mostra a notificação de outra pessoa', function () {
    $n = plainNotification(User::factory()->create());
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/notifications/{$n->id}")->assertNotFound();
});

it('a push de uma notificação sem ecrã abre o popup; com ecrã mantém o caminho', function () {
    $user = User::factory()->create();
    $factory = app(PushMessageFactory::class);

    $plain = plainNotification($user);
    expect($factory->fromNotification($plain, $user)->path)->toBe('/?notificacao=' . $plain->id);

    $withPath = plainNotification($user, ['path' => '/operator']);
    expect($factory->fromNotification($withPath, $user)->path)->toBe('/operator');
});
