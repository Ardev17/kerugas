<?php

use App\Enums\UserRole;
use App\Models\ClientError;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Relatórios de erros do site/app (Outubro 2026). */
function clientErrorPayload(array $extra = []): array
{
    return array_merge([
        'source' => 'android',
        'name' => 'TypeError',
        'message' => "Cannot read properties of undefined (reading 'name')",
        'stack' => "TypeError: Cannot read properties of undefined\n    at Profile (https://app.kerugas.ao/assets/Profile-abc.js:1:2345)",
        'url' => 'https://app.kerugas.ao/perfil?token=segredo',
        'app_version' => '1.0.0',
    ], $extra);
}

it('aceita relatórios de visitantes e agrupa o mesmo erro numa só linha', function () {
    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();
    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();

    expect(ClientError::count())->toBe(1);
    $error = ClientError::first();
    expect($error->occurrences)->toBe(2);
    expect($error->url_path)->toBe('/perfil'); // sem query string
    expect($error->source)->toBe('android');
});

it('apaga tokens, emails e telefones antes de guardar', function () {
    $this->postJson('/api/v1/client-errors', clientErrorPayload([
        'message' => 'Falhou para ana@exemplo.ao (+244 923 111 000) com Bearer 12|abcdefghijklmnopqrstuvwxyz123',
    ]))->assertNoContent();

    $message = ClientError::first()->message;
    expect($message)->not->toContain('ana@exemplo.ao')->not->toContain('923')->not->toContain('abcdefghij');
});

it('valida os campos e limita por IP', function () {
    // origem desconhecida e mensagem em falta → 422 ("ios" passou a ser válido, Outubro 2026)
    $this->postJson('/api/v1/client-errors', ['source' => 'desktop', 'message' => 'x'])->assertStatus(422);
    $this->postJson('/api/v1/client-errors', ['source' => 'web'])->assertStatus(422);

    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/v1/client-errors', clientErrorPayload(['message' => "erro {$i} único"]));
    }
    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertStatus(429);
});

it('só o Super Admin geral vê e resolve os erros; resolvido que volta a acontecer reabre', function () {
    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();
    $error = ClientError::first();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN]));
    $this->getJson('/api/v1/admin/client-errors')->assertForbidden();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));
    $this->getJson('/api/v1/admin/client-errors')->assertOk()->assertJsonPath('data.0.id', $error->id);
    $this->patchJson("/api/v1/admin/client-errors/{$error->id}/resolve")->assertOk();
    expect($error->fresh()->resolved_at)->not->toBeNull();

    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();
    expect($error->fresh()->resolved_at)->toBeNull();
});

it('um relatório anónimo conta a ocorrência mas não reabre um erro resolvido', function () {
    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();
    $error = ClientError::first();
    $error->forceFill(['resolved_at' => now()])->save();

    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();

    expect($error->fresh()->resolved_at)->not->toBeNull();
    expect($error->fresh()->occurrences)->toBe(2);
});

it('com o limite cheio, sai o erro antigo que só aconteceu uma vez', function () {
    ClientError::create([
        'fingerprint' => str_repeat('a', 40), 'source' => 'web', 'message' => 'velho', 'occurrences' => 1,
        'first_seen_at' => now()->subDays(10), 'last_seen_at' => now()->subDays(10),
    ]);
    $max = \App\Services\Monitoring\ClientErrorService::MAX_ROWS;
    $rows = [];
    for ($i = 1; $i < $max; $i++) {
        $rows[] = ['fingerprint' => sha1("x{$i}"), 'source' => 'web', 'message' => "m{$i}", 'occurrences' => 2,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        ClientError::insert($chunk);
    }

    $this->postJson('/api/v1/client-errors', clientErrorPayload(['message' => 'erro novo e real']))->assertNoContent();

    expect(ClientError::where('message', 'velho')->exists())->toBeFalse();
    expect(ClientError::where('message', 'erro novo e real')->exists())->toBeTrue();
});

it('apaga os relatórios com mais de 90 dias', function () {
    $this->postJson('/api/v1/client-errors', clientErrorPayload())->assertNoContent();
    ClientError::query()->update(['last_seen_at' => now()->subDays(91)]);

    $this->artisan('kerugas:client-errors-prune')->assertSuccessful();

    expect(ClientError::count())->toBe(0);
});

it('a versão antiga do app consegue sempre enviar erros (nunca 426)', function () {
    config(['kerugas.app.android.min_version' => '9.0.0']);

    $this->postJson('/api/v1/client-errors', clientErrorPayload(), ['X-App-Platform' => 'android', 'X-App-Version' => '1.0.0'])
        ->assertNoContent();
});

it('aceita relatórios do app iPhone e recusa origens desconhecidas', function () {
    $this->postJson('/api/v1/client-errors', clientErrorPayload(['source' => 'ios']))->assertNoContent();
    expect(ClientError::first()->source)->toBe('ios');

    $this->postJson('/api/v1/client-errors', clientErrorPayload(['source' => 'windows-phone']))->assertUnprocessable();
});
