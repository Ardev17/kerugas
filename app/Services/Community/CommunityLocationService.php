<?php

namespace App\Services\Community;

use App\Enums\StationStatus;
use App\Events\CommunityLocationEnded;
use App\Events\CommunityMessagePosted;
use App\Models\CommunityMessage;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Vizinhos — "Partilhar a minha localização" (Outubro 2026).
 *
 * Alguém está num posto com gás e quer que os vizinhos lá cheguem: partilha um
 * ponto (a posição do telemóvel) ou o próprio posto. Regras de segurança:
 *  - só de dia (08h–18h, hora de Angola): fora disso os postos estão fechados;
 *  - dura 30 min, 1 h ou 2 h, à escolha — e acaba sempre às 18h;
 *  - a pessoa pode parar quando quiser; uma partilha nova termina a anterior;
 *  - quando acaba, as coordenadas são APAGADAS (não só escondidas) — fica o aviso;
 *  - a API nunca devolve coordenadas de uma partilha que já acabou, mesmo que a
 *    limpeza automática (a cada 5 minutos) ainda não tenha passado.
 */
class CommunityLocationService
{
    public const KINDS = ['point', 'station'];

    public function __construct(private readonly CommunityService $community)
    {
    }

    // ── Horário ─────────────────────────────────────────────────────────

    /**
     * @return array{open: bool, opens_at: string, closes_at: string, durations: list<int>, closes_at_iso: ?string, opens_at_iso: string}
     */
    public function window(?CarbonImmutable $now = null): array
    {
        [$opens, $closes] = $this->todayBounds($now);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->tz());
        $open = $now->gte($opens) && $now->lt($closes);
        $nextOpen = $now->lt($opens) ? $opens : $opens->addDay();

        return [
            'open' => $open,
            'opens_at' => (string) config('kerugas.community_location.opens_at', '08:00'),
            'closes_at' => (string) config('kerugas.community_location.closes_at', '18:00'),
            'durations' => $this->durations(),
            'closes_at_iso' => $open ? $closes->toIso8601String() : null,
            'opens_at_iso' => ($open ? $opens->addDay() : $nextOpen)->toIso8601String(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function todayBounds(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->tz());
        $at = function (string $hhmm) use ($now): CarbonImmutable {
            [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => 0]);

            return $now->setTime($h, $m);
        };

        return [
            $at((string) config('kerugas.community_location.opens_at', '08:00')),
            $at((string) config('kerugas.community_location.closes_at', '18:00')),
        ];
    }

    private function tz(): string
    {
        return (string) config('kerugas.community_location.timezone', 'Africa/Luanda');
    }

    /** @return list<int> */
    public function durations(): array
    {
        return array_values(array_map('intval', (array) config('kerugas.community_location.durations', [30, 60, 120])));
    }

    // ── Partilhar / parar ───────────────────────────────────────────────

    /**
     * @param  array{kind: string, latitude: float, longitude: float, accuracy?: ?int, station_id?: ?int, minutes: int}  $data
     */
    public function share(User $author, int $municipalityId, array $data): CommunityMessage
    {
        $this->community->assertCanWrite($author, $municipalityId);

        $window = $this->window();
        if (!$window['open']) {
            throw new HttpException(422, "A partilha de localização só funciona entre as {$window['opens_at']} e as {$window['closes_at']}, quando os postos estão abertos.");
        }

        if (!in_array((int) $data['minutes'], $this->durations(), true)) {
            throw ValidationException::withMessages(['minutes' => 'Escolha 30 minutos, 1 hora ou 2 horas.']);
        }

        $accuracy = isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null;
        $maxAccuracy = (int) config('kerugas.community_location.max_accuracy_m', 500);
        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            throw ValidationException::withMessages(['accuracy' => "A localização do telemóvel está imprecisa (±{$accuracy} m). Ligue o GPS, vá para um sítio aberto e tente de novo."]);
        }

        [, $closes] = $this->todayBounds();
        $expires = CarbonImmutable::now()->addMinutes((int) $data['minutes']);
        if ($expires->gt($closes)) {
            $expires = $closes;
        }
        if ($expires->diffInMinutes(CarbonImmutable::now(), true) < 5) {
            throw new HttpException(422, "Faltam poucos minutos para as {$window['closes_at']} — a partilha de localização já fechou por hoje.");
        }

        $station = null;
        if ($data['kind'] === 'station') {
            $station = Station::query()->whereKey((int) ($data['station_id'] ?? 0))->where('status', StationStatus::ACTIVE)->first();
            if (!$station || $station->latitude === null || $station->longitude === null) {
                throw ValidationException::withMessages(['station_id' => 'Esse posto não está disponível.']);
            }
            $radius = (int) config('kerugas.community_location.station_radius_m', 300);
            $distance = self::meters((float) $data['latitude'], (float) $data['longitude'], (float) $station->latitude, (float) $station->longitude);
            if ($distance > $radius) {
                throw ValidationException::withMessages(['station_id' => 'Só pode partilhar um posto quando está nele (a menos de ' . $radius . ' m).']);
            }
        }

        $this->throttle($author);

        // Só uma partilha ativa de cada vez: a nova termina a anterior.
        $this->endActiveFor($author);

