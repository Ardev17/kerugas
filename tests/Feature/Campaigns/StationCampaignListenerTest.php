<?php

use App\Enums\QueueLevel;
use App\Enums\UserRole;
use App\Events\GasAvailabilityChanged;
use App\Listeners\StationCampaignListener;
use App\Models\Alert;
use App\Models\CampaignDelivery;
use App\Models\Favorite;
use App\Models\Municipality;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Africa/Luanda')->utc());
    $this->municipality = Municipality::factory()->create();
    $this->station = Station::factory()->create([
        'municipality_id' => $this->municipality->id,
        'province_id' => $this->municipality->province_id,
        'current_gas_available' => true,
        'current_queue_level' => QueueLevel::LOW,
    ]);
    $this->neighbour = User::factory()->create(['role' => UserRole::USER, 'municipality_id' => $this->municipality->id]);
    $this->listener = app(StationCampaignListener::class);
});

it('avisa os clientes do município quando o posto VOLTA a ter gás', function () {
    $elsewhere = User::factory()->create(['role' => UserRole::USER]);

    $this->listener->handle(new GasAvailabilityChanged($this->station, false, QueueLevel::HIGH));

    expect(CampaignDelivery::where('campaign_key', 'gas_back_nearby')->pluck('user_id')->all())->toBe([$this->neighbour->id])
        ->and(CampaignDelivery::where('user_id', $elsewhere->id)->count())->toBe(0);
});

it('não repete o aviso a quem já tem um alerta para este posto (esse já recebe o alerta)', function () {
    Alert::create(['user_id' => $this->neighbour->id, 'station_id' => $this->station->id, 'channels' => ['in_app'], 'active' => true]);

    $this->listener->handle(new GasAvailabilityChanged($this->station, false, QueueLevel::HIGH));

    expect(CampaignDelivery::count())->toBe(0);
});

it('não avisa quando o posto já tinha gás (é só mais uma atualização)', function () {
    $this->listener->handle(new GasAvailabilityChanged($this->station, true, QueueLevel::LOW));

    expect(CampaignDelivery::count())->toBe(0);
});

it('fila curta: avisa quem tem o posto nos favoritos quando a fila PASSA a curta', function () {
    $fan = User::factory()->create(['role' => UserRole::USER]);
    Favorite::create(['user_id' => $fan->id, 'station_id' => $this->station->id]);

    $this->listener->handle(new GasAvailabilityChanged($this->station, true, QueueLevel::HIGH));
    // Segunda atualização no mesmo dia: não repete.
    $this->listener->handle(new GasAvailabilityChanged($this->station, true, QueueLevel::HIGH));

    expect(CampaignDelivery::where('campaign_key', 'short_queue')->where('user_id', $fan->id)->count())->toBe(1);
});
