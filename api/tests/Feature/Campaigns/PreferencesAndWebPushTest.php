<?php

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('preferências: tudo ligado por omissão, e muda só o que foi enviado', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/users/me/notification-preferences')
        ->assertOk()
        ->assertJson(['data' => ['reminders' => true, 'alerts' => true, 'tips' => true, 'promos' => true, 'sms' => true]]);

    $this->putJson('/api/v1/users/me/notification-preferences', ['promos' => false])
        ->assertOk()
        ->assertJson(['data' => ['promos' => false, 'reminders' => true]]);

    expect($user->fresh()->wantsNotifications('promos'))->toBeFalse()
        ->and($user->fresh()->wantsNotifications('reminders'))->toBeTrue();
});

it('Web Push: sem chaves VAPID, o site sabe que está desligado', function () {
    config(['kerugas.push.vapid_public_key' => null]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/web-push/public-key')->assertOk()->assertJsonPath('data.enabled', false);
});

it('Web Push: inscreve o navegador, e ao sair da conta essa inscrição desaparece', function () {
    $user = User::factory()->create();
    $token = $user->createToken('web')->plainTextToken;
    $payload = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
    ];

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/web-push/subscriptions', $payload)->assertCreated();
    // Repetir a inscrição do mesmo navegador não duplica.
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/web-push/subscriptions', $payload)->assertCreated();
    expect(WebPushSubscription::count())->toBe(1);

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout')->assertOk();
    expect(WebPushSubscription::count())->toBe(0);
});

it('Web Push: só aceita endereços de serviços de push por HTTPS', function () {
    Sanctum::actingAs(User::factory()->create());

    foreach (['http://atacante.local/push', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com.atacante.com/x', 'https://fcm.googleapis.com:8443/x'] as $endpoint) {
        $this->postJson('/api/v1/web-push/subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'x', 'auth' => 'y'],
        ])->assertStatus(422)->assertJsonValidationErrors(['endpoint']);
    }

    expect(WebPushSubscription::count())->toBe(0);
});
