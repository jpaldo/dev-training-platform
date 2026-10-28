<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A curriculum's mentors and which of them mentors each participant. A participant has at most one current assignment
 * per curriculum (`ended_at` null); changing the mentor ends it and opens a new one, so the ended rows are the history.
 */
class MentorAssignmentService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * Add the coaches to the curriculum as mentors and record `program_mentor_added` for each of them for the admin who
     * added them. Returns the number added. The caller has already checked that each is an active coach who is not yet
     * a mentor of the curriculum. Without an admin (demo provisioning, not an admin action) no audit row is written.
     *
     * @param  list<int>  $mentorIds
     */
    public function addMentors(Program $program, array $mentorIds, ?User $admin): int
    {
        return DB::transaction(function () use ($program, $mentorIds, $admin): int {
            $mentors = User::query()->whereKey($mentorIds)->get();

            $program->mentors()->attach($mentors->modelKeys());

            if ($admin !== null) {
                $mentors->each(fn (User $mentor): AdminAuditLog => $this->audit->record($admin, 'program_mentor_added', $mentor, [
                    'program_id' => $program->id,
                ]));
            }

            return $mentors->count();
        });
    }

    /**
     * Remove the mentor from the curriculum, end their current assignments there (those participants become
     * unassigned) and record `program_mentor_removed`.
     */
    public function removeMentor(Program $program, User $mentor, User $admin): void
    {
        DB::transaction(function () use ($program, $mentor, $admin): void {
            $program->mentorAssignments()
                ->where('mentor_id', $mentor->id)
                ->current()
                ->update(['ended_at' => now()]);

            $program->mentors()->detach($mentor->id);

            $this->audit->record($admin, 'program_mentor_removed', $mentor, [
                'program_id' => $program->id,
            ]);
        });
    }

    /**
     * Make the mentor the participant's current mentor in the curriculum: end the current assignment, if any, and open
     * a new one from the same moment, and record `program_mentor_assigned` with the previous and new mentor IDs. When
     * the mentor already is the current one, nothing changes and that assignment is returned. The participant's
     * membership row is locked first, so two assignments at once cannot both open a row. Without an actor (demo
     * provisioning, not an admin action) the new assignment has no `assigned_by` and no audit row is written.
     *
     * @throws ValidationException when the participant is not in the curriculum (`participant`) or the mentor is not a
     *                             mentor of the curriculum (`mentor_id`).
     */
    public function assign(Program $program, User $participant, User $mentor, ?User $actor): MentorAssignment
    {
        return DB::transaction(function () use ($program, $participant, $mentor, $actor): MentorAssignment {
            $membership = ProgramParticipant::query()
                ->forParticipantInProgram($program->id, $participant->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages(['participant' => 'The participant is not in this curriculum.']);
            }

            if (! $program->includesMentor($mentor)) {
                throw ValidationException::withMessages(['mentor_id' => 'The selected mentor is not a mentor of this curriculum.']);
            }

            $current = $this->currentAssignment($program, $participant);

            if ($current?->mentor_id === $mentor->id) {
                return $current;
            }

            $changedAt = now();

            $current?->update(['ended_at' => $changedAt]);

            $assignment = $program->mentorAssignments()->create([
                'participant_id' => $participant->id,
                'mentor_id' => $mentor->id,
                'assigned_by' => $actor?->id,
                'started_at' => $changedAt,
            ]);

            if ($actor !== null) {
                $this->audit->record($actor, 'program_mentor_assigned', $participant, [
                    'program_id' => $program->id,
                    'previous_mentor_id' => $current?->mentor_id,
                    'mentor_id' => $mentor->id,
                ]);
            }

            return $assignment;
        });
    }

    /**
     * End the participant's current assignment in the curriculum, if any, so they are unassigned. Removing the
     * participant from the curriculum does this (ProgramEnrollmentService::removeParticipant()).
     */
    public function endCurrentAssignment(Program $program, User $participant): void
    {
        MentorAssignment::query()
            ->forParticipantInProgram($program->id, $participant->id)
            ->current()
            ->update(['ended_at' => now()]);
    }

    /**
     * The participant's current mentor in the curriculum, or null when they have none.
     */
    public function currentMentor(Program $program, User $participant): ?User
    {
        return $this->currentAssignment($program, $participant)?->mentor;
    }

    /**
     * The participant's mentor assignments in the curriculum, current and ended, the earliest first, each with its
     * mentor.
     *
     * @return Collection<int, MentorAssignment>
     */
    public function history(Program $program, User $participant): Collection
    {
        return MentorAssignment::query()
            ->forParticipantInProgram($program->id, $participant->id)
            ->with('mentor')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    private function currentAssignment(Program $program, User $participant): ?MentorAssignment
    {
        return MentorAssignment::query()
            ->forParticipantInProgram($program->id, $participant->id)
            ->current()
            ->first();
    }
}
