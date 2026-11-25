<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Localização dos condutores (Novembro 2026): só o Super Admin geral e o
 * Provincial a veem; o admin escolhe,nunca há atribuição automática.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->catumbela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Catumbela']);

    $this->global = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->psa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->psaHuila = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->huila->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->benguela->id]);

    $base = ['role' => UserRole::DRIVER, 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'driver_available' => true];
    $this->longe = User::factory()->create($base + ['name' => 'Condutor Longe']);
    $this->perto = User::factory()->create($base + ['name' => 'Condutor Perto']);
    $this->semNet = User::factory()->create($base + ['name' => 'Condutor Sem Net']);
    $this->outroMunicipio = User::factory()->create(array_merge($base, ['municipality_id' => $this->catumbela->id, 'name' => 'Condutor Catumbela']));

    $this->order = GasOrder::factory()->create([
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
        'status' => GasOrderStatus::PENDING, 'assigned_to' => null,
        'latitude' => -12.3600, 'longitude' => 13.5400,
    ]);
});

function sendLocation(User $driver, float $lat, float $lng, array $extra = [])
{
    Sanctum::actingAs($driver);

    return test()->postJson('/api/v1/driver/location', array_merge(['latitude' => $lat, 'longitude' => $lng, 'accuracy' => 12], $extra));
}

it('o condutor envia a localização e fica guardada só a última', function () {
    sendLocation($this->perto, -12.36, 13.54)->assertOk();
    sendLocation($this->perto, -12.361, 13.541)->assertOk();

    $this->perto->refresh();
    expect((float) $this->perto->driver_latitude)->toBe(-12.361)
        ->and($this->perto->driver_location_accuracy)->toBe(12)
        ->and($this->perto->driver_location_at)->not->toBeNull();
});

it('o condutor de posto também partilha a localização', function () {
    $posto = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
    $cp = User::factory()->create(['role' => UserRole::STATION_DRIVER, 'driver_station_id' => $posto->id, 'municipality_id' => $this->lobito->id]);

    sendLocation($cp, -12.36, 13.54)->assertOk();
});

it('valida as coordenadas e recusa quem não é condutor', function () {
    sendLocation($this->perto, 120, 13.54)->assertUnprocessable();

    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/driver/location', ['latitude' => -12.36, 'longitude' => 13.54])->assertForbidden();
});

it('uma posição antiga que chega atrasada não substitui a mais recente', function () {
    sendLocation($this->perto, -12.36, 13.54, ['recorded_at' => now()->getTimestampMs()])->assertOk();
    sendLocation($this->perto, -10.0, 10.0, ['recorded_at' => now()->subMinutes(5)->getTimestampMs()])->assertOk();

    expect((float) $this->perto->refresh()->driver_latitude)->toBe(-12.36);
});

it('ordena os condutores do município do mais perto para o mais longe; sem localização recente ficam no fim', function () {
    sendLocation($this->longe, -12.40, 13.60);
    sendLocation($this->perto, -12.361, 13.541);
    sendLocation($this->outroMunicipio, -12.36, 13.54);
    // Sem internet há mais de 15 minutos → "sem localização".
    $this->semNet->forceFill(['driver_latitude' => -12.36, 'driver_longitude' => 13.54, 'driver_location_at' => now()->subMinutes(40)])->saveQuietly();

    Sanctum::actingAs($this->psa);
    $r = $this->getJson("/api/v1/admin/orders/{$this->order->id}/nearby-drivers")->assertOk();

    $names = collect($r->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Condutor Perto', 'Condutor Longe', 'Condutor Sem Net'])
        ->and($r->json('data.0.distance_km'))->toBeLessThan(1)
        ->and($r->json('data.2.distance_km'))->toBeNull()
        ->and($r->json('data.2.location.fresh'))->toBeFalse()
        ->and($r->json('meta.order_has_location'))->toBeTrue();

    // Ver a lista não atribui nada.
    expect($this->order->refresh()->assigned_to)->toBeNull();
});

it('o Super Admin geral também vê; o admin normal e o provincial de outra província não', function () {
    Sanctum::actingAs($this->global);
    $this->getJson("/api/v1/admin/orders/{$this->order->id}/nearby-drivers")->assertOk();

    Sanctum::actingAs($this->admin);
    $this->getJson("/api/v1/admin/orders/{$this->order->id}/nearby-drivers")->assertForbidden();

    Sanctum::actingAs($this->psaHuila);
    $this->getJson("/api/v1/admin/orders/{$this->order->id}/nearby-drivers")->assertStatus(403);
});

it('a lista de condutores só mostra a localização ao Super Admin geral/Provincial', function () {
    sendLocation($this->perto, -12.361, 13.541);

    Sanctum::actingAs($this->psa);
    $rows = collect($this->getJson('/api/v1/admin/drivers')->assertOk()->json('data'));
    expect($rows->firstWhere('id', $this->perto->id))->toHaveKey('location')
        ->and($rows->firstWhere('id', $this->perto->id)['location']['fresh'])->toBeTrue();

    Sanctum::actingAs($this->admin);
    $rows = collect($this->getJson('/api/v1/admin/drivers')->assertOk()->json('data'));
    expect($rows->firstWhere('id', $this->perto->id))->not->toHaveKey('location');
});

it('a localização nunca sai no perfil do próprio utilizador nem noutros recursos', function () {
    sendLocation($this->perto, -12.361, 13.541);
    Sanctum::actingAs($this->perto);
    $me = $this->getJson('/api/v1/users/me')->json();
    expect(json_encode($me))->not->toContain('driver_latitude');
});