        $message = CommunityMessage::create([
            'municipality_id' => $municipalityId,
            'neighborhood_id' => null,       // todos os vizinhos do município veem
            'user_id' => $author->id,
            'body' => '',
            'official' => CommunityAccess::writesAsTeam($author, $municipalityId),
            'location_kind' => $data['kind'],
            // Posto: a posição exata da pessoa NUNCA é guardada — só o posto.
            'location_lat' => $station ? null : round((float) $data['latitude'], 6),
            'location_lng' => $station ? null : round((float) $data['longitude'], 6),
            'location_accuracy' => $station ? null : $accuracy,
            'location_station_id' => $station?->id,
            'location_label' => $station ? mb_substr((string) $station->name, 0, 120) : null,
            'location_expires_at' => $expires,
        ]);

        CommunityMessagePosted::dispatchSafely($message);

        return $message;
    }

    public function stop(User $actor, CommunityMessage $message): CommunityMessage
    {
        if ($message->user_id !== $actor->id) {
            throw new HttpException(403, 'Só quem partilhou a localização a pode parar.');
        }
        if ($message->location_kind === null) {
            throw new HttpException(422, 'Esta mensagem não tem uma localização.');
        }

        $this->end($message);

        return $message->refresh();
    }

    /** Termina já todas as partilhas ativas desta pessoa (nova partilha, conta eliminada…). */
    public function endActiveFor(User $user): void
    {
        CommunityMessage::query()->where('user_id', $user->id)->whereNotNull('location_kind')->whereNull('location_ended_at')
            ->get()->each(fn (CommunityMessage $m) => $this->end($m));
    }

    /**
     * Limpeza agendada (a cada 5 minutos): apaga as coordenadas de todas as
     * partilhas que já acabaram. Devolve quantas limpou.
     */
    public function prune(): int
    {
        $ids = CommunityMessage::query()
            ->whereNotNull('location_kind')
            ->where(fn ($q) => $q->whereNotNull('location_lat')->orWhereNotNull('location_lng')->orWhereNotNull('location_station_id')->orWhereNull('location_ended_at'))
            ->where(fn ($q) => $q->where('location_expires_at', '<=', now())->orWhereNotNull('location_ended_at'))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $all = CommunityMessage::query()->whereIn('id', $ids)->get(['id', 'municipality_id']);
        CommunityMessage::query()->whereIn('id', $ids)->update([
            'location_lat' => null, 'location_lng' => null, 'location_accuracy' => null, 'location_station_id' => null,
        ]);
        CommunityMessage::query()->whereIn('id', $ids)->whereNull('location_ended_at')
            ->update(['location_ended_at' => now()]);

        foreach ($all as $m) {
            CommunityLocationEnded::dispatchSafely((int) $m->municipality_id, (int) $m->id);
        }

        return $all->count();
    }

    /** Ids das partilhas ainda ativas de um município (o ecrã usa isto para apagar as que acabaram). */
    public function activeIds(int $municipalityId): array
    {
        return CommunityMessage::query()->where('municipality_id', $municipalityId)
            ->whereNotNull('location_kind')->whereNull('location_ended_at')->where('location_expires_at', '>', now())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function end(CommunityMessage $message): void
    {
        $message->forceFill([
            'location_lat' => null, 'location_lng' => null, 'location_accuracy' => null, 'location_station_id' => null,
            'location_ended_at' => $message->location_ended_at ?? now(),
        ])->save();

        CommunityLocationEnded::dispatchSafely((int) $message->municipality_id, (int) $message->id);
    }

    private function throttle(User $author): void
    {
        if (!RateLimiter::attempt('community:gap:' . $author->id, 1, fn () => true, 5)) {
            throw ValidationException::withMessages(['kind' => 'Calma, vizinho! Espere uns segundos antes de partilhar de novo.']);
        }
        $key = 'community:location:' . $author->id;
        $max = (int) config('kerugas.community_location.per_hour', 6);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['kind' => 'Já partilhou a localização muitas vezes nesta hora. Tente mais tarde.']);
        }
        RateLimiter::hit($key, 3600);
    }

    // ── Para o JSON da mensagem ─────────────────────────────────────────

    /**
     * O que o ecrã recebe sobre a localização. As coordenadas só seguem
     * enquanto a partilha está ativa E a mensagem está visível.
     *
     * @return array<string, mixed>|null
     */
    public static function present(CommunityMessage $m, User $viewer, string $status): ?array
    {
        if ($m->location_kind === null) {
            return null;
        }

        $active = $m->locationActive() && $status === 'visible';
        $mine = $m->user_id === $viewer->id;
        $data = [
            'kind' => $m->location_kind,
            'label' => $m->location_label,
            'active' => $active,
            'expires_at' => $m->location_expires_at?->toIso8601String(),
            'ended_at' => $m->location_ended_at?->toIso8601String(),
            'can_stop' => $mine && $active,
            'latitude' => null,
            'longitude' => null,
            'accuracy' => null,
            'station' => null,
        ];

        if (!$active) {
            return $data;
        }

        if ($m->location_kind === 'station' && $m->locationStation) {
            $s = $m->locationStation;
            $data['station'] = [
                'id' => $s->id, 'name' => $s->name,
                'gas_available' => $s->current_gas_available,
                'queue_level' => $s->current_queue_level instanceof \BackedEnum ? $s->current_queue_level->value : $s->current_queue_level,
            ];
            $data['latitude'] = (float) $s->latitude;
            $data['longitude'] = (float) $s->longitude;
        } elseif ($m->location_kind === 'point') {
            $data['latitude'] = $m->location_lat;
            $data['longitude'] = $m->location_lng;
            $data['accuracy'] = $m->location_accuracy;
        }

        return $data;
    }

    /** Distância em metros (haversine). */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
