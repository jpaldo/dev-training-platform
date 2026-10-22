<?php

namespace Database\Factories;

use App\Models\GateCriterion;
use App\Models\TrainingGate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateCriterion>
 */
class GateCriterionFactory extends Factory
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
            'training_gate_id' => TrainingGate::factory(),
            'criterion_type' => 'activity_completion',
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'is_required' => true,
            'position' => $this->nextPositionWithin('training_gate_id'),
            'configuration' => null,
        ];
    }
}
