<?php

namespace Database\Factories;

use App\Models\ActivityRequirement;
use App\Models\EvidenceRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvidenceRequirement>
 */
class EvidenceRequirementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_requirement_id' => ActivityRequirement::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'evidence_type' => 'text',
            'is_required' => true,
            'minimum_count' => 1,
            'maximum_count' => 1,
            'configuration' => null,
            'position' => fake()->numberBetween(1, 9),
        ];
    }
}
