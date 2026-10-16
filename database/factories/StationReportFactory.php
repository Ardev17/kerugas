<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StationReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'user_id' => User::factory(),
            'reason' => ReportReason::GAS_UNAVAILABLE,
            'description' => $this->faker->sentence(),
            'status' => ReportStatus::OPEN,
        ];
    }
}
