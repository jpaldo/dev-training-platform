<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
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
     * Define the model's default state: a draft attempt in a session of the activity's program
     * (TrainingSessionFactory::defaultForWeek()).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'training_session_id' => fn (array $attributes): int|TrainingSessionFactory => TrainingSessionFactory::defaultForWeek(
                Activity::query()->findOrFail($attributes['activity_id'])->week,
            ),
            'user_id' => User::factory(),
            'status' => SubmissionStatus::Draft,
            'submitted_at' => null,
            'completed_at' => null,
        ];
    }
}
