<?php

namespace Database\Factories;

use App\Enums\QueueLevel;
use App\Enums\StationUpdateSource;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

class StationStatusUpdateFactory extends Factory
{
    protected $model = \App\Models\StationStatusUpdate::class;

    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'gas_available' => $this->faker->boolean(),
            'queue_level' => $this->faker->randomElement(QueueLevel::cases()),
            'source' => $this->faker->randomElement(StationUpdateSource::cases()),
            'confidence_score' => $this->faker->numberBetween(0, 100),
            'expires_at' => now()->addHours(3),
        ];
    }
}
