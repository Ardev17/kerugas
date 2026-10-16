<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone' => '+2449' . $this->faker->unique()->numerify('########'),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'province_id' => Province::factory(),
            'municipality_id' => Municipality::factory(),
            'role' => UserRole::USER,
            'status' => 'ACTIVE',
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::ADMIN]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'SUSPENDED']);
    }
}
