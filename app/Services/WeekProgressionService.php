<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\TrainingSessionStatus;
use App\Enums\UserRole;
use App\Enums\WeekAccessState;
use App\Models\Activity;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\SessionWeekState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Week progression within a training session. A participant opens week N of a session from its open date
 * (`TrainingSession::weekOpensOn()`), without completing earlier weeks, unless an earlier week of the session has an
 * incomplete review (`GateEvaluationStatus::incompleteReviews()`): that locks every later week until the review becomes
 * completed. A participant passes a week once every Gate of the week has a completed (`Passed`) review in the session.
 * Coaches and admins open every week. Work in another session never counts.
 */
class WeekProgressionService
{
    public function isWeekPassed(User $user, TrainingSession $trainingSession, TrainingWeek $week): bool
    {
        return $this->passedWeekIds($user, $trainingSession, collect([$week]))->contains($week->id);
    }

    /**
     * Whether the user has passed one of the week's active Gates in the session, which locks the week's Weekly Video
     * Recording.
     */
    public function hasPassedActiveGate(User $user, TrainingSession $trainingSession, TrainingWeek $week): bool
    {
        return $week->gates()
            ->where('is_active', true)
            ->whereHas('evaluations', fn (Builder $query): Builder => $query
                ->where('training_session_id', $trainingSession->id)
                ->where('user_id', $user->id)
                ->where('status', GateEvaluationStatus::Passed))
            ->exists();
    }

    /**
     * Whether the user can open the week of the session: coaches and admins always, a participant while the week is
     * open for them (see weekStates()).
     */
    public function canAccessWeek(User $user, TrainingSession $trainingSession, TrainingWeek $week): bool
    {
        if ($user->isCoachOrAdmin()) {
            return true;
        }
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        return $this->weekStates($user, $trainingSession)->get($week->id)?->isOpen() ?? false;
    }

    /**
     * The state of each of the session's weeks for the participant, keyed by week id in week order, with a fixed
     * number of queries (see weekStatesForMemberships()).
     *
     * @return Collection<int, SessionWeekState>
     */
    public function weekStates(User $participant, TrainingSession $trainingSession): Collection
    {
        $weeks = $trainingSession->weeks()->get(['id', 'program_id', 'week_number', 'is_active']);

        return $this->sessionWeekStates(
            $trainingSession,
            $participant->id,
            $weeks,
            $this->reviewedGatesByWeek(collect([$trainingSession->id]), collect([$participant->id]), $weeks),
        );
    }

    /**
     * Resolve the state of each session week for each session membership with a fixed number of queries. Week N is
     * locked when an earlier week of the session has an incomplete review by the participant there (the earliest such
     * week locks it), otherwise open from its open date and not open before it. A week is passed when the participant
     * has a completed review of every Gate of the week in the session.
     *
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships  participants' memberships of sessions of the program, with their training session loaded
     * @param  Collection<int, TrainingWeek>  $programWeeks  the weeks of the program (weeks outside a membership's session are left out of its states)
     * @return Collection<array-key, Collection<int, SessionWeekState>> keyed like the memberships, then by week id in week order
     */
    public function weekStatesForMemberships(Collection $memberships, Collection $programWeeks): Collection
    {
        $gatesByWeek = $this->reviewedGatesByWeek(
            $memberships->pluck('training_session_id'),
            $memberships->pluck('user_id'),
            $programWeeks,
        );

        return $memberships->map(fn (TrainingSessionParticipant $membership): Collection => $this->sessionWeekStates(
            $membership->trainingSession,
            (int) $membership->user_id,
            $programWeeks,
            $gatesByWeek,
        ));
    }

    /**
     * The id of the participant's current week among the session's week states: the latest open week of an active
     * session, or null when the session is not active or no week is open.
     *
     * @param  Collection<int, SessionWeekState>  $weekStates  keyed by week id (see weekStates())
     */
    public function currentWeekId(TrainingSession $trainingSession, Collection $weekStates): ?int
    {
        if ($trainingSession->status() !== TrainingSessionStatus::Active) {
            return null;
        }

        return $weekStates
            ->filter(fn (SessionWeekState $state): bool => $state->isOpen())
            ->sortBy(fn (SessionWeekState $state): int => $state->weekNumber)
            ->keys()
            ->last();
    }

    /**
     * Whether the user passed every week of the session (and it has at least one).
     */
    public function hasCompletedSession(User $user, TrainingSession $trainingSession): bool
    {
        $weeks = $trainingSession->weeks()->get();

        return $weeks->isNotEmpty() && $this->allWeeksPassed($weeks, $this->passedWeekIds($user, $trainingSession, $weeks));
    }

