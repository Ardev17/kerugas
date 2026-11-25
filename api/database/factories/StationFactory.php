<?php

namespace Database\Factories;

use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StationFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Posto ' . $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4),
            'province_id' => Province::factory(),
            'municipality_id' => Municipality::factory(),
            'address' => $this->faker->streetAddress(),
            'latitude' => $this->faker->latitude(-18, -5),
            'longitude' => $this->faker->longitude(11, 24),
            'phone' => '+2449' . $this->faker->numerify('########'),
            'sms_phone' => '+2449' . $this->faker->unique()->numerify('########'),
            'status' => 'ACTIVE',
            'current_gas_available' => false,
            'current_queue_level' => 'LOW',
            'current_confidence_score' => 0,
        ];
    }
}
