<?php

use App\Enums\UserRole;
use App\Mail\NewContactMessageMail;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Formulário de Contacto público do site (Setembro 2026) — substitui o
 * protótipo que só fazia setSent(true) no frontend sem enviar nada.
 */
it('guarda a mensagem e envia o email para o destinatário configurado', function () {
    Mail::fake();
    config(['kerugas.contact.recipient' => 'contacto@kerugas.com']);

    $response = $this->postJson('/api/v1/contact', [
        'name' => 'Ana Silva',
        'contact' => '923123456',
        'message' => 'Gostaria de cadastrar o meu posto no KeruGás.',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('success', true);

    expect(ContactMessage::count())->toBe(1);
    $stored = ContactMessage::first();
    expect($stored->name)->toBe('Ana Silva');
    expect($stored->status)->toBe('NEW');
    expect($stored->ip_address)->not->toBeNull();

    Mail::assertQueued(NewContactMessageMail::class, function (NewContactMessageMail $mail) use ($stored) {
        return $mail->contactMessage->is($stored)
            && $mail->hasTo('contacto@kerugas.com');
    });
});

it('rejeita quando o campo de honeypot vem preenchido, sem revelar que é anti-spam', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/contact', [
        'name' => 'Bot',
        'contact' => 'bot@example.com',
        'message' => 'spam',
        'website' => 'http://spam.example.com',
    ]);

    $response->assertStatus(422);
    expect(ContactMessage::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('valida campos obrigatórios', function () {
    $response = $this->postJson('/api/v1/contact', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name', 'contact', 'message']);
});

it('guarda a mensagem mesmo que o envio de email falhe (nunca perde o contacto do visitante)', function () {
    config(['kerugas.contact.recipient' => 'contacto@kerugas.com']);
    Mail::shouldReceive('to->send')->andThrow(new \Exception('SMTP em baixo'));

    $response = $this->postJson('/api/v1/contact', [
        'name' => 'Carlos',
        'contact' => 'carlos@example.com',
        'message' => 'Preciso de ajuda com o meu pedido.',
    ]);

    $response->assertCreated();
    expect(ContactMessage::count())->toBe(1);
});

it('só o Super Admin vê as mensagens no painel — nem o Admin comum tem acesso', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['role' => UserRole::USER]);

    ContactMessage::create(['name' => 'Primeiro', 'contact' => 'a@a.com', 'message' => 'msg 1', 'status' => 'NEW']);
    ContactMessage::create(['name' => 'Segundo', 'contact' => 'b@b.com', 'message' => 'msg 2', 'status' => 'NEW']);

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/admin/contact-messages')->assertStatus(403);

    // Decisão explícita do cliente: ao contrário da generalidade das
    // secções do painel, o Admin comum NÃO vê as mensagens de contacto
    // — só o Super Admin. Por isso CONTACT_VIEW não está no mapa do
    // ADMIN em RolePermissions, apesar do Admin ter outras permissões.
    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/admin/contact-messages')->assertStatus(403);

    Sanctum::actingAs($superAdmin);
    $response = $this->getJson('/api/v1/admin/contact-messages');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('meta.unread_count'))->toBe(2);
    expect($response->json('data.0.name'))->toBe('Segundo'); // mais recente primeiro
});

it('marca uma mensagem como lida (Super Admin) e recusa o Admin comum', function () {
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $admin = User::factory()->admin()->create();
    $message = ContactMessage::create(['name' => 'Ana', 'contact' => 'a@a.com', 'message' => 'msg', 'status' => 'NEW']);

    Sanctum::actingAs($admin);
    $this->patchJson("/api/v1/admin/contact-messages/{$message->id}/read")->assertStatus(403);

    Sanctum::actingAs($superAdmin);
    $response = $this->patchJson("/api/v1/admin/contact-messages/{$message->id}/read");

    $response->assertOk();
    expect($message->fresh()->status)->toBe('READ');
});
