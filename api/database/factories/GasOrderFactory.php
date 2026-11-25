<?php

namespace Database\Factories;

use App\Enums\GasOrderPriority;
use App\Enums\GasOrderStatus;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GasOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_reference' => 'KG-' . now()->format('Ymd') . '-' . Str::padLeft((string) $this->faker->unique()->numberBetween(0, 999999), 6, '0'),
            'customer_name' => $this->faker->name(),
            'customer_phone' => '+2449' . $this->faker->numerify('########'),
            'province_id' => Province::factory(),
            'municipality_id' => Municipality::factory(),
            'location_source' => 'MANUAL',
            'quantity' => 1,
            'status' => GasOrderStatus::PENDING,
            'priority' => GasOrderPriority::NORMAL,
        ];
    }
}
