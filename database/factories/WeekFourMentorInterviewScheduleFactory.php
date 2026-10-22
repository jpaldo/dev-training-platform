<?php

namespace Database\Factories;

use App\Enums\MentorInterviewScheduleStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\User;
use App\Models\WeekFourMentorInterviewSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<WeekFourMentorInterviewSchedule>
 */
class WeekFourMentorInterviewScheduleFactory extends Factory
{
    /**
     * Define the model's default state: a one-hour interview tomorrow that has not reached Google Calendar yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => User::factory(),
            'mentor_id' => User::factory()->state(['role' => UserRole::Coach, 'training_path' => null]),
            'activity_id' => Activity::factory(),
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'ends_at' => fn (array $attributes): Carbon => Carbon::parse($attributes['starts_at'])->addMinutes((int) $attributes['duration_minutes']),
            'timezone' => config('training.week_four_mentor_interview_timezone'),
            'status' => MentorInterviewScheduleStatus::Pending,
        ];
    }

    /**
     * Indicate that Google Calendar created the event and its Meet link.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MentorInterviewScheduleStatus::Scheduled,
            'google_event_id' => 'event-'.fake()->unique()->bothify('########'),
            'google_meet_url' => 'https://meet.google.com/'.fake()->lexify('???-????-???'),
            'google_conference_id' => 'conference-'.fake()->bothify('########'),
            'scheduled_at' => now(),
        ]);
    }

    /**
     * Indicate that Google Calendar could not create the event.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MentorInterviewScheduleStatus::Failed,
            'failure_reason' => 'Google Calendar could not create the meeting.',
        ]);
    }

    /**
     * Indicate that the interview was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MentorInterviewScheduleStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