    /**
     * The memberships whose participant passed every week of the membership's session (and it has at least one),
     * resolved with a fixed number of queries.
     *
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships  with their training session loaded
     * @return Collection<array-key, TrainingSessionParticipant>
     */
    public function completedMemberships(Collection $memberships): Collection
    {
        if ($memberships->isEmpty()) {
            return $memberships;
        }

        $weeks = TrainingWeek::query()
            ->whereIn('program_id', $memberships->map(fn (TrainingSessionParticipant $membership): int => $membership->trainingSession->program_id)->unique()->values())
            ->where('is_active', true)
            ->get();
        $gatesByWeek = $this->reviewedGatesByWeek($memberships->pluck('training_session_id'), $memberships->pluck('user_id'), $weeks);

        return $memberships->filter(function (TrainingSessionParticipant $membership) use ($weeks, $gatesByWeek): bool {
            $sessionWeeks = $weeks->filter(fn (TrainingWeek $week): bool => $membership->trainingSession->includesWeek($week));

            return $sessionWeeks->isNotEmpty() && $sessionWeeks->every(fn (TrainingWeek $week): bool => $this->isPassed(
                $gatesByWeek->get($week->id, collect()),
                (int) $membership->training_session_id,
                (int) $membership->user_id,
            ));
        });
    }

    /**
     * Refuse a participant a week of the session that is not open for them, with the message its tab shows (when it
     * opens, or which week locks it).
     *
     * @throws AuthorizationException
     */
    public function ensureCanAccessWeek(User $user, TrainingSession $trainingSession, TrainingWeek $week): void
    {
        if ($user->isCoachOrAdmin()) {
            return;
        }

        $state = $user->role === UserRole::Participant ? $this->weekStates($user, $trainingSession)->get($week->id) : null;
        if ($state?->isOpen()) {
            return;
        }

        throw new AuthorizationException($state?->lockedMessage() ?? 'This training week is not available to your account.');
    }

    public function ensureCanAccessActivity(User $user, TrainingSession $trainingSession, TrainingWeek $week, Activity $activity): void
    {
        abort_unless($activity->training_week_id === $week->id, 404);
        $this->ensureCanAccessWeek($user, $trainingSession, $week);
    }

    /**
     * Whether the week is the participant's current week in the session (see currentWeekId()).
     */
    public function isCurrentWeek(User $user, TrainingSession $trainingSession, TrainingWeek $week): bool
    {
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        return $this->currentWeekId($trainingSession, $this->weekStates($user, $trainingSession)) === $week->id;
    }

    /**
     * Whether the participant must check in before working on the activity in the session: in their current week,
     * without an open check-in, while their latest attempt there is missing, a draft or needs revision.
     */
    public function requiresAttendanceForActivity(User $user, TrainingSession $trainingSession, TrainingWeek $week, Activity $activity): bool
    {
        if ($user->role !== UserRole::Participant || ! $this->isCurrentWeek($user, $trainingSession, $week) || $user->attendanceSessions()->whereNull('check_out_at')->exists()) {
            return false;
        }

        $submission = $activity->submissions()
            ->where('training_session_id', $trainingSession->id)
            ->where('user_id', $user->id)
            ->latest('attempt_number')
            ->first();

        return ! $submission || $submission->isDraft() || $submission->needsRevision();
    }

    /**
     * Whether the participant must check in before starting the activity in the session: in their current week,
     * without an open check-in, while they have no attempt at it there.
     */
    public function requiresAttendanceForNewActivity(User $user, TrainingSession $trainingSession, TrainingWeek $week, Activity $activity): bool
    {
        return $this->isCurrentWeek($user, $trainingSession, $week)
            && ! $user->attendanceSessions()->whereNull('check_out_at')->exists()
            && ! $activity->submissions()->where('training_session_id', $trainingSession->id)->where('user_id', $user->id)->exists();
    }

    public function ensureCanEditActivity(User $user, TrainingSession $trainingSession, TrainingWeek $week, Activity $activity): void
    {
        if ($this->requiresAttendanceForActivity($user, $trainingSession, $week, $activity)) {
            abort(403, 'Please check in to continue editing this activity.');
        }
    }

