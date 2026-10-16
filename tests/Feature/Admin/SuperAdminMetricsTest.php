<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\DriverRating;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\OrderPushSubscription;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->luanda = Province::factory()->create(['name' => 'Luanda']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id]);
    $this->talatona = Municipality::factory()->create(['province_id' => $this->luanda->id]);

    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->driver = User::factory()->create(['role' => UserRole::DRIVER, 'name' => 'Paulo Condutor', 'province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id]);

    $this->order = function (array $attrs = []): GasOrder {
        return GasOrder::factory()->create(array_merge([
            'province_id' => $this->huila->id,
            'municipality_id' => $this->lubango->id,
        ], $attrs));
    };
});

// --------------------------------------------------------------------- acesso

it('só o Super Admin acede ao painel de métricas', function () {
    $this->getJson('/api/v1/admin/metrics')->assertUnauthorized();

    foreach ([UserRole::ADMIN, UserRole::MODERATOR, UserRole::STATION_OPERATOR, UserRole::DRIVER, UserRole::USER] as $role) {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/v1/admin/metrics')->assertForbidden();
    }

    Sanctum::actingAs($this->super);
    $this->getJson('/api/v1/admin/metrics')->assertOk()->assertJsonPath('success', true);
});

it('um Super Admin suspenso não acede', function () {
    $this->super->update(['status' => 'SUSPENDED']);
    Sanctum::actingAs($this->super);

    $this->getJson('/api/v1/admin/metrics')->assertForbidden();
});

// ------------------------------------------------------------------- números

it('sem dados nenhum devolve zeros e nulos (nunca divide por zero)', function () {
    Sanctum::actingAs($this->super);

    $data = $this->getJson('/api/v1/admin/metrics')->assertOk()->json('data');

    expect($data['orders']['total'])->toBe(0)
        ->and($data['orders']['completion_rate'])->toBeNull()
        ->and($data['orders']['average_ticket'])->toBeNull()
        ->and($data['orders']['average_minutes_to_deliver'])->toBeNull()
        ->and($data['ratings']['average'])->toBeNull()
        ->and($data['notifications']['read_rate'])->toBeNull()
        ->and($data['orders_by_day'])->toHaveCount(30);
});

it('calcula os KPIs de pedidos do período com os valores certos', function () {
    $now = now();
    $order = $this->order;

    // 2 entregues (2000 + 3000 Kz), 1 cancelado, 1 pendente, 1 em curso.
    $order(['status' => GasOrderStatus::DELIVERED, 'delivery_price' => 2000, 'created_at' => $now->copy()->subHours(10), 'contacted_at' => $now->copy()->subHours(10)->addMinutes(10), 'completed_at' => $now->copy()->subHours(10)->addMinutes(60)]);
    $order(['status' => GasOrderStatus::DELIVERED, 'delivery_price' => 3000, 'created_at' => $now->copy()->subHours(5), 'contacted_at' => $now->copy()->subHours(5)->addMinutes(20), 'completed_at' => $now->copy()->subHours(5)->addMinutes(120)]);
    $order(['status' => GasOrderStatus::CANCELLED, 'created_at' => $now->copy()->subHours(3)]);
    $order(['status' => GasOrderStatus::PENDING, 'created_at' => $now->copy()->subHours(2)]);
    $order(['status' => GasOrderStatus::ASSIGNED, 'created_at' => $now->copy()->subHour()]);

    Sanctum::actingAs($this->super);
    $kpis = $this->getJson('/api/v1/admin/metrics?days=7')->assertOk()->json('data.orders');

    expect($kpis['total'])->toBe(5)
        ->and($kpis['delivered'])->toBe(2)
        ->and($kpis['cancelled'])->toBe(1)
        ->and($kpis['in_progress'])->toBe(2)
        ->and($kpis['completion_rate'])->toEqual(40)
        ->and($kpis['cancellation_rate'])->toEqual(20)
        ->and($kpis['revenue'])->toEqual(5000)
        ->and($kpis['average_ticket'])->toEqual(2500)
        ->and($kpis['average_minutes_to_contact'])->toEqual(15)    // (10 + 20) / 2
        ->and($kpis['average_minutes_to_deliver'])->toEqual(90);   // (60 + 120) / 2
});

