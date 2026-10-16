<?php

use App\Enums\CommentStatus;
use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Organização das listas do painel (Outubro 2026): categoria → província →
 * município, com contagens, respeitando o âmbito de província do Admin.
 */
beforeEach(function () {
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);
    $this->matala = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Matala']);
    $this->luanda = Province::factory()->create(['name' => 'Luanda']);
    $this->viana = Municipality::factory()->create(['province_id' => $this->luanda->id, 'name' => 'Viana']);

    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => null, 'municipality_id' => null]);
});

function groupsTestUser(UserRole $role, $province, $municipality = null): User
{
    return User::factory()->create(['role' => $role, 'province_id' => $province?->id, 'municipality_id' => $municipality?->id]);
}

it('utilizadores: abas por papel e grupos por província (admins) ou província → município (condutores)', function () {
    groupsTestUser(UserRole::ADMIN, $this->huila, $this->lubango);
    groupsTestUser(UserRole::ADMIN, $this->luanda, $this->viana);
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->lubango);
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->matala);
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->matala);
    Sanctum::actingAs($this->super);

    $tabs = collect($this->getJson('/api/v1/admin/users/groups')->assertOk()->json('data.tabs'))->pluck('count', 'key');
    expect($tabs['SUPER_ADMIN'])->toBe(1)->and($tabs['ADMIN'])->toBe(2)->and($tabs['DRIVER'])->toBe(3);

    $admins = $this->getJson('/api/v1/admin/users/groups?role=ADMIN')->json('data.groups');
    expect(collect($admins)->pluck('province')->all())->toBe(['Huíla', 'Luanda']);
    expect($admins[0]['municipalities'])->toBe([]); // admins: só província

    $drivers = $this->getJson('/api/v1/admin/users/groups?role=DRIVER')->json('data.groups');
    expect($drivers[0]['count'])->toBe(3);
    expect(collect($drivers[0]['municipalities'])->pluck('count', 'municipality')->all())->toBe(['Lubango' => 1, 'Matala' => 2]);

    // o Super Admin geral sem província aparece em "Sem província"
    $supers = $this->getJson('/api/v1/admin/users/groups?role=SUPER_ADMIN')->json('data.groups');
    expect($supers[0])->toMatchArray(['province_id' => null, 'province' => 'Sem província', 'count' => 1]);
});

it('utilizadores: a lista filtra por província/município (incluindo "sem província")', function () {
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->lubango);
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->matala);
    Sanctum::actingAs($this->super);

    $this->getJson("/api/v1/admin/users?role=DRIVER&province_id={$this->huila->id}&municipality_id={$this->matala->id}")
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users?role=SUPER_ADMIN&province_id=none')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->super->id);
});

it('o Admin provincial só vê os grupos da sua província', function () {
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->lubango);
    groupsTestUser(UserRole::DRIVER, $this->luanda, $this->viana);
    Sanctum::actingAs(groupsTestUser(UserRole::ADMIN, $this->huila, $this->lubango));

    $groups = $this->getJson('/api/v1/admin/users/groups?role=DRIVER')->assertOk()->json('data.groups');
    expect(collect($groups)->pluck('province')->all())->toBe(['Huíla']);

    // e não consegue ver outra província pelo filtro
    $this->getJson("/api/v1/admin/users?role=DRIVER&province_id={$this->luanda->id}")->assertOk()->assertJsonCount(0, 'data');
});

it('postos: abas pelo estado do gás e grupos por província → município', function () {
    Station::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'current_gas_available' => true, 'status_updated_at' => now()]);
    Station::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'current_gas_available' => false, 'status_updated_at' => now()]);
    Station::factory()->create(['province_id' => $this->luanda->id, 'municipality_id' => $this->viana->id, 'status_updated_at' => null]);
    Sanctum::actingAs($this->super);

    $tabs = collect($this->getJson('/api/v1/admin/stations/groups')->assertOk()->json('data.tabs'))->pluck('count', 'key');
    expect($tabs->all())->toBe(['available' => 1, 'unavailable' => 1, 'unknown' => 1]);

    $groups = $this->getJson('/api/v1/admin/stations/groups?gas=available')->json('data.groups');
    expect($groups)->toHaveCount(1);
    expect($groups[0]['municipalities'][0])->toMatchArray(['municipality' => 'Lubango', 'count' => 1]);

    $this->getJson("/api/v1/admin/stations?gas=unknown&province_id={$this->luanda->id}")->assertOk()->assertJsonCount(1, 'data');
});

it('pedidos: contagens por estado e grupos; /orders/groups não é confundido com um pedido', function () {
    GasOrder::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'status' => GasOrderStatus::PENDING]);
    GasOrder::factory()->create(['province_id' => $this->luanda->id, 'municipality_id' => $this->viana->id, 'status' => GasOrderStatus::DELIVERED]);
    Sanctum::actingAs($this->super);

    $res = $this->getJson('/api/v1/admin/orders/groups?status=PENDING')->assertOk();
    expect(collect($res->json('data.tabs'))->pluck('count', 'key')['DELIVERED'])->toBe(1);
    expect(collect($res->json('data.groups'))->pluck('province')->all())->toBe(['Huíla']);

    $this->getJson("/api/v1/admin/orders?status=PENDING&municipality_id={$this->viana->id}")->assertOk()->assertJsonCount(0, 'data');
});

it('comentários: agrupados pela localização do posto', function () {
    $station = Station::factory()->create(['province_id' => $this->huila->id, 'municipality_id' => $this->matala->id]);
    StationComment::factory()->count(2)->create(['station_id' => $station->id, 'status' => CommentStatus::PENDING]);
    StationComment::factory()->create(['status' => CommentStatus::VISIBLE]);
    Sanctum::actingAs($this->super);

    $res = $this->getJson('/api/v1/admin/comments/groups?status=PENDING')->assertOk();
    expect(collect($res->json('data.tabs'))->pluck('count', 'key')->all())->toMatchArray(['PENDING' => 2, 'VISIBLE' => 1]);
    expect($res->json('data.groups.0'))->toMatchArray(['province' => 'Huíla', 'count' => 2]);
    expect($res->json('data.groups.0.municipalities.0'))->toMatchArray(['municipality' => 'Matala', 'count' => 2]);

    $this->getJson("/api/v1/admin/comments?status=PENDING&municipality_id={$this->matala->id}")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/admin/comments?status=PENDING&municipality_id={$this->lubango->id}")->assertOk()->assertJsonCount(0, 'data');
});

it('condutores e denúncias também têm grupos', function () {
    groupsTestUser(UserRole::DRIVER, $this->huila, $this->lubango);
    User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id, 'driver_available' => false]);
    Sanctum::actingAs($this->super);

    $this->getJson('/api/v1/admin/drivers/groups')->assertOk()->assertJsonPath('data.groups.0.province', 'Huíla');
    // a aba "Todos" mantém o total mesmo com o filtro "disponíveis" ativo
    $res = $this->getJson('/api/v1/admin/drivers/groups?available_only=1')->assertOk();
    expect(collect($res->json('data.tabs'))->pluck('count', 'key')->all())->toBe(['all' => 2, 'available' => 1]);
    expect($res->json('data.groups.0.count'))->toBe(1);

    // parâmetros malformados nunca dão erro 500
    $this->getJson('/api/v1/admin/users?province_id[]=1&role[]=x')->assertOk();
    $this->getJson('/api/v1/admin/reports/groups')->assertOk()->assertJsonPath('data.groups', []);
});
