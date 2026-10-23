<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\TrainingSessionStatus;
use App\Enums\UserRole;
use App\Enums\WeekAccessState;
use App\Models\Activity;
use App\Models\GateEvaluation;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\SessionWeekState;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Week progression within a training session. A participant opens week N of a session from its open date
 * (`TrainingSession::weekOpensOn()`), without completing earlier weeks, unless an earlier week of the session has an
 * incomplete review (`GateEvaluationStatus::incompleteReviews()`): that locks every later week until the review becomes
 * completed. Once the participant hands in work in the locking week after that review (latestWorkSinceReviews()), the
 * locked weeks wait for the mentor to verify it, still locked. A participant passes a week once every Gate of the week
 * has a completed (`Passed`) review in the session. Coaches and admins open every week. Work in another session never
 * counts.
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
        $gatesByWeek = $this->reviewedGatesByWeek(collect([$trainingSession->id]), collect([$participant->id]), $weeks);
        $lockingReview = $this->lockingReview($trainingSession, $participant->id, $weeks, $gatesByWeek);

        return $this->sessionWeekStates(
            $trainingSession,
            $participant->id,
            $weeks,
            $gatesByWeek,
            $lockingReview,
            $this->latestWorkSinceReviews(collect([$lockingReview])->filter()),
        );
    }

    /**
     * Resolve the state of each session week for each session membership with a fixed number of queries (two, and a
     * third when a week is locked). Week N is locked when an earlier week of the session has an incomplete review by the
     * participant there (the earliest such week locks it, see lockingReview()), otherwise open from its open date and not
     * open before it. A locked week awaits verification once the participant handed in work in the locking week after
     * its review (latestWorkSinceReviews()). A week is passed when the participant has a completed review of every Gate
     * of the week in the session.
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

        $lockingReviews = $memberships->map(fn (TrainingSessionParticipant $membership): ?GateEvaluation => $this->lockingReview(
            $membership->trainingSession,
            (int) $membership->user_id,
            $programWeeks,
            $gatesByWeek,
        ));
        $latestWork = $this->latestWorkSinceReviews($lockingReviews->filter()->values());

        return $memberships->map(fn (TrainingSessionParticipant $membership, int|string $key): Collection => $this->sessionWeekStates(
            $membership->trainingSession,
            (int) $membership->user_id,
            $programWeeks,
            $gatesByWeek,
            $lockingReviews->get($key),
            $latestWork,
        ));
    }

    /**
     * For each given incomplete review, the time of the latest activity version its participant handed in after the
     * review (`submitted_at` after `evaluated_at`) in an activity of the review's week, in the review's session (Q2: any
     * activity of the week counts), keyed by review id. Reviews without such a version, or without a review time, are
     * left out, so a key means the participant has new work for the mentor to verify. One query for any number of
     * reviews.
     *
     * @param  Collection<array-key, GateEvaluation>  $reviews  with their Gate loaded
     * @return Collection<int, CarbonInterface>
     */
    public function latestWorkSinceReviews(Collection $reviews): Collection
    {
        $reviews = $reviews->filter(fn (GateEvaluation $review): bool => $review->evaluated_at !== null);
        if ($reviews->isEmpty()) {
            return collect();
        }

        $latestByWeek = SubmissionVersion::query()
            ->join('activity_submissions', 'activity_submissions.id', '=', 'submission_versions.activity_submission_id')
            ->join('activities', 'activities.id', '=', 'activity_submissions.activity_id')
            ->whereIn('activity_submissions.training_session_id', $reviews->pluck('training_session_id')->unique()->values())
            ->whereIn('activity_submissions.user_id', $reviews->pluck('user_id')->unique()->values())
            ->whereIn('activities.training_week_id', $reviews->map(fn (GateEvaluation $review): int => $review->gate->training_week_id)->unique()->values())
            ->where('submission_versions.submitted_at', '>', $reviews->min('evaluated_at'))
            ->groupBy(['activity_submissions.training_session_id', 'activity_submissions.user_id', 'activities.training_week_id'])
            ->select(['activity_submissions.training_session_id', 'activity_submissions.user_id', 'activities.training_week_id'])
            ->selectRaw('max(submission_versions.submitted_at) as latest_submitted_at')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->workKey((int) $row->training_session_id, (int) $row->user_id, (int) $row->training_week_id) => Date::parse($row->latest_submitted_at),
            ]);

        return $reviews->mapWithKeys(function (GateEvaluation $review) use ($latestByWeek): array {
            $latest = $latestByWeek->get($this->workKey((int) $review->training_session_id, (int) $review->user_id, (int) $review->gate->training_week_id));

            return $latest?->gt($review->evaluated_at) ? [$review->id => $latest] : [];
        });
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
     * order. Every week after the week of the locking review is locked, and awaits verification when the review has new
     * work since it.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @param  Collection<int, Collection<int, TrainingGate>>  $gatesByWeek  see reviewedGatesByWeek()
     * @param  ?GateEvaluation  $lockingReview  see lockingReview()
     * @param  Collection<int, CarbonInterface>  $latestWork  see latestWorkSinceReviews()
     * @return Collection<int, SessionWeekState>
     */
    private function sessionWeekStates(TrainingSession $trainingSession, int $userId, Collection $weeks, Collection $gatesByWeek, ?GateEvaluation $lockingReview, Collection $latestWork): Collection
    {
        $today = today();
        $lockingWeekNumber = $lockingReview?->gate->week->week_number;
        $awaitingVerification = $lockingReview !== null && $latestWork->has($lockingReview->id);
        $states = collect();

        foreach ($this->sessionWeeks($trainingSession, $weeks) as $week) {
            $opensOn = $trainingSession->weekOpensOn($week->week_number);
            $isLocked = $lockingWeekNumber !== null && $week->week_number > $lockingWeekNumber;
            $access = match (true) {
                $isLocked => WeekAccessState::Locked,
                $opensOn->lte($today) => WeekAccessState::Open,
                default => WeekAccessState::NotOpen,
            };
            $states->put($week->id, new SessionWeekState(
                weekNumber: $week->week_number,
                access: $access,
                opensOn: $opensOn,
                lockedByWeekNumber: $isLocked ? $lockingWeekNumber : null,
                passed: $this->isPassed($gatesByWeek->get($week->id, collect()), $trainingSession->id, $userId),
                lockingReviewId: $isLocked ? $lockingReview->id : null,
                awaitingVerification: $isLocked && $awaitingVerification,
            ));
        }

        return $states;
    }

    /**
     * The participant's incomplete review that locks the later weeks of the session: walking the session's weeks in
     * order, the first incomplete review of an active Gate, with its Gate and the Gate's week set. Null when no week of
     * the session has one.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @param  Collection<int, Collection<int, TrainingGate>>  $gatesByWeek  see reviewedGatesByWeek()
     */
    private function lockingReview(TrainingSession $trainingSession, int $userId, Collection $weeks, Collection $gatesByWeek): ?GateEvaluation
    {
        foreach ($this->sessionWeeks($trainingSession, $weeks) as $week) {
            foreach ($gatesByWeek->get($week->id, collect())->where('is_active', true) as $gate) {
                $review = $gate->evaluations->first(fn (GateEvaluation $evaluation): bool => $this->isReviewBy($evaluation, $trainingSession->id, $userId)
                    && in_array($evaluation->status, GateEvaluationStatus::incompleteReviews(), true));

                if ($review) {
                    return $review->setRelation('gate', $gate->setRelation('week', $week));
                }
            }
        }

        return null;
    }

    /**
     * The session's weeks among the given weeks, in week order.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, TrainingWeek>
     */
    private function sessionWeeks(TrainingSession $trainingSession, Collection $weeks): Collection
    {
        return $weeks->filter(fn (TrainingWeek $week): bool => $trainingSession->includesWeek($week))->sortBy('week_number');
    }

    /**
     * The key of a participant's work in one week of one session (see latestWorkSinceReviews()).
     */
    private function workKey(int $trainingSessionId, int $userId, int $trainingWeekId): string
    {
        return "{$trainingSessionId}-{$userId}-{$trainingWeekId}";
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
                ->select(['id', 'training_gate_id', 'training_session_id', 'user_id', 'status', 'evaluated_at'])
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