it('pedidos fora do período não entram, mas contam para a tendência do período anterior', function () {
    $order = $this->order;
    $order(['status' => GasOrderStatus::DELIVERED, 'delivery_price' => 1000, 'created_at' => now()->subHours(2), 'completed_at' => now()->subHour()]);
    // Período anterior (7–13 dias atrás) — 2 pedidos, 1 entregue de 4000.
    $order(['status' => GasOrderStatus::DELIVERED, 'delivery_price' => 4000, 'created_at' => now()->subDays(9), 'completed_at' => now()->subDays(9)]);
    $order(['status' => GasOrderStatus::PENDING, 'created_at' => now()->subDays(10)]);
    // Muito antigo: fora de ambos.
    $order(['status' => GasOrderStatus::DELIVERED, 'delivery_price' => 9999, 'created_at' => now()->subDays(60), 'completed_at' => now()->subDays(60)]);

    Sanctum::actingAs($this->super);
    $data = $this->getJson('/api/v1/admin/metrics?days=7')->assertOk()->json('data');

    expect($data['orders']['total'])->toBe(1)
        ->and($data['orders']['revenue'])->toEqual(1000)
        ->and($data['previous_orders'])->toEqual(['total' => 2, 'delivered' => 1, 'revenue' => 4000, 'commission' => 0]);
});

it('um período inválido volta a 30 dias em vez de dar erro', function () {
    Sanctum::actingAs($this->super);

    $this->getJson('/api/v1/admin/metrics?days=9999')->assertOk()->assertJsonPath('data.period.days', 30);
    $this->getJson('/api/v1/admin/metrics?days=abc')->assertOk()->assertJsonPath('data.period.days', 30);
    $this->getJson('/api/v1/admin/metrics?days=90')->assertOk()->assertJsonPath('data.period.days', 90);
});

it('pedidos por dia, por estado e por província batem certo e incluem os dias vazios', function () {
    $order = $this->order;
    $order(['status' => GasOrderStatus::DELIVERED, 'created_at' => now(), 'completed_at' => now()]);
    $order(['status' => GasOrderStatus::CANCELLED, 'created_at' => now()]);
    $order(['status' => GasOrderStatus::PENDING, 'created_at' => now(), 'province_id' => $this->luanda->id, 'municipality_id' => $this->talatona->id]);

    Sanctum::actingAs($this->super);
    $data = $this->getJson('/api/v1/admin/metrics?days=7')->assertOk()->json('data');

    expect($data['orders_by_day'])->toHaveCount(7);
    $today = collect($data['orders_by_day'])->last();
    expect($today['date'])->toBe(now()->toDateString())
        ->and($today['total'])->toBe(3)
        ->and($today['delivered'])->toBe(1)
        ->and($today['cancelled'])->toBe(1)
        ->and(collect($data['orders_by_day'])->sum('total'))->toBe(3);

    $byStatus = collect($data['orders_by_status'])->pluck('total', 'status');
    expect($byStatus['DELIVERED'])->toBe(1)->and($byStatus['CANCELLED'])->toBe(1)->and($byStatus['PENDING'])->toBe(1)
        ->and($byStatus['ASSIGNED'])->toBe(0);

    expect($data['orders_by_province'][0])->toBe(['name' => 'Huíla', 'total' => 2, 'delivered' => 1])
        ->and($data['orders_by_province'][1])->toBe(['name' => 'Luanda', 'total' => 1, 'delivered' => 0]);
});

it('o funil mostra onde se perdem pedidos', function () {
    $order = $this->order;
    $order(['status' => GasOrderStatus::PENDING, 'created_at' => now()]);
    $order(['status' => GasOrderStatus::CONTACTING, 'created_at' => now(), 'contacted_at' => now()]);
    $order(['status' => GasOrderStatus::CONFIRMED, 'created_at' => now(), 'contacted_at' => now(), 'confirmed_at' => now()]);
    $order(['status' => GasOrderStatus::DELIVERED, 'created_at' => now(), 'contacted_at' => now(), 'confirmed_at' => now(), 'completed_at' => now()]);

    Sanctum::actingAs($this->super);
    $funnel = collect($this->getJson('/api/v1/admin/metrics')->json('data.funnel'))->pluck('total', 'key');

    expect($funnel->all())->toBe(['created' => 4, 'contacted' => 3, 'confirmed' => 2, 'delivered' => 1]);
});

