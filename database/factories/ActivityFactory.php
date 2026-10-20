<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_week_id' => TrainingWeek::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'objectives' => fake()->sentence(),
            'instructions' => fake()->paragraphs(2, true),
            'position' => fake()->numberBetween(1, 9),
            'requires_coach_review' => false,
            'is_active' => true,
        ];
    }
}
