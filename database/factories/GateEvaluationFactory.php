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
     * Define the model's default state: a not-ready evaluation in a session of the Gate's program
     * (TrainingSessionFactory::defaultForWeek()).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_gate_id' => TrainingGate::factory(),
            'training_session_id' => fn (array $attributes): int|TrainingSessionFactory => TrainingSessionFactory::defaultForWeek(
                TrainingGate::query()->findOrFail($attributes['training_gate_id'])->week,
            ),
            'user_id' => User::factory(),
            'status' => GateEvaluationStatus::NotReady,
            'reviewer_id' => null,
            'evaluated_at' => null,
            'comments' => null,
        ];
    }
}
