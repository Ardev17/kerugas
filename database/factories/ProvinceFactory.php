<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProvinceFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4),
        ];
    }
}
