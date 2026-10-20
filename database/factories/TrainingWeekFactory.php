<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingWeek>
 */
class TrainingWeekFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'week_number' => fake()->unique()->numberBetween(2, 99),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'position' => fake()->unique()->numberBetween(1, 99),
            'is_active' => true,
        ];
    }
}
