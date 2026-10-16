<?php

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StationCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'user_id' => User::factory(),
            'content' => $this->faker->sentence(),
            'status' => CommentStatus::PENDING,
        ];
    }
}
