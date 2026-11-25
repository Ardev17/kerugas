<?php

use App\Console\Commands\GenerateVapidKeysCommand;

it('gera chaves VAPID no formato que os navegadores esperam (P-256, base64url)', function () {
    [$public, $private] = GenerateVapidKeysCommand::generate();
    $decode = fn (string $v) => base64_decode(strtr($v, '-_', '+/') . str_repeat('=', (4 - strlen($v) % 4) % 4));

    expect($public)->toMatch('/^[A-Za-z0-9_-]{87}$/')
        ->and($private)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(strlen($decode($public)))->toBe(65)
        ->and($decode($public)[0])->toBe("\x04")
        ->and(strlen($decode($private)))->toBe(32);
});

it('cada execução gera um par diferente', function () {
    expect(GenerateVapidKeysCommand::generate()[0])->not->toBe(GenerateVapidKeysCommand::generate()[0]);
});
