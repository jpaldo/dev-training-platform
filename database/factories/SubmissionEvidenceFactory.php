<?php

namespace Database\Factories;

use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionEvidence>
 */
class SubmissionEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_version_id' => SubmissionVersion::factory(),
            'evidence_requirement_id' => EvidenceRequirement::factory(),
            'evidence_type' => 'text',
            'title' => fake()->sentence(3),
            'content' => fake()->paragraph(),
            'url' => null,
            'disk' => null,
            'path' => null,
            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'metadata' => null,
        ];
    }
}
