<?php

namespace Database\Factories;

use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingGate>
 */
class TrainingGateFactory extends Factory
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
            'position' => fake()->numberBetween(1, 9),
            'is_active' => true,
        ];
    }
}
