<?php

namespace Database\Factories;

use App\Enums\GateEvaluationStatus;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateEvaluation>
 */
class GateEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_gate_id' => TrainingGate::factory(),
            'user_id' => User::factory(),
            'status' => GateEvaluationStatus::NotReady,
            'reviewer_id' => null,
            'evaluated_at' => null,
            'comments' => null,
        ];
    }
}
