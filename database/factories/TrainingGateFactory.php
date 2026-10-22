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
    use AssignsNextPosition;

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
            'position' => $this->nextPositionWithin('training_week_id'),
            'is_active' => true,
        ];
    }
}
