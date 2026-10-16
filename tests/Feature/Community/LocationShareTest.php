<?php

use App\Models\CommunityMessage;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Vizinhos — "Partilhar a minha localização" (Outubro 2026).
 * Só de dia (08h–18h, Angola), 30/60/120 min, para quando a pessoa quiser, e as
 * coordenadas são apagadas quando a partilha acaba.
 */
const LS_HOME = [-12.5903, 13.4187];        // Benfica, Benguela

beforeEach(function () {
    CommunityAccess::flush();
    $this->province = Province::factory()->create();
    $this->benguela = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->ana = lsUser($this->benguela);
    $this->rui = lsUser($this->benguela);
    lsAt('10:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    \Illuminate\Support\Carbon::setTestNow();
});

function lsUser(Municipality $m): User
{
    return User::factory()->create([
        'province_id' => $m->province_id, 'municipality_id' => $m->id, 'community_accepted_at' => now(),
    ]);
}

/** Muda o relógio para uma hora de Angola (hoje). */
function lsAt(string $hhmm): void
{
    $t = CarbonImmutable::parse("2026-10-08 {$hhmm}:00", 'Africa/Luanda');
    test()->travelTo($t);
    CarbonImmutable::setTestNow($t);
}

function lsShare(User $user, int $mid, array $extra = [])
{
    Cache::flush(); // intervalo de 5 s e limite por hora
    Sanctum::actingAs($user);

    return test()->postJson('/api/v1/community/location?municipality_id=' . $mid, array_merge([
        'kind' => 'point', 'latitude' => LS_HOME[0], 'longitude' => LS_HOME[1], 'accuracy' => 12, 'minutes' => 60,
    ], $extra));
}

function lsSeen(User $viewer, int $messageId): array
{
    Sanctum::actingAs($viewer);
    $data = test()->getJson('/api/v1/community/messages')->assertOk()->json('data');

    return collect($data)->firstWhere('id', $messageId);
}

it('partilha um ponto sem texto e todos os vizinhos do município veem o mapa', function () {
    $res = lsShare($this->ana, $this->benguela->id)->assertCreated()
        ->assertJsonPath('data.body', '')
        ->assertJsonPath('data.neighborhood', null)
        ->assertJsonPath('data.location.kind', 'point')
        ->assertJsonPath('data.location.active', true)
        ->assertJsonPath('data.location.can_stop', true)
        ->assertJsonPath('data.can_edit', false);

    expect(CarbonImmutable::parse($res->json('data.location.expires_at'))->equalTo(CarbonImmutable::parse('2026-10-08 11:00', 'Africa/Luanda')))->toBeTrue();

    $seen = lsSeen($this->rui, $res->json('data.id'));
    expect($seen['location']['latitude'])->toEqualWithDelta(LS_HOME[0], 0.00001)
        ->and($seen['location']['longitude'])->toEqualWithDelta(LS_HOME[1], 0.00001)
        ->and($seen['location']['can_stop'])->toBeFalse();
});

it('está desligada das 18h às 8h (postos fechados)', function (string $hora) {
    lsAt($hora);
    lsShare($this->ana, $this->benguela->id)->assertStatus(422)->assertJsonFragment(['message' => 'A partilha de localização só funciona entre as 08:00 e as 18:00, quando os postos estão abertos.']);

    Sanctum::actingAs($this->ana);
    $this->getJson('/api/v1/community/overview')->assertJsonPath('data.location_share.open', false);
})->with(['18:00', '21:30', '03:00', '07:59']);

it('abre às 8h e diz ao ecrã que está aberta e as durações', function () {
    lsAt('08:00');
    Sanctum::actingAs($this->ana);
    $this->getJson('/api/v1/community/overview')
        ->assertJsonPath('data.location_share.open', true)
        ->assertJsonPath('data.location_share.durations', [30, 60, 120]);

    lsShare($this->ana, $this->benguela->id)->assertCreated();
});

it('acaba sempre às 18h, mesmo que a pessoa escolha mais tempo', function () {
    lsAt('17:00');
    $res = lsShare($this->ana, $this->benguela->id, ['minutes' => 120])->assertCreated();
    expect(CarbonImmutable::parse($res->json('data.location.expires_at'))->equalTo(CarbonImmutable::parse('2026-10-08 18:00', 'Africa/Luanda')))->toBeTrue();

    lsAt('17:57');
    lsShare($this->rui, $this->benguela->id, ['minutes' => 30])->assertStatus(422);
});

it('recusa durações inventadas, coordenadas fora de Angola e GPS impreciso', function () {
    lsShare($this->ana, $this->benguela->id, ['minutes' => 45])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    lsShare($this->ana, $this->benguela->id, ['latitude' => 38.72, 'longitude' => -9.14])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    lsShare($this->ana, $this->benguela->id, ['accuracy' => 900])->assertUnprocessable()->assertJsonValidationErrors('accuracy');
    expect(CommunityMessage::count())->toBe(0);
});

it('só escreve no próprio município (e quem não aceitou as regras não partilha)', function () {
    lsShare($this->ana, $this->lobito->id)->assertForbidden();

    $novo = User::factory()->create(['province_id' => $this->province->id, 'municipality_id' => $this->benguela->id, 'community_accepted_at' => null]);
    lsShare($novo, $this->benguela->id)->assertForbidden();
});

it('partilhar o posto: a posição da pessoa nunca é guardada, só o posto', function () {
    $posto = Station::factory()->create([
        'name' => 'Posto Benfica', 'municipality_id' => $this->benguela->id, 'province_id' => $this->province->id,
        'latitude' => -12.5910, 'longitude' => 13.4190, 'status' => 'ACTIVE',
    ]);

    $res = lsShare($this->ana, $this->benguela->id, ['kind' => 'station', 'station_id' => $posto->id])->assertCreated()
        ->assertJsonPath('data.location.kind', 'station')
        ->assertJsonPath('data.location.label', 'Posto Benfica')
        ->assertJsonPath('data.location.station.id', $posto->id);

    expect($res->json('data.location.latitude'))->toEqualWithDelta(-12.5910, 0.00001);

    $row = CommunityMessage::find($res->json('data.id'));
    expect($row->location_lat)->toBeNull()->and($row->location_lng)->toBeNull()->and($row->location_accuracy)->toBeNull();
});

it('não deixa "estar" num posto longe de onde a pessoa está', function () {
    $longe = Station::factory()->create(['municipality_id' => $this->benguela->id, 'province_id' => $this->province->id, 'latitude' => -12.60, 'longitude' => 13.44, 'status' => 'ACTIVE']);
    $fechado = Station::factory()->create(['municipality_id' => $this->benguela->id, 'province_id' => $this->province->id, 'latitude' => -12.5904, 'longitude' => 13.4188, 'status' => 'SUSPENDED']);

    lsShare($this->ana, $this->benguela->id, ['kind' => 'station', 'station_id' => $longe->id])->assertUnprocessable()->assertJsonValidationErrors('station_id');
    lsShare($this->ana, $this->benguela->id, ['kind' => 'station', 'station_id' => $fechado->id])->assertUnprocessable()->assertJsonValidationErrors('station_id');
    lsShare($this->ana, $this->benguela->id, ['kind' => 'station'])->assertUnprocessable()->assertJsonValidationErrors('station_id');
});

it('só quem partilhou pode parar — e parar apaga as coordenadas na hora', function () {
    $id = lsShare($this->ana, $this->benguela->id)->json('data.id');

    Sanctum::actingAs($this->rui);
    $this->postJson("/api/v1/community/messages/{$id}/location/stop")->assertForbidden();

    Sanctum::actingAs($this->ana);
    $this->postJson("/api/v1/community/messages/{$id}/location/stop")->assertOk()
        ->assertJsonPath('data.location.active', false)
        ->assertJsonPath('data.location.latitude', null);

    $row = CommunityMessage::find($id);
    expect($row->location_lat)->toBeNull()->and($row->location_lng)->toBeNull()->and($row->location_ended_at)->not->toBeNull()
        ->and($row->location_kind)->toBe('point'); // a mensagem fica, sem coordenadas

    expect(lsSeen($this->rui, $id)['location'])->toMatchArray(['active' => false, 'latitude' => null, 'longitude' => null]);
});

it('depois do tempo escolhido ninguém vê as coordenadas, e a limpeza apaga-as da base de dados', function () {
    $id = lsShare($this->ana, $this->benguela->id, ['minutes' => 30])->json('data.id');

    lsAt('10:31');
    // Ainda não passou a limpeza, mas a API já não as mostra.
    expect(lsSeen($this->rui, $id)['location'])->toMatchArray(['active' => false, 'latitude' => null, 'longitude' => null]);
    expect(lsSeen($this->ana, $id)['location']['can_stop'])->toBeFalse();
    expect(CommunityMessage::find($id)->location_lat)->not->toBeNull();

    $this->artisan('kerugas:community-locations-prune')->assertSuccessful();

    $row = CommunityMessage::find($id);
    expect($row->location_lat)->toBeNull()->and($row->location_lng)->toBeNull()->and($row->location_ended_at)->not->toBeNull()
        ->and($row->exists)->toBeTrue();
});

it('a limpeza não toca nas partilhas que ainda estão a decorrer', function () {
    $ativa = lsShare($this->ana, $this->benguela->id, ['minutes' => 120])->json('data.id');
    $curta = lsShare($this->rui, $this->benguela->id, ['minutes' => 30])->json('data.id');

    lsAt('10:45');
    $this->artisan('kerugas:community-locations-prune')->assertSuccessful();

    expect(CommunityMessage::find($ativa)->location_lat)->not->toBeNull()
        ->and(CommunityMessage::find($curta)->location_lat)->toBeNull();

    Sanctum::actingAs($this->rui);
    $this->getJson('/api/v1/community/locations/active')->assertOk()->assertJsonPath('data', [$ativa]);
});

it('uma partilha nova termina a anterior da mesma pessoa', function () {
    $primeira = lsShare($this->ana, $this->benguela->id)->json('data.id');
    $segunda = lsShare($this->ana, $this->benguela->id, ['latitude' => -12.5800])->json('data.id');

    expect(CommunityMessage::find($primeira)->location_lat)->toBeNull()
        ->and(CommunityMessage::find($primeira)->location_ended_at)->not->toBeNull()
        ->and(CommunityMessage::find($segunda)->locationActive())->toBeTrue();
});

it('uma localização não se edita, e apagar a mensagem apaga as coordenadas', function () {
    $id = lsShare($this->ana, $this->benguela->id)->json('data.id');

    Sanctum::actingAs($this->ana);
    $this->putJson("/api/v1/community/messages/{$id}", ['body' => 'Afinal estou noutro sítio'])->assertStatus(422);
    $this->deleteJson("/api/v1/community/messages/{$id}")->assertOk();

    $row = CommunityMessage::find($id);
    expect($row->location_lat)->toBeNull()->and($row->location_ended_at)->not->toBeNull();
});

it('limita as partilhas por hora', function () {
    config(['kerugas.community_location.per_hour' => 2]);
    Cache::flush();
    Sanctum::actingAs($this->ana);
    $send = fn () => $this->postJson('/api/v1/community/location', ['kind' => 'point', 'latitude' => LS_HOME[0], 'longitude' => LS_HOME[1], 'minutes' => 30]);

    $send()->assertCreated();
    Cache::forget('community:gap:' . $this->ana->id);
    $send()->assertCreated();
    Cache::forget('community:gap:' . $this->ana->id);
    $send()->assertUnprocessable();
});
