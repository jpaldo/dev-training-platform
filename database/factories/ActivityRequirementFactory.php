<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityRequirement>
 */
class ActivityRequirementFactory extends Factory
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
            'activity_id' => Activity::factory(),
            'participant_path' => null,
            'requirement_type' => 'deliverable',
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'is_required' => true,
            'position' => $this->nextPositionWithin('activity_id'),
            'configuration' => null,
        ];
    }
}
