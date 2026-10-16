<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\CampaignDelivery;
use App\Models\DriverRating;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use App\Services\Campaigns\AutomaticCampaignRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->now = Carbon::parse('2026-10-01 10:00', 'Africa/Luanda')->utc();
    $this->travelTo($this->now);
    $this->runner = app(AutomaticCampaignRunner::class);
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->customer = User::factory()->create(['role' => UserRole::USER, 'created_at' => $this->now->copy()->subDays(90)]);
});

function deliveredOrder(User $user, Carbon $completedAt, array $extra = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'user_id' => $user->id,
        'status' => GasOrderStatus::DELIVERED,
        'completed_at' => $completedAt,
        'created_at' => $completedAt->copy()->subHours(3),
    ], $extra));
}

function notificationsFor(User $user, string $campaign): int
{
    return CampaignDelivery::where('user_id', $user->id)->where('campaign_key', $campaign)->count();
}

it('lembrete de gás: avisa quando a última entrega foi há 17 dias (ritmo por omissão de 18)', function () {
    deliveredOrder($this->customer, $this->now->copy()->subDays(17));

    $this->runner->run('gas_reminder');

    expect(notificationsFor($this->customer, 'gas_reminder'))->toBe(1);
});

it('lembrete de gás: ainda não avisa com a última entrega há 10 dias', function () {
    deliveredOrder($this->customer, $this->now->copy()->subDays(10));

    $this->runner->run('gas_reminder');

    expect(notificationsFor($this->customer, 'gas_reminder'))->toBe(0);
});

it('lembrete de gás: aprende o ritmo da casa (quem pede de 10 em 10 dias é avisado ao 8.º dia)', function () {
    deliveredOrder($this->customer, $this->now->copy()->subDays(38));
    deliveredOrder($this->customer, $this->now->copy()->subDays(28));
    deliveredOrder($this->customer, $this->now->copy()->subDays(18));
    deliveredOrder($this->customer, $this->now->copy()->subDays(8));

    $this->runner->run('gas_reminder');

    expect(notificationsFor($this->customer, 'gas_reminder'))->toBe(1);
});

it('lembrete de gás: não avisa quem já fez outro pedido depois da última entrega', function () {
    deliveredOrder($this->customer, $this->now->copy()->subDays(20));
    GasOrder::factory()->create(['user_id' => $this->customer->id, 'status' => GasOrderStatus::CONFIRMED, 'created_at' => $this->now->copy()->subDay()]);

    $this->runner->run('gas_reminder');

    expect(notificationsFor($this->customer, 'gas_reminder'))->toBe(0);
});

it('lembrete de gás: quem nunca pediu recebe 15 dias depois do registo, uma única vez', function () {
    $new = User::factory()->create(['role' => UserRole::USER, 'created_at' => $this->now->copy()->subDays(16)]);

    $this->runner->run('gas_reminder');
    $this->travel(1)->days();
    $this->runner->run('gas_reminder');

    expect(notificationsFor($new, 'gas_reminder'))->toBe(1);
});

it('segundo lembrete: uma vez, 3 dias depois, só se não houve pedido', function () {
    deliveredOrder($this->customer, $this->now->copy()->subDays(17));
    $this->runner->run('gas_reminder');

    // 3 dias e 4 horas depois (14:00 — fora das horas de silêncio).
    $this->travelTo($this->now->copy()->addDays(3)->addHours(4));
    $this->runner->run('gas_reminder_followup');
    $this->runner->run('gas_reminder_followup');

    expect(notificationsFor($this->customer, 'gas_reminder_followup'))->toBe(1);
});

it('segundo lembrete: não sai se a pessoa entretanto pediu', function () {
    deliveredOrder($this->customer, $this->now->copy()->subDays(17));
    $this->runner->run('gas_reminder');
    $this->travel(1)->days();
    GasOrder::factory()->create(['user_id' => $this->customer->id]);

    $this->travelTo($this->now->copy()->addDays(3)->addHours(4));
    $this->runner->run('gas_reminder_followup');

    expect(notificationsFor($this->customer, 'gas_reminder_followup'))->toBe(0);
});

it('operador: avisado quando o posto não é atualizado há mais de 6 horas', function () {
    $operator = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    $stale = assignOperator($operator, Station::factory()->create(['status_updated_at' => now()->subHours(7)]));
    $otherOperator = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    assignOperator($otherOperator, Station::factory()->create(['status_updated_at' => now()->subHour()]));

    $this->runner->run('operator_stale');

    expect(notificationsFor($operator, 'operator_stale'))->toBe(1)
        ->and(notificationsFor($otherOperator, 'operator_stale'))->toBe(0);
    expect(AppNotification::where('user_id', $operator->id)->first()->title)->toContain($stale->name);
});

it('resumo do dia: o Admin vê só a sua província, o Super Admin vê tudo', function () {
    $otherProvince = Province::factory()->create();
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    GasOrder::factory()->count(2)->create(['province_id' => $this->province->id, 'municipality_id' => $this->municipality->id]);
    GasOrder::factory()->count(3)->create(['province_id' => $otherProvince->id]);

    $this->runner->run('admin_daily_summary');

    expect(AppNotification::where('user_id', $admin->id)->first()->body)->toStartWith('2 pedidos')
        ->and(AppNotification::where('user_id', $super->id)->first()->body)->toStartWith('5 pedidos');
});

it('datas especiais: só no dia da data (11-11)', function () {
    $this->runner->run('special_dates');
    expect(notificationsFor($this->customer, 'special_dates'))->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-11 10:15', 'Africa/Luanda')->utc());
    $this->runner->run('special_dates');
    expect(AppNotification::where('user_id', $this->customer->id)->first()->title)->toContain('Independência');

    // Estatísticas por variante: fica o índice da data no catálogo.
    $this->travelTo(Carbon::parse('2026-12-20 10:15', 'Africa/Luanda')->utc());
    $this->runner->run('special_dates');
    expect(CampaignDelivery::where('campaign_key', 'special_dates')->orderBy('id')->pluck('variant')->all())->toBe([0, 1]);
});

it('pós-entrega: pede avaliação 1 a 3 horas depois, só se ainda não avaliou', function () {
    $driver = User::factory()->create(['role' => UserRole::DRIVER, 'name' => 'António Chilala']);
    $order = deliveredOrder($this->customer, now()->subHours(2), ['assigned_to' => $driver->id]);
    $rated = deliveredOrder(User::factory()->create(['role' => UserRole::USER]), now()->subHours(2), ['assigned_to' => $driver->id]);
    DriverRating::create(['gas_order_id' => $rated->id, 'driver_id' => $driver->id, 'rating' => 5]);

    $this->runner->run('post_delivery');

    $notification = AppNotification::where('user_id', $this->customer->id)->first();
    expect($notification->body)->toContain('António')
        ->and($notification->data['path'])->toBe('/avaliar/' . $order->order_reference)
        ->and(CampaignDelivery::where('campaign_key', 'post_delivery')->count())->toBe(1);
});

it('aniversário de conta: no dia certo, com o número de anos', function () {
    $veteran = User::factory()->create(['role' => UserRole::USER, 'created_at' => Carbon::parse('2024-10-01 09:00', 'Africa/Luanda')->utc()]);

    $this->runner->run('account_anniversary');

    expect(AppNotification::where('user_id', $veteran->id)->first()->body)->toContain('2 anos');
});
