<?php

namespace App\Services\Drivers;

use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Localização dos condutores (Novembro 2026).
 *
 * - O condutor envia a posição; guarda-se só a última.
 * - Uma posição com mais de FRESH_MINUTES deixa de contar: o condutor
 *   aparece "sem localização" (sem internet, telemóvel desligado ou app
 *   fechada à força).
 * - Nunca há atribuição automática: o admin vê a lista ordenada pela
 *   distância ao cliente e escolhe.
 */
class DriverLocationService
{
    public const FRESH_MINUTES = 15;

    /** @param array{latitude: float, longitude: float, accuracy?: ?float, recorded_at?: ?int} $data */
    public function record(User $driver, array $data): bool
    {
        $at = now();
        if (!empty($data['recorded_at'])) {
            // Hora do telemóvel (ms). Nunca no futuro, nem mais antiga do que 1 hora.
            //
            // O fuso TEM de ser o da aplicação. O Carbon 3 devolve estas
            // instâncias em UTC, e o Eloquent grava a hora do próprio objeto
            // mas lê a coluna de volta no fuso da aplicação. Com
            // APP_TIMEZONE=Africa/Luanda isso tirava uma hora à posição: o
            // condutor aparecia logo "sem localização" (isFresh falhava
            // sempre) e uma posição velha passava à frente da mais recente.
            $device = Carbon::createFromTimestampMs((int) $data['recorded_at'], config('app.timezone'));
            if ($device->lte($at) && $device->gt($at->copy()->subHour())) {
                $at = $device;
            }
        }

        // Posições que chegam fora de ordem (rede lenta) não substituem uma mais recente.
        if ($driver->driver_location_at && $driver->driver_location_at->gt($at)) {
            return false;
        }

        $driver->forceFill([
            'driver_latitude' => round((float) $data['latitude'], 7),
            'driver_longitude' => round((float) $data['longitude'], 7),
            'driver_location_accuracy' => isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
            'driver_location_at' => $at,
        ])->saveQuietly();

        return true;
    }

    public function isFresh(User $driver): bool
    {
        return $driver->driver_location_at !== null
            && $driver->driver_latitude !== null
            && $driver->driver_location_at->gt(now()->subMinutes(self::FRESH_MINUTES));
    }

    /** @return array<string, mixed>|null */
    public function present(User $driver): ?array
    {
        if ($driver->driver_location_at === null || $driver->driver_latitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $driver->driver_latitude,
            'longitude' => (float) $driver->driver_longitude,
            'accuracy_m' => $driver->driver_location_accuracy,
            'updated_at' => $driver->driver_location_at->toIso8601String(),
            'fresh' => $this->isFresh($driver),
        ];
    }

    /**
     * Condutores KeruGás do município do pedido, do mais perto para o mais
     * longe. Sem localização recente ficam no fim.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function nearbyForOrder(GasOrder $order): Collection
    {
        $hasTarget = $order->latitude !== null && $order->longitude !== null;

        return User::query()
            ->where('role', UserRole::DRIVER)
            ->where('status', 'ACTIVE')
            ->where('municipality_id', $order->municipality_id)
            ->get()
            ->map(function (User $d) use ($order, $hasTarget) {
                $fresh = $this->isFresh($d);
                $distance = $fresh && $hasTarget
                    ? $this->distanceKm((float) $d->driver_latitude, (float) $d->driver_longitude, (float) $order->latitude, (float) $order->longitude)
                    : null;

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'phone' => $d->phone,
                    'available' => (bool) $d->driver_available,
                    'location' => $this->present($d),
                    'distance_km' => $distance !== null ? round($distance, 2) : null,
                ];
            })
            ->sortBy(fn ($row) => [
                $row['location']['fresh'] ?? false ? 0 : 1,
                $row['distance_km'] ?? PHP_FLOAT_MAX,
                $row['available'] ? 0 : 1,
            ])
            ->values();
    }

    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
