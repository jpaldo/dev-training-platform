<?php

namespace Database\Factories;

use App\Enums\GateCriterionStatus;
use App\Models\GateCriterion;
use App\Models\GateCriterionEvaluation;
use App\Models\GateEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateCriterionEvaluation>
 */
class GateCriterionEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gate_evaluation_id' => GateEvaluation::factory(),
            'gate_criterion_id' => GateCriterion::factory(),
            'status' => GateCriterionStatus::NotMet,
            'notes' => null,
            'reviewer_id' => null,
            'evaluated_at' => null,
        ];
    }
}
