<?php

namespace Database\Factories;

use App\Enums\ReviewDecision;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionReview>
 */
class SubmissionReviewFactory extends Factory
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
            'reviewer_id' => User::factory(),
            'decision' => ReviewDecision::Approved,
            'comments' => fake()->paragraph(),
            'reviewed_at' => now(),
        ];
    }
}
