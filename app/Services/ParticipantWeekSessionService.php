<?php

namespace App\Services;

use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Picks the training session a participant opens a week in for the one caller that does not carry a session yet: the
 * Gates on the coach participant page (CoachGateOverviewService, until 10.5). The session page, the pages under it and
 * the participant notifications carry their session, so this caller reads the week's progress from, and links to it
 * through, one of the participant's sessions.
 */
class ParticipantWeekSessionService
{
    /**
     * The participant's sessions of the program, preferred first: sessions that are not completed, the earliest start
     * first (an active session before an upcoming one), then completed sessions, the latest start first.
     *
     * @return Collection<int, TrainingSession>
     */
    public function sessionsOfProgram(User $participant, int $programId): Collection
    {
        return $participant->trainingSessions()
            ->where('program_id', $programId)
            ->get()
            ->sortBy([
                fn (TrainingSession $first, TrainingSession $second): int => $first->isCompleted() <=> $second->isCompleted(),
                fn (TrainingSession $first, TrainingSession $second): int => $first->isCompleted()
                    ? $second->starts_on <=> $first->starts_on
                    : $first->starts_on <=> $second->starts_on,
            ])
            ->values();
    }

    /**
     * The first of the sessions (in their given order) that includes the week, or null when none does.
     *
     * @param  Collection<int, TrainingSession>  $sessions
     */
    public function firstIncludingWeek(Collection $sessions, TrainingWeek $week): ?TrainingSession
    {
        return $sessions->first(fn (TrainingSession $session): bool => $session->includesWeek($week));
    }

    /**
     * The participant's preferred session (see sessionsOfProgram()) that includes the week, or null when they are in
     * none.
     */
    public function sessionForWeek(User $participant, TrainingWeek $week): ?TrainingSession
    {
        return $this->firstIncludingWeek($this->sessionsOfProgram($participant, $week->program_id), $week);
    }
}
