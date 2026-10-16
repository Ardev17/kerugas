<?php

/**
 * AUDITORIA DE SEGURANÇA (Setembro 2026): este ficheiro não existia no
 * projeto. Sem ele, o middleware `Illuminate\Http\Middleware\HandleCors`
 * (ativo por omissão em toda a aplicação Laravel 11, incluindo `/api/*`)
 * corre com os valores por omissão do framework — `allowed_origins`
 * a `['*']` — ou seja, qualquer site na internet conseguia fazer
 * `fetch()`/`XMLHttpRequest` contra esta API a partir do browser de um
 * visitante. Como a autenticação é por Bearer token (não por cookie), o
 * risco prático era menor do que seria com sessão — um site de terceiros
 * não consegue "herdar" a sessão do utilizador automaticamente — mas
 * mesmo assim: qualquer JS que já tivesse um token guardado (ex: um XSS
 * noutra parte do stack, ou um token colado num sítio errado) podia ser
 * usado a partir de qualquer origem, sem restrição nenhuma.
 *
 * Corrigido: só a origem do frontend oficial (`FRONTEND_URL`, já
 * existente no `.env.example`) pode chamar a API a partir do browser.
 * Ajuste `allowed_origins` se tiver mais do que um domínio de frontend
 * (ex: staging + produção) — nesse caso, liste-os todos em vez de usar
 * `*`, nunca volte ao wildcard.
 *
 * IMPORTANTE: se o seu projeto real já tiver um `config/cors.php`
 * publicado (é o padrão de qualquer `laravel new`), compare com este e
 * mantenha o que já estiver correto — este ficheiro não existia na
 * árvore de trabalho que me foi dada, por isso não sei se substitui um
 * já existente ou preenche uma lacuna real. Confirme antes de comitar.
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // App Android (Setembro 2026): o app corre num WebView do Capacitor
    // cuja origem é `https://app.kerugas.ao` (definida em
    // apps/web/capacitor.config.ts → server.hostname). Esse domínio não
    // existe na internet: o Capacitor serve os ficheiros do próprio
    // telemóvel sob esse nome. Foi escolhido em vez do `https://localhost`
    // por omissão do Capacitor para não autorizar, por acidente, qualquer
    // página servida em localhost no computador de alguém.
    // CORS_EXTRA_ORIGINS permite juntar outros domínios (ex: staging)
    // sem voltar ao wildcard.
    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'https://app.kerugas.ao',
        // App iPhone (Outubro 2026): o iOS não deixa o WebView usar https://
        // para ficheiros locais, por isso a origem é capacitor://app.kerugas.ao.
        'capacitor://app.kerugas.ao',
        ...array_map('trim', explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // false porque a API é 100% stateless (Bearer token), sem cookies
    // de sessão — não há nada a "credenciar" entre origens aqui.
    'supports_credentials' => false,

];
