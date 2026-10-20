<?php

namespace Database\Factories;

use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionVersion>
 */
class SubmissionVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_submission_id' => ActivitySubmission::factory(),
            'version_number' => 1,
            'status' => 'draft',
            'field_values' => [],
            'participant_note' => null,
            'submitted_at' => null,
        ];
    }
}
