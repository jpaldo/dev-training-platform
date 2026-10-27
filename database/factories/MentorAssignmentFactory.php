<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MentorAssignment>
 */
class MentorAssignmentFactory extends Factory
{
    /**
     * Define the model's default state: a coach's current assignment to a participant in a new session, started now.
     * It does not add either of them to the session; use TrainingSessionFactory::withParticipants() and withMentors()
     * when the test needs the memberships too. Its curriculum is the session's program, or a new program when the
     * assignment has no session.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_session_id' => TrainingSession::factory(),
            'program_id' => fn (array $attributes): int|ProgramFactory => TrainingSession::query()
                ->whereKey($attributes['training_session_id'])
                ->value('program_id') ?? Program::factory(),
            'participant_id' => User::factory(),
            'mentor_id' => User::factory()->state(['role' => UserRole::Coach]),
            'assigned_by' => null,
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    /**
     * An assignment that has ended, now unless a time is given.
     */
    public function ended(?CarbonInterface $endedAt = null): static
    {
        return $this->state(fn (): array => ['ended_at' => $endedAt ?? now()]);
    }
}
