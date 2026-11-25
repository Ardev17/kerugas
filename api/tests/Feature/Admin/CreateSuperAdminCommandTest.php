<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

// Sem isto o utilizador criado no 1.º teste ficava na base de dados e
// contaminava os seguintes (e outros ficheiros: RegisterTest, métricas).
uses(RefreshDatabase::class);

it('cria um super admin com telemóvel normalizado e palavra-passe cifrada', function () {
    $this->artisan('kerugas:create-super-admin', ['--name' => 'Aristides Chiovo', '--phone' => '923456789', '--email' => 'a@kerugas.ao'])
        ->expectsQuestion('Palavra-passe (mín. 10 caracteres)', 'SenhaForte#2026')
        ->expectsQuestion('Repita a palavra-passe', 'SenhaForte#2026')
        ->assertExitCode(0);

    $u = User::where('phone', '+244923456789')->firstOrFail();
    expect($u->role)->toBe(UserRole::SUPER_ADMIN)
        ->and(Hash::check('SenhaForte#2026', $u->password))->toBeTrue();
});

it('recusa palavra-passe curta ou diferente', function () {
    $this->artisan('kerugas:create-super-admin', ['--name' => 'X Y', '--phone' => '923456789'])
        ->expectsQuestion('Palavra-passe (mín. 10 caracteres)', 'curta')
        ->assertExitCode(1);

    $this->artisan('kerugas:create-super-admin', ['--name' => 'X Y', '--phone' => '923456789'])
        ->expectsQuestion('Palavra-passe (mín. 10 caracteres)', 'SenhaForte#2026')
        ->expectsQuestion('Repita a palavra-passe', 'outra-coisa-123')
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});
