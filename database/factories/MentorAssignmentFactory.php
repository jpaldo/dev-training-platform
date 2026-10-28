<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MentorAssignment>
 */
class MentorAssignmentFactory extends Factory
{
    /**
     * Define the model's default state: a coach's current assignment to a participant in a new curriculum, started
     * now. It does not add either of them to the curriculum; use ProgramFactory::withParticipants() and withMentors()
     * when the test needs the memberships too.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
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
