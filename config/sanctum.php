<?php

/**
 * AUDITORIA DE SEGURANÇA (Setembro 2026): este ficheiro não existia no
 * projeto. Sem ele, `expiration` corre a `null` (o default do pacote) —
 * ou seja, um token de acesso (`AuthController::login/register`) NUNCA
 * expira sozinho. Na prática: um token roubado (telemóvel perdido,
 * sessão esquecida num dispositivo partilhado, etc.) continua válido
 * para sempre, a menos que alguém entre manualmente e o revogue
 * (`logout` só apaga o token atual, não os antigos).
 *
 * Corrigido: expiração por omissão de 90 dias (129600 minutos),
 * configurável via `SANCTUM_TOKEN_EXPIRATION_MINUTES` no `.env`. Isto é
 * uma escolha de produto, não só de segurança — 90 dias é generoso o
 * suficiente para não obrigar o utilizador comum a fazer login com
 * frequência incómoda, mas finito, ao contrário do que estava antes.
 * Para nunca expirar (comportamento antigo), defina essa variável como
 * vazia/null no `.env`.
 *
 * IMPORTANTE: tal como `config/cors.php`, este ficheiro não existia na
 * árvore de trabalho que me foi dada — se o projeto real já tiver um,
 * confirme antes de substituir.
 */

return [

    'stateful' => explode(',', env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1'
    )),

    'guard' => ['web'],

    // null = nunca expira (comportamento anterior a esta auditoria).
    // 129600 minutos = 90 dias.
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 129600),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