it('ranking de condutores e avaliações do período', function () {
    $other = User::factory()->create(['role' => UserRole::DRIVER, 'name' => 'Ana Condutora', 'province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id]);
    $order = $this->order;

    $d1 = $order(['status' => GasOrderStatus::DELIVERED, 'assigned_to' => $this->driver->id, 'created_at' => now(), 'completed_at' => now()]);
    $d2 = $order(['status' => GasOrderStatus::DELIVERED, 'assigned_to' => $this->driver->id, 'created_at' => now(), 'completed_at' => now()]);
    $order(['status' => GasOrderStatus::CANCELLED, 'assigned_to' => $this->driver->id, 'created_at' => now()]);
    $d3 = $order(['status' => GasOrderStatus::DELIVERED, 'assigned_to' => $other->id, 'created_at' => now(), 'completed_at' => now()]);

    DriverRating::create(['gas_order_id' => $d1->id, 'driver_id' => $this->driver->id, 'rating' => 5]);
    DriverRating::create(['gas_order_id' => $d2->id, 'driver_id' => $this->driver->id, 'rating' => 4]);
    DriverRating::create(['gas_order_id' => $d3->id, 'driver_id' => $other->id, 'rating' => 1]);

    Sanctum::actingAs($this->super);
    $data = $this->getJson('/api/v1/admin/metrics')->assertOk()->json('data');

    expect($data['drivers']['total'])->toBe(2)
        ->and($data['drivers']['top'][0])->toBe([
            'id' => $this->driver->id, 'name' => 'Paulo Condutor', 'delivered' => 2, 'cancelled' => 1,
            'average_rating' => 4.5, 'ratings_count' => 2,
        ])
        ->and($data['drivers']['top'][1]['name'])->toBe('Ana Condutora')
        ->and($data['ratings']['count'])->toBe(3)
        ->and($data['ratings']['average'])->toBe(3.33)
        ->and(collect($data['ratings']['distribution'])->pluck('total', 'stars')->all())->toBe([1 => 1, 2 => 0, 3 => 0, 4 => 1, 5 => 1]);
});

it('só condutores ativos contam, e os indisponíveis ficam de fora dos disponíveis', function () {
    $this->driver->update(['driver_available' => false]);
    User::factory()->create(['role' => UserRole::DRIVER, 'status' => 'SUSPENDED']);

    Sanctum::actingAs($this->super);
    $drivers = $this->getJson('/api/v1/admin/metrics')->json('data.drivers');

    expect($drivers['total'])->toBe(1)->and($drivers['available'])->toBe(0);
});

it('postos e utilizadores', function () {
    $fresh = Station::factory()->create(['status' => 'ACTIVE', 'current_gas_available' => true, 'status_updated_at' => now()->subHour()]);
    Station::factory()->create(['status' => 'ACTIVE', 'current_gas_available' => false, 'status_updated_at' => now()->subHours(5)]);
    Station::factory()->create(['status' => 'ACTIVE', 'current_gas_available' => false, 'status_updated_at' => null]);
    Station::factory()->create(['status' => 'INACTIVE']);
    User::factory()->count(2)->create(['created_at' => now()->subDays(60)]);

    Sanctum::actingAs($this->super);
    $data = $this->getJson('/api/v1/admin/metrics')->assertOk()->json('data');

    expect($data['stations'])->toBe(['active' => 3, 'with_gas' => 1, 'without_gas' => 2, 'stale' => 2])
        ->and($data['users']['total'])->toBe(4)         // super + condutor + 2
        ->and($data['users']['new_in_period'])->toBe(2) // super + condutor (os outros 2 são antigos)
        ->and(collect($data['users']['by_role'])->pluck('total', 'role')->all())
        ->toBe(['SUPER_ADMIN' => 1, 'DRIVER' => 1, 'USER' => 2]);
    expect($fresh->exists)->toBeTrue();
});

it('saúde das notificações: enviadas, taxa de leitura e quem as pode receber', function () {
    $user = User::factory()->create();
    AppNotification::create(['user_id' => $user->id, 'category' => 'order', 'title' => 'a', 'body' => 'b', 'read_at' => now()]);
    AppNotification::create(['user_id' => $user->id, 'category' => 'order', 'title' => 'a', 'body' => 'b']);
    AppNotification::create(['user_id' => $user->id, 'category' => 'system', 'title' => 'a', 'body' => 'b']);
    AppNotification::create(['user_id' => $user->id, 'category' => 'system', 'title' => 'a', 'body' => 'b', 'read_at' => now()]);

    WebPushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://fcm.googleapis.com/x', 'endpoint_hash' => hash('sha256', 'x'), 'public_key' => 'k', 'auth_token' => 'a']);
    DeviceToken::create(['user_id' => $user->id, 'token' => 'tok', 'platform' => 'android']);
    OrderPushSubscription::create(['gas_order_id' => ($this->order)()->id, 'endpoint' => 'https://fcm.googleapis.com/y', 'endpoint_hash' => hash('sha256', 'y'), 'public_key' => 'k', 'auth_token' => 'a']);

    Sanctum::actingAs($this->super);
    $n = $this->getJson('/api/v1/admin/metrics')->assertOk()->json('data.notifications');

    expect($n['sent'])->toBe(4)
        ->and($n['read_rate'])->toEqual(50)
        ->and($n['orders_sent'])->toBe(2)
        ->and($n['web_push_subscriptions'])->toBe(1)
        ->and($n['order_push_subscriptions'])->toBe(1)
        ->and($n['device_tokens'])->toBe(1);
});
