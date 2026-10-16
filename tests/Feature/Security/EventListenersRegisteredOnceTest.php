<?php

use App\Events\GasAvailabilityChanged;
use App\Events\GasOrderCreated;
use App\Listeners\DispatchStationAlertsListener;
use App\Listeners\NotifyAdminsOfNewOrderListener;
use App\Listeners\StationCampaignListener;
use Illuminate\Support\Facades\Event;

/*
 * Regressão (Setembro 2026): com o event discovery do Laravel 11 ligado e
 * o registo manual no AppServiceProvider, cada listener corria duas vezes
 * — SMS de alerta enviados (e pagos) em duplicado.
 */
function registeredListenerNames(string $event): array
{
    return collect(Event::getRawListeners()[$event] ?? [])
        ->map(fn ($listener) => is_string($listener) ? explode('@', $listener)[0] : 'closure')
        ->all();
}

it('cada listener fica registado uma única vez', function () {
    expect(registeredListenerNames(GasAvailabilityChanged::class))
        ->toEqualCanonicalizing([DispatchStationAlertsListener::class, StationCampaignListener::class])
        ->and(registeredListenerNames(GasOrderCreated::class))
        ->toBe([NotifyAdminsOfNewOrderListener::class]);
});
