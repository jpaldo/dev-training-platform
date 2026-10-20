<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivitySubmission>
 */
class ActivitySubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'user_id' => User::factory(),
            'status' => 'draft',
            'submitted_at' => null,
            'completed_at' => null,
        ];
    }
}
