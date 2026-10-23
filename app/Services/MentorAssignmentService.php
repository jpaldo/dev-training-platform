<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\MentorAssignment;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A training session's mentors and which of them mentors each participant. A participant has at most one current
 * assignment per session (`ended_at` null); changing the mentor ends it and opens a new one, so the ended rows are
 * the history.
 */
class MentorAssignmentService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * Add the coaches to the session as mentors and record `session_mentor_added` for each of them. Returns the number
     * added. The request has already checked that each is an active coach who is not yet a mentor of the session.
     *
     * @param  list<int>  $mentorIds
     */
    public function addMentors(TrainingSession $session, array $mentorIds, User $admin): int
    {
        return DB::transaction(function () use ($session, $mentorIds, $admin): int {
            $mentors = User::query()->whereKey($mentorIds)->get();

            $session->mentors()->attach($mentors->modelKeys());

            $mentors->each(fn (User $mentor): AdminAuditLog => $this->audit->record($admin, 'session_mentor_added', $mentor, [
                'training_session_id' => $session->id,
            ]));

            return $mentors->count();
        });
    }

    /**
     * Remove the mentor from the session, end their current assignments there (those participants become unassigned)
     * and record `session_mentor_removed`.
     */
    public function removeMentor(TrainingSession $session, User $mentor, User $admin): void
    {
        DB::transaction(function () use ($session, $mentor, $admin): void {
            $session->mentorAssignments()
                ->where('mentor_id', $mentor->id)
                ->current()
                ->update(['ended_at' => now()]);

            $session->mentors()->detach($mentor->id);

            $this->audit->record($admin, 'session_mentor_removed', $mentor, [
                'training_session_id' => $session->id,
            ]);
        });
    }

    /**
     * Make the mentor the participant's current mentor in the session: end the current assignment, if any, and open a
     * new one from the same moment, and record `session_mentor_assigned` with the previous and new mentor IDs. When the
     * mentor already is the current one, nothing changes and that assignment is returned. The participant's membership
     * row is locked first, so two assignments at once cannot both open a row.
     *
     * @throws ValidationException when the participant is not in the session (`participant`) or the mentor is not a
     *                             mentor of the session (`mentor_id`).
     */
    public function assign(TrainingSession $session, User $participant, User $mentor, User $actor): MentorAssignment
    {
        return DB::transaction(function () use ($session, $participant, $mentor, $actor): MentorAssignment {
            $membership = TrainingSessionParticipant::query()
                ->forParticipantInSession($session->id, $participant->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages(['participant' => 'The participant is not in this training session.']);
            }

            if (! $session->includesMentor($mentor)) {
                throw ValidationException::withMessages(['mentor_id' => 'The selected mentor is not a mentor of this training session.']);
            }

            $current = $this->currentAssignment($session, $participant);

            if ($current?->mentor_id === $mentor->id) {
                return $current;
            }

            $changedAt = now();

            $current?->update(['ended_at' => $changedAt]);

            $assignment = $session->mentorAssignments()->create([
                'participant_id' => $participant->id,
                'mentor_id' => $mentor->id,
                'assigned_by' => $actor->id,
                'started_at' => $changedAt,
            ]);

            $this->audit->record($actor, 'session_mentor_assigned', $participant, [
                'training_session_id' => $session->id,
                'previous_mentor_id' => $current?->mentor_id,
                'mentor_id' => $mentor->id,
            ]);

            return $assignment;
        });
    }

    /**
     * End the participant's current assignment in the session, if any, so they are unassigned. Removing the
     * participant from the session does this (TrainingSessionService::removeParticipant()).
     */
    public function endCurrentAssignment(TrainingSession $session, User $participant): void
    {
        MentorAssignment::query()
            ->forParticipantInSession($session->id, $participant->id)
            ->current()
            ->update(['ended_at' => now()]);
    }

    /**
     * The participant's current mentor in the session, or null when they have none.
     */
    public function currentMentor(TrainingSession $session, User $participant): ?User
    {
        return $this->currentAssignment($session, $participant)?->mentor;
    }

    /**
     * The participant's mentor assignments in the session, current and ended, the earliest first, each with its mentor.
     *
     * @return Collection<int, MentorAssignment>
     */
    public function history(TrainingSession $session, User $participant): Collection
    {
        return MentorAssignment::query()
            ->forParticipantInSession($session->id, $participant->id)
            ->with('mentor')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    private function currentAssignment(TrainingSession $session, User $participant): ?MentorAssignment
    {
        return MentorAssignment::query()
            ->forParticipantInSession($session->id, $participant->id)
            ->current()
            ->first();
    }
}
