<?php

namespace App\Services\Stations;

use App\Enums\StationListingOverride;
use App\Enums\SubscriptionType;
use App\Models\Station;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionSettings;
use Illuminate\Support\Carbon;

/**
 * Presença paga do posto na aplicação (Novembro 2026),A DORMIR.
 *
 * Terceira via de receita B2B, para uma fase bem mais à frente: quando a
 * KeruGás já tiver postos próprios e aderência que chegue, o posto parceiro
 * passa a pagar uma taxa semanal para continuar listado.
 *
 * Enquanto a definição `listing_enforcement` estiver desligada,e nasce
 * desligada,tudo aqui devolve "aparece", e NÃO SE FAZ UMA ÚNICA CONSULTA
 * À BASE DE DADOS. É isso que permite deixar o mecanismo montado sem custo
 * nenhum no caminho quente, que são as listas de postos.
 *
 * Os postos da própria KeruGás (platform_owned) nunca saem do mapa. São eles
 * que mantêm a aplicação útil ao cliente se um parceiro deixar de pagar,sem
 * isso, cobrar pela presença esvazia o produto e acaba por matar a razão pela
 * qual os postos quereriam lá estar.
 */
class StationListingService
{
    public function __construct(private readonly SubscriptionSettings $settings)
    {
    }

    public function enforced(): bool
    {
        return $this->settings->listingEnforced();
    }

    /**
     * A partir de quando é que uma subscrição de presença deixa mesmo de
     * valer. Não é no instante em que expira: há dias de tolerância, para o
     * posto ter tempo de renovar sem desaparecer do mapa de um dia para o
     * outro. Quatro dias, por omissão.
     */
    private function limiteDaTolerancia(): Carbon
    {
        return now()->subDays($this->settings->listingGraceDays());
    }

    /**
     * Ids dos postos que não aparecem. Vazio enquanto o mecanismo dorme.
     *
     * @return array<int, int>
     */
    public function hiddenStationIds(): array
    {
        if (!$this->enforced()) {
            return [];
        }

        $limite = $this->limiteDaTolerancia();

        return Station::query()
            ->where('platform_owned', false)
            ->where(function ($q) use ($limite) {
                $q->where('listing_override', StationListingOverride::BLOCK->value)
                    ->orWhere(function ($w) use ($limite) {
                        $w->where('listing_override', StationListingOverride::INHERIT->value)
                            ->whereDoesntHave('subscriptions', fn ($s) => $s
                                ->where('type', SubscriptionType::STATION_LISTING)
                                ->where('status', Subscription::STATUS_ACTIVE)
                                ->where('starts_at', '<=', now())
                                ->where('expires_at', '>', $limite));
                    });
            })
            ->pluck('id')
            ->all();
    }

    public function isListed(Station $station): bool
    {
        if (!$this->enforced()) {
            return true;
        }

        return $station->isListed(true, $this->temPresencaValida($station));
    }

    /** Subscrição a cobrir hoje, ou expirada mas ainda dentro da tolerância. */
    public function temPresencaValida(Station $station): bool
    {
        return $station->subscriptions()
            ->where('type', SubscriptionType::STATION_LISTING)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', $this->limiteDaTolerancia())
            ->exists();
    }

    /**
     * O posto está a viver da tolerância? Devolve o dia em que sai do mapa,
     * ou null se não estiver nessa situação. Serve para o painel mostrar a
     * contagem e para o aviso diário ao operador.
     */
    public function toleranciaAcaba(Station $station): ?Carbon
    {
        if (!$this->enforced() || $station->platform_owned) {
            return null;
        }

        $expiraEm = $station->subscriptions()
            ->where('type', SubscriptionType::STATION_LISTING)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->max('expires_at');

        if (!$expiraEm) {
            return null;
        }

        $expiraEm = Carbon::parse($expiraEm);

        // Ainda dentro do período pago: não é tolerância.
        if ($expiraEm->isFuture()) {
            return null;
        }

        $sai = $expiraEm->copy()->addDays($this->settings->listingGraceDays());

        return $sai->isFuture() ? $sai : null;
    }
}