    /**
     * The state of each of the session's weeks among the given weeks for one participant, keyed by week id in week
     * order. Walking the weeks in order, the first week with an incomplete review locks every week after it.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @param  Collection<int, Collection<int, TrainingGate>>  $gatesByWeek  see reviewedGatesByWeek()
     * @return Collection<int, SessionWeekState>
     */
    private function sessionWeekStates(TrainingSession $trainingSession, int $userId, Collection $weeks, Collection $gatesByWeek): Collection
    {
        $today = today();
        $lockedByWeekNumber = null;
        $states = collect();

        foreach ($weeks->filter(fn (TrainingWeek $week): bool => $trainingSession->includesWeek($week))->sortBy('week_number') as $week) {
            $gates = $gatesByWeek->get($week->id, collect());
            $opensOn = $trainingSession->weekOpensOn($week->week_number);
            $access = match (true) {
                $lockedByWeekNumber !== null => WeekAccessState::Locked,
                $opensOn->lte($today) => WeekAccessState::Open,
                default => WeekAccessState::NotOpen,
            };
            $states->put($week->id, new SessionWeekState(
                weekNumber: $week->week_number,
                access: $access,
                opensOn: $opensOn,
                lockedByWeekNumber: $lockedByWeekNumber,
                passed: $this->isPassed($gates, $trainingSession->id, $userId),
            ));

            if ($lockedByWeekNumber === null && $this->hasIncompleteReview($gates, $trainingSession->id, $userId)) {
                $lockedByWeekNumber = $week->week_number;
            }
        }

        return $states;
    }

    /**
     * The Gates of the given weeks, grouped by week id, each with its completed and incomplete reviews by the given
     * users in the given sessions: two queries.
     *
     * @param  Collection<array-key, int>  $sessionIds
     * @param  Collection<array-key, int>  $userIds
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, Collection<int, TrainingGate>>
     */
    private function reviewedGatesByWeek(Collection $sessionIds, Collection $userIds, Collection $weeks): Collection
    {
        if ($weeks->isEmpty() || $sessionIds->isEmpty()) {
            return collect();
        }

        return TrainingGate::query()
            ->select(['id', 'training_week_id', 'is_active'])
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->with(['evaluations' => fn (Relation $query): Relation => $query
                ->select(['id', 'training_gate_id', 'training_session_id', 'user_id', 'status'])
                ->whereIn('training_session_id', $sessionIds->unique()->values())
                ->whereIn('user_id', $userIds->unique()->values())
                ->whereIn('status', [GateEvaluationStatus::Passed, ...GateEvaluationStatus::incompleteReviews()])])
            ->get()
            ->groupBy('training_week_id');
    }

    /**
     * Whether the week's Gates (at least one) each have a completed review by the user in the session.
     *
     * @param  Collection<int, TrainingGate>  $gates  with their reviews loaded (see reviewedGatesByWeek())
     */
    private function isPassed(Collection $gates, int $trainingSessionId, int $userId): bool
    {
        return $gates->isNotEmpty() && $gates->every(fn (TrainingGate $gate): bool => $gate->evaluations->contains(
            fn (GateEvaluation $evaluation): bool => $this->isReviewBy($evaluation, $trainingSessionId, $userId)
                && $evaluation->status === GateEvaluationStatus::Passed,
        ));
    }

    /**
     * Whether one of the week's active Gates has an incomplete review by the user in the session.
     *
     * @param  Collection<int, TrainingGate>  $gates  with their reviews loaded (see reviewedGatesByWeek())
     */
    private function hasIncompleteReview(Collection $gates, int $trainingSessionId, int $userId): bool
    {
        return $gates->contains(fn (TrainingGate $gate): bool => $gate->is_active && $gate->evaluations->contains(
            fn (GateEvaluation $evaluation): bool => $this->isReviewBy($evaluation, $trainingSessionId, $userId)
                && in_array($evaluation->status, GateEvaluationStatus::incompleteReviews(), true),
        ));
    }

    private function isReviewBy(GateEvaluation $evaluation, int $trainingSessionId, int $userId): bool
    {
        return (int) $evaluation->training_session_id === $trainingSessionId && (int) $evaluation->user_id === $userId;
    }

    /**
     * Ids of the given weeks that have at least one Gate and a passed evaluation by the user in the session for every
     * Gate.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, int>
     */
    private function passedWeekIds(User $user, TrainingSession $trainingSession, Collection $weeks): Collection
    {
        if ($weeks->isEmpty()) {
            return collect();
        }

        return TrainingGate::query()
            ->select(['id', 'training_week_id'])
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->withExists(['evaluations as passed_by_user' => fn (Builder $query): Builder => $query
                ->where('training_session_id', $trainingSession->id)
                ->where('user_id', $user->id)
                ->where('status', GateEvaluationStatus::Passed)])
            ->get()
            ->groupBy('training_week_id')
            ->filter(fn (Collection $gates): bool => $gates->every(fn (TrainingGate $gate): bool => $gate->passed_by_user))
            ->keys();
    }

    /**
     * @param  Collection<int, TrainingWeek>  $weeks
     * @param  Collection<int, int>  $passedWeekIds
     */
    private function allWeeksPassed(Collection $weeks, Collection $passedWeekIds): bool
    {
        return $weeks->every(fn (TrainingWeek $week): bool => $passedWeekIds->contains($week->id));
    }
}
