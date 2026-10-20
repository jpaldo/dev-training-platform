<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyLog>
 */
class DailyLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activity_id' => Activity::factory(),
            'logged_on' => fake()->date(),
            'content' => fake()->paragraph(),
        ];
    }
}
