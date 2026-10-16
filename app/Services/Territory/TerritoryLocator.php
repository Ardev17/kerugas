<?php

namespace App\Services\Territory;

use App\Enums\StationStatus;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deduz Província e Município a partir de coordenadas (Setembro 2026) —
 * usado no "obter a minha localização" do registo e do pedido de gás.
 *
 * 1. Geocodificação inversa (OpenStreetMap/Nominatim, feita pelo servidor:
 *    o Nominatim exige um User-Agent identificável, que o browser não
 *    consegue definir) e correspondência dos nomes com os territórios da
 *    nossa base — sem acentos nem prefixos ("Província da", "Município de").
 * 2. Se o geocodificador falhar ou não reconhecer o município: o município
 *    do posto ativo mais próximo (até ~30 km). Pior caso: devolve nulls e o
 *    utilizador escolhe à mão, como sempre.
 *
 * O utilizador pode sempre alterar o resultado nos selects.
 */
class TerritoryLocator
{
    /** Chaves do endereço do Nominatim onde pode estar o município, da mais à menos provável. */
    private const MUNICIPALITY_KEYS = ['county', 'municipality', 'city', 'town', 'city_district', 'district', 'suburb', 'village'];

    /** ~0,3° ≈ 33 km. */
    private const NEAREST_STATION_BOX = 0.3;

    /**
     * @return array{province_id: int|null, municipality_id: int|null, source: string|null}
     */
    public function locate(float $latitude, float $longitude): array
    {
        $address = $this->reverseGeocode($latitude, $longitude);

        $province = $address ? $this->matchProvince($address) : null;
        $municipality = $address ? $this->matchMunicipality($address, $province) : null;
        $source = ($province || $municipality) ? 'geocoder' : null;

        if (!$municipality) {
            $station = $this->nearestStation($latitude, $longitude, $province?->id);
            if ($station) {
                $municipality = $station->municipality;
                $province = $province ?? $municipality?->province;
                $source = $source ?? 'station';
            }
        }

        return [
            'province_id' => $municipality?->province_id ?? $province?->id,
            'municipality_id' => $municipality?->id,
            'source' => $source,
        ];
    }

    /** Minúsculas, sem acentos, prefixos administrativos nem variações de ortografia. */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        // Chave fonética: a ortografia dos topónimos angolanos varia entre
        // fontes (Kwanza/Cuanza, Kuvango/Cuvango, Quilamba/Kilamba, Tômbwa/
        // Tombua). Aplica-se aos dois lados da comparação, por isso basta
        // ser consistente.
        $name = strtr($name, ['qu' => 'c', 'k' => 'c', 'w' => 'u']);
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? $name;
        $name = preg_replace('/\b(provincia|province|municipio|municipality|county|comuna|distrito|district|city|cidade)\b/', ' ', $name) ?? $name;
        $name = preg_replace('/^\s*(de|da|do|das|dos|of)\s+/', '', trim($name)) ?? $name;
        $name = preg_replace('/\b(de|da|do|das|dos|of)\b/', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function matchProvince(array $address): ?Province
    {
        $candidates = array_filter([$address['state'] ?? null, $address['province'] ?? null, $address['region'] ?? null]);

        foreach ($candidates as $candidate) {
            $wanted = self::normalize((string) $candidate);
            if ($wanted === '') {
                continue;
            }
            $match = Province::all()->first(fn (Province $p) => self::normalize($p->name) === $wanted);
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function matchMunicipality(array $address, ?Province $province): ?Municipality
    {
        $pool = Municipality::query()
            ->when($province, fn ($q) => $q->where('province_id', $province->id))
            ->get();

        foreach (self::MUNICIPALITY_KEYS as $key) {
            if (empty($address[$key])) {
                continue;
            }
            $wanted = self::normalize((string) $address[$key]);
            if ($wanted === '') {
                continue;
            }
            $matches = $pool->filter(fn (Municipality $m) => self::normalize($m->name) === $wanted);
            // Sem província conhecida, só aceita se o nome for inequívoco
            // (há municípios com o mesmo nome em províncias diferentes).
            if ($matches->count() === 1 || ($province && $matches->isNotEmpty())) {
                return $matches->first();
            }
        }

        return null;
    }

    private function nearestStation(float $latitude, float $longitude, ?int $provinceId): ?Station
    {
        $box = self::NEAREST_STATION_BOX;

        return Station::query()
            ->with('municipality.province')
            ->where('status', StationStatus::ACTIVE)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude', [$latitude - $box, $latitude + $box])
            ->whereBetween('longitude', [$longitude - $box, $longitude + $box])
            ->get()
            ->filter(fn (Station $s) => !$provinceId || $s->municipality?->province_id === $provinceId)
            ->sortBy(fn (Station $s) => ($s->latitude - $latitude) ** 2 + (($s->longitude - $longitude) * cos(deg2rad($latitude))) ** 2)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reverseGeocode(float $latitude, float $longitude): ?array
    {
        $cacheKey = sprintf('territory:geo:%.3f:%.3f', $latitude, $longitude);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(4)
                ->withHeaders(['User-Agent' => (string) config('kerugas.geocoding.user_agent')])
                ->acceptJson()
                ->get((string) config('kerugas.geocoding.url'), [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 12,
                    'addressdetails' => 1,
                    'accept-language' => 'pt',
                ]);

            $address = $response->successful() ? $response->json('address') : null;
        } catch (Throwable $e) {
            Log::channel('single')->warning('[TerritoryLocator] Geocodificação inversa falhou', ['error' => $e->getMessage()]);

            return null;
        }

        if (!is_array($address)) {
            return null;
        }

        Cache::put($cacheKey, $address, now()->addDays(30));

        return $address;
    }
}
