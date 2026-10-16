<?php

namespace App\Providers;

use App\Events\GasAvailabilityChanged;
use App\Events\GasOrderCreated;
use App\Listeners\DispatchStationAlertsListener;
use App\Listeners\NotifyAdminsOfNewOrderListener;
use App\Models\GasOrder;
use App\Policies\GasOrderPolicy;
use App\Services\Sms\KambaSmsProvider;
use App\Services\Sms\MockSmsProvider;
use App\Services\Sms\ProductionSmsProvider;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * ÚNICO ponto central de registo da aplicação (Laravel 11).
 *
 * IMPORTANTE: o Laravel 11 eliminou por omissão os ficheiros
 * AuthServiceProvider, RouteServiceProvider e EventServiceProvider que
 * existiam em versões anteriores. Tudo o que antes vivia espalhado por
 * esses ficheiros agora vive aqui (bindings, policies, listeners, rate
 * limiters) ou em bootstrap/app.php (middleware, rotas). Não crie esses
 * ficheiros antigos — não seriam carregados pelo framework a menos que
 * os registasse manualmente em bootstrap/providers.php, o que só
 * acrescentaria confusão sem necessidade.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Bindings de interface -> implementação. Corre antes do boot() de
     * todos os providers, por isso é aqui (não em boot()) que se regista
     * o binding do SmsProviderInterface.
     */
    public function register(): void
    {
        $this->app->bind(
            SmsProviderInterface::class,
            // BUG CORRIGIDO (Setembro 2026): as credenciais eram lidas
            // aqui com `env()` diretamente — fora de um ficheiro
            // `config/*.php`, o que se parte assim que alguém corre
            // `php artisan config:cache` (o `.env` deixa de ser lido em
            // runtime, e `env()` passa a devolver sempre `null` fora dos
            // ficheiros de config). Ver o comentário completo em
            // `config/kerugas.php` → `sms.api_key`. Ler sempre por
            // `config()`, nunca por `env()`, fora de um ficheiro de config.
            fn () => match (config('kerugas.sms.provider')) {
                'production' => new ProductionSmsProvider(
                    config('kerugas.sms.api_key'),
                    config('kerugas.sms.api_secret'),
                    config('kerugas.sms.webhook_secret'),
                ),
                'kamba' => new KambaSmsProvider(
                    config('kerugas.sms.api_key'),
                    config('kerugas.sms.kamba.base_url'),
                ),
                default => new MockSmsProvider(),
            }
        );
    }

    public function boot(): void
    {
        // Policies — substitui o antigo array $policies do
        // AuthServiceProvider (secção "Registo da Policy" do README,
        // agora corrigida).
        Gate::policy(GasOrder::class, GasOrderPolicy::class);

        // Listeners (Etapa 14) — substitui o antigo EventServiceProvider.
        Event::listen(GasAvailabilityChanged::class, DispatchStationAlertsListener::class);
        Event::listen(GasOrderCreated::class, NotifyAdminsOfNewOrderListener::class);

        // Rate limiters — substitui o antigo RouteServiceProvider::boot().
        RateLimiter::for('orders', function (Request $request) {
            return Limit::perHour(config('kerugas.order_rate_limit.per_phone_per_hour'))
                ->by($request->input('customer_phone', $request->ip()));
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('social', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Central de Operações (secção 78): protege os endpoints de
        // preview/envio de SMS contra abuso — um admin não deve poder
        // disparar centenas de SMS acidentalmente em poucos segundos.
        RateLimiter::for('sms-operations', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // AUDITORIA DE SEGURANÇA (Setembro 2026) — `/webhooks/sms` não
        // tinha NENHUM limite: mesmo sem assinatura válida, cada pedido
        // ainda gasta CPU/DB a calcular o HMAC e a procurar o posto
        // antes de rejeitar, o que tornava a rota um alvo barato de DoS.
        // 30/min por IP é generoso o suficiente para o tráfego real de
        // um provedor de SMS (que nunca envia rajadas desse volume por
        // um único posto), mas corta um ataque de flood.
        RateLimiter::for('sms-webhook', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // AUDITORIA DE SEGURANÇA (Setembro 2026): as rotas públicas de
        // leitura (postos, mapa, território, comunidade) não tinham
        // nenhum limite — qualquer bot conseguia martelar `/map/stations`
        // (que devolve TODOS os postos ativos, sem paginação) à vontade.
        // Nunca acrescentar cache aqui como alternativa: já houve um bug
        // real (ver `StationController::forMap()`) em que uma cache sem
        // invalidação fazia atualizações do Operador/Admin desaparecerem
        // do mapa até expirar — rate limit é a mitigação correta para
        // este endpoint específico, não cache. 60/min por IP é confortável
        // para uso humano normal (o frontend não pede isto tantas vezes).
        RateLimiter::for('public-read', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Avaliação do condutor (Setembro 2026) — rota pública, sem
        // `auth:sanctum`, que aceita "referência + telefone" como prova
        // de posse. Sem limite, um atacante podia tentar adivinhar o
        // telefone certo para uma referência conhecida (brute-force),
        // por isso limita-se por IP E por referência de pedido, para
        // não deixar nem um IP martelar várias referências nem várias
        // origens martelarem a mesma referência.
        RateLimiter::for('ratings', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by('rating:' . $request->route('reference')),
            ];
        });

        // Formulário de Contacto (Setembro 2026) — rota pública sem
        // autenticação nenhuma. 5/hora por IP é generoso para uma
        // pessoa real (ninguém preenche este formulário repetidamente)
        // mas corta um bot a martelar o endpoint para gerar spam de
        // email através do nosso próprio SMTP.
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });
    }
}
