<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Atualização obrigatória do app Android (Outubro 2026). */
beforeEach(function () {
    config([
        'kerugas.app.android.min_version' => '1.2.0',
        'kerugas.app.android.latest_version' => '1.4.0',
        'kerugas.app.android.store_url' => 'https://play.google.com/store/apps/details?id=ao.kerugas.app',
        // Independente do .env de quem corre os testes.
        'kerugas.app.android.published' => false,
        'kerugas.app.android.apk_url' => '',
        'kerugas.app.android.apk_version' => '',
    ]);
});

it('publica as versões e o link da loja sem sessão', function () {
    $this->getJson('/api/v1/app/config')
        ->assertOk()
        ->assertJsonPath('data.android.min_version', '1.2.0')
        ->assertJsonPath('data.android.latest_version', '1.4.0')
        ->assertJsonPath('data.android.store_url', 'https://play.google.com/store/apps/details?id=ao.kerugas.app');
});

it('recusa (426) pedidos de uma versão do app abaixo da mínima, mas nunca a configuração', function () {
    Sanctum::actingAs(User::factory()->create());
    $old = ['X-App-Platform' => 'android', 'X-App-Version' => '1.1.9'];

    $this->getJson('/api/v1/users/me', $old)
        ->assertStatus(426)
        ->assertJsonPath('code', 'APP_UPDATE_REQUIRED')
        ->assertJsonPath('data.store_url', 'https://play.google.com/store/apps/details?id=ao.kerugas.app');

    $this->getJson('/api/v1/app/config', $old)->assertOk();
});

it('deixa passar versões suportadas, o site e cabeçalhos inválidos', function (array $headers) {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/users/me', $headers)->assertOk();
})->with([
    'versão mínima' => [['X-App-Platform' => 'android', 'X-App-Version' => '1.2.0']],
    'versão nova' => [['X-App-Platform' => 'android', 'X-App-Version' => '1.10.0']],
    'site (sem cabeçalhos)' => [[]],
    'versão inválida' => [['X-App-Platform' => 'android', 'X-App-Version' => 'abc']],
]);

it('ignora uma configuração inválida (nunca bloqueia tudo por engano)', function () {
    config(['kerugas.app.android.min_version' => 'lixo', 'kerugas.app.android.store_url' => 'javascript:alert(1)']);

    $this->getJson('/api/v1/app/config')
        ->assertJsonPath('data.android.min_version', '1.0.0')
        ->assertJsonPath('data.android.store_url', 'https://play.google.com/store/apps/details?id=ao.kerugas.app');
});

it('diz ao site se o app já está publicado na loja (desligado por defeito)', function () {
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.android.published', false);

    config(['kerugas.app.android.published' => true]);
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.android.published', true);

    config(['kerugas.app.android.published' => 'false']);
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.android.published', false);
});

it('prepara o botão da App Store: desligado até haver link e APP_IOS_PUBLISHED', function () {
    config(['kerugas.app.ios.published' => false, 'kerugas.app.ios.store_url' => '']);
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.ios.published', false)->assertJsonPath('data.ios.store_url', null);

    // Ligado mas sem link válido: continua desligado (nunca um botão para lado nenhum).
    config(['kerugas.app.ios.published' => true, 'kerugas.app.ios.store_url' => 'javascript:alert(1)']);
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.ios.published', false);

    config(['kerugas.app.ios.store_url' => 'https://apps.apple.com/ao/app/kerugas/id0000000000']);
    $this->getJson('/api/v1/app/config')
        ->assertJsonPath('data.ios.published', true)
        ->assertJsonPath('data.ios.store_url', 'https://apps.apple.com/ao/app/kerugas/id0000000000');
});

// ------------------------------------------------------------------ iPhone

it('publica as versões do iPhone e recusa (426) uma versão iOS abaixo da mínima', function () {
    config([
        'kerugas.app.ios.min_version' => '1.1.0',
        'kerugas.app.ios.latest_version' => '1.3.0',
        'kerugas.app.ios.published' => true,
        'kerugas.app.ios.store_url' => 'https://apps.apple.com/ao/app/kerugas/id0000000000',
    ]);

    $this->getJson('/api/v1/app/config')
        ->assertJsonPath('data.ios.min_version', '1.1.0')
        ->assertJsonPath('data.ios.latest_version', '1.3.0')
        ->assertJsonPath('data.android.min_version', '1.2.0');

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/users/me', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.0.9'])
        ->assertStatus(426)
        ->assertJsonPath('code', 'APP_UPDATE_REQUIRED')
        ->assertJsonPath('data.store_url', 'https://apps.apple.com/ao/app/kerugas/id0000000000');

    $this->getJson('/api/v1/users/me', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.1.0'])->assertOk();
    // A versão mínima de um sistema não bloqueia o outro.
    $this->getJson('/api/v1/users/me', ['X-App-Platform' => 'android', 'X-App-Version' => '1.2.0'])->assertOk();
    $this->getJson('/api/v1/app/config', ['X-App-Platform' => 'ios', 'X-App-Version' => '0.1.0'])->assertOk();
});

it('o iPhone com configuração inválida nunca bloqueia ninguém', function () {
    config(['kerugas.app.ios.min_version' => 'lixo']);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.ios.min_version', '1.0.0');
    $this->getJson('/api/v1/users/me', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.0.0'])->assertOk();
});

/*
 * Versão de teste (APK) antes da Play Store (Outubro 2026).
 */
it('anuncia o APK de teste (só https) e o aviso de atualização aponta para ele enquanto não há loja', function () {
    config([
        'kerugas.app.android.published' => false,
        'kerugas.app.android.apk_url' => 'https://github.com/ardev/kerugas-teste/releases/latest/download/kerugas.apk',
        'kerugas.app.android.apk_version' => '1.0.3-teste',
    ]);

    $this->getJson('/api/v1/app/config')->assertOk()
        ->assertJsonPath('data.android.published', false)
        ->assertJsonPath('data.android.apk_url', 'https://github.com/ardev/kerugas-teste/releases/latest/download/kerugas.apk')
        ->assertJsonPath('data.android.apk_version', '1.0.3-teste')
        ->assertJsonPath('data.android.update_url', 'https://github.com/ardev/kerugas-teste/releases/latest/download/kerugas.apk');
});

it('ignora um APK sem https e, já publicada, o aviso de atualização vai para a Play Store', function () {
    config(['kerugas.app.android.apk_url' => 'http://inseguro.ao/app.apk', 'kerugas.app.android.apk_version' => '<script>']);
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.android.apk_url', null)->assertJsonPath('data.android.apk_version', null);

    config([
        'kerugas.app.android.published' => true,
        'kerugas.app.android.store_url' => 'https://play.google.com/store/apps/details?id=ao.kerugas.app',
        'kerugas.app.android.apk_url' => 'https://github.com/ardev/x/releases/latest/download/kerugas.apk',
    ]);
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.android.update_url', 'https://play.google.com/store/apps/details?id=ao.kerugas.app');
});
