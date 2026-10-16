<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PreventApiCaching;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
|--------------------------------------------------------------------------
| Ficheiro de arranque da aplicação (Laravel 11)
|--------------------------------------------------------------------------
| Substitui os antigos app/Http/Kernel.php,
| app/Providers/RouteServiceProvider.php e app/Console/Kernel.php.
| Middleware, rotas (incluindo channels.php do broadcasting) e exceções
| registam-se todos aqui.
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // BUG CORRIGIDO (Setembro 2026, varredura antes do app Android):
    // antes, os canais eram registados com `channels:` dentro de
    // withRouting(), o que cria `/broadcasting/auth` com o middleware
    // `web` — autenticação por SESSÃO/cookie. Mas esta API autentica
    // por Bearer token (Sanctum), por isso todos os canais PRIVADOS
    // (`admin.orders`, `municipality.{id}.chat`) respondiam 403 e nunca
    // entregavam nada em tempo real. E o nginx só encaminha `/api/*`
    // para o PHP, por isso `/broadcasting/auth` nem sequer chegava ao
    // Laravel em produção. Agora a rota fica em `/api/broadcasting/auth`
    // com `auth:sanctum` — o mesmo token que o resto da API já usa, e
    // o mesmo que o app Android envia.
    // BUG CORRIGIDO (Setembro 2026, revisão das campanhas): o Laravel 11
    // descobre sozinho os listeners de app/Listeners (event discovery,
    // ligado por omissão em Application::configure()) — e o
    // AppServiceProvider também os regista com Event::listen. Cada evento
    // corria DUAS vezes: o SMS de alerta de gás saía em duplicado (e era
    // pago duas vezes) e os admins recebiam dois avisos por pedido novo.
    // Fica só o registo explícito no AppServiceProvider.
    ->withEvents(discover: false)
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'account.active' => EnsureAccountActive::class,
            'role' => EnsureRole::class,
            'seen' => \App\Http\Middleware\TouchLastSeen::class,
        ]);

        // Nenhuma resposta da API deve ser guardada em cache pelo
        // browser — ver PreventApiCaching para o porquê (bug real
        // que fazia atualizações de estado parecerem "não aplicadas").
        $middleware->api(append: [PreventApiCaching::class, \App\Http\Middleware\EnsureSupportedAppVersion::class]);

        // Cabeçalhos de segurança (Outubro 2026) — global e não só no grupo
        // `api`, para cobrir também os erros fora de uma rota (ex.: 404 de
        // /api/qualquer-coisa). O próprio middleware limita-se a /api e /up.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Restauro pelo painel (Outubro 2026): com o site em manutenção, o
        // estado do restauro continua a responder (o painel acompanha-o).
        $middleware->preventRequestsDuringMaintenance(except: ['api/v1/restores/*/status']);

        // Proxy/túnel à frente do Laravel (Outubro 2026) — ex.: ngrok nos testes
        // do app, ou o nginx em produção. Só com TRUSTED_PROXIES definido no
        // .env é que os cabeçalhos X-Forwarded-* contam: assim o Laravel sabe
        // que o pedido chegou por HTTPS (URLs das fotos em https://) e qual o
        // IP real de quem pede (limites de tentativas por pessoa, não por túnel).
        // Vazio (por omissão) = não confia em ninguém.
        $trusted = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trusted !== '') {
            $middleware->trustProxies(at: $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
