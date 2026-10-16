<?php

use App\Models\Province;
use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Cabeçalhos de segurança da API (Outubro 2026): presentes mesmo sem o
 * nginx à frente (Laragon, ngrok) e também nos erros.
 */
function securityHeadersTestAssertBasics(\Illuminate\Testing\TestResponse $res): void
{
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($res->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($res->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($res->headers->get('X-Powered-By'))->toBeNull();
}

it('as respostas da API trazem os cabeçalhos de segurança', function () {
    Province::factory()->create();

    $res = $this->getJson('/api/v1/provinces')->assertOk();

    securityHeadersTestAssertBasics($res);
    expect($res->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'self'");
    // HSTS nunca fora de produção (localhost / ngrok)
    expect($res->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('também nos erros: 404 de rota inexistente, 401 e 422', function () {
    securityHeadersTestAssertBasics($this->getJson('/api/v1/nao-existe')->assertNotFound());
    securityHeadersTestAssertBasics($this->getJson('/api/v1/users/me')->assertUnauthorized());
    securityHeadersTestAssertBasics($this->postJson('/api/v1/auth/login', [])->assertStatus(422));
});

it('a foto de perfil mantém a sua CSP com sandbox e continua em cache', function () {
    Storage::fake(AvatarService::DISK);
    $file = str_repeat('c', 40) . '.webp';
    Storage::disk(AvatarService::DISK)->put('avatars/' . $file, 'img');
    User::factory()->create(['avatar_path' => 'avatars/' . $file]);

    $res = $this->get('/api/v1/avatars/' . $file)->assertOk();

    expect($res->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox");
    expect($res->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    // o app Android (outra origem) tem de conseguir mostrar a foto
    expect($res->headers->get('Cross-Origin-Resource-Policy'))->toBe('cross-origin');
});
