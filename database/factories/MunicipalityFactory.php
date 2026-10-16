<?php

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MunicipalityFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->citySuffix() . ' ' . $this->faker->city();

        return [
            'province_id' => Province::factory(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4),
        ];
    }
}
