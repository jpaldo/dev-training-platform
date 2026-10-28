<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Enums\WeekAccessState;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\ProgramWeekState;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Week progression within a curriculum. A participant opens week N of a curriculum from its own start date
 * (`TrainingWeek::starts_on`; an unscheduled week never opens), without completing earlier weeks, unless an earlier
 * week has an incomplete review (`GateEvaluationStatus::incompleteReviews()`): that locks every later week until the
 * review becomes completed. Once the participant hands in work in the locking week after that review
 * (latestWorkSinceReviews()), the locked weeks wait for the mentor to verify it, still locked. A participant passes a
 * week once every Gate of the week has a completed (`Passed`) review. Coaches and admins open every week. Once the
 * curriculum has ended, the participant views every week, read only: they can only work on an attempt a mentor
 * reopened (`Program::acceptsWork()`, ensureProgramAcceptsWork()). Work belongs to a curriculum through its activity,
 * Gate or week, so no other curriculum's work can count.
 */
class WeekProgressionService
{
    public function isWeekPassed(User $user, TrainingWeek $week): bool
    {
        return $this->passedWeekIds($user, collect([$week]))->contains($week->id);
    }

    /**
     * Whether the user has passed one of the week's active Gates, which locks the week's Weekly Video Recording.
     */
    public function hasPassedActiveGate(User $user, TrainingWeek $week): bool
    {
        return $week->gates()
            ->where('is_active', true)
            ->whereHas('evaluations', fn (Builder $query): Builder => $query
                ->where('user_id', $user->id)
                ->where('status', GateEvaluationStatus::Passed))
            ->exists();
    }

    /**
     * Whether the user can open the week of the curriculum: coaches and admins always, a participant while the week is
     * viewable for them (open, or any week once the curriculum has ended; see weekStates()).
     */
    public function canAccessWeek(User $user, Program $program, TrainingWeek $week): bool
    {
        if ($user->isCoachOrAdmin()) {
            return true;
        }
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        return $this->weekStates($user, $program)->get($week->id)?->isViewable() ?? false;
    }

    /**
     * The state of each of the curriculum's active weeks for the participant, keyed by week id in week order, with a
     * fixed number of queries (see weekStatesForMemberships()).
     *
     * @return Collection<int, ProgramWeekState>
     */
    public function weekStates(User $participant, Program $program): Collection
    {
        $weeks = $program->activeWeeks()->get(['id', 'program_id', 'week_number', 'is_active', 'starts_on', 'ends_on']);
        $gatesByWeek = $this->reviewedGatesByWeek(collect([$participant->id]), $weeks);
        $lockingReview = $this->lockingReview($program->id, $participant->id, $weeks, $gatesByWeek);

        return $this->programWeekStates(
            $program->id,
            $program->isCompleted(),
            $participant->id,
            $weeks,
            $gatesByWeek,
            $lockingReview,
            $this->latestWorkSinceReviews(collect([$lockingReview])->filter()),
        );
    }

    /**
     * Resolve the state of each active week of the curriculum for each membership with a fixed number of queries (two,
     * a third when a week is locked, and one per curriculum not loaded with `Program::withScheduleBounds()` or
     * `loadScheduleBounds()`). Week N is locked when an earlier week has an incomplete review by the participant (the
     * earliest such week locks it, see lockingReview()), otherwise open from its start date and not open before it or
     * while it has none. A locked week awaits verification once the participant handed in work in the locking week
     * after its review (latestWorkSinceReviews()). A week is passed when the participant has a completed review of
     * every Gate of the week.
     *
     * @param  Collection<array-key, ProgramParticipant>  $memberships  participants' memberships of curricula, with their program loaded
     * @param  Collection<int, TrainingWeek>  $programWeeks  the weeks of the memberships' curricula, with their dates (inactive weeks and other curricula's weeks are left out of a membership's states)
     * @return Collection<array-key, Collection<int, ProgramWeekState>> keyed like the memberships, then by week id in week order
     */
    public function weekStatesForMemberships(Collection $memberships, Collection $programWeeks): Collection
    {
        $gatesByWeek = $this->reviewedGatesByWeek($memberships->pluck('user_id'), $programWeeks);
        $lockingReviews = $this->lockingReviewsOf($memberships, $programWeeks, $gatesByWeek);
        $latestWork = $this->latestWorkSinceReviews($lockingReviews->filter()->values());
        $completedByProgram = $this->completedByProgram($memberships);

        return $memberships->map(fn (ProgramParticipant $membership, int|string $key): Collection => $this->programWeekStates(
            (int) $membership->program_id,
            $completedByProgram->get($membership->program_id),
            (int) $membership->user_id,
            $programWeeks,
            $gatesByWeek,
            $lockingReviews->get($key),
            $latestWork,
        ));
    }

    /**
     * For each membership, the participant's incomplete review that locks the later weeks of the curriculum
     * (lockingReview(): walking the curriculum's active weeks in order, the first incomplete review of an active Gate,
     * with its Gate and the Gate's week set), or null when no week has one. Two queries for any number of memberships,
     * of any curricula; pass the result to latestWorkSinceReviews() to learn which reviews have new work.
     *
     * @param  Collection<array-key, ProgramParticipant>  $memberships
     * @param  Collection<int, TrainingWeek>  $weeks  the weeks of the memberships' curricula (other curricula's weeks are ignored for a membership)
     * @return Collection<array-key, ?GateEvaluation> keyed like the memberships
     */
    public function lockingReviewsForMemberships(Collection $memberships, Collection $weeks): Collection
    {
        return $this->lockingReviewsOf(
            $memberships,
            $weeks,
            $this->reviewedGatesByWeek($memberships->pluck('user_id'), $weeks),
        );
    }

    /**
     * For each given incomplete review, the time of the latest activity version its participant handed in after the
     * review (`submitted_at` after `evaluated_at`) in an activity of the review's week (Q2: any activity of the week
     * counts), keyed by review id. Reviews without such a version, or without a review time, are left out, so a key
     * means the participant has new work for the mentor to verify. One query for any number of reviews.
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
            ->whereIn('activity_submissions.user_id', $reviews->pluck('user_id')->unique()->values())
            ->whereIn('activities.training_week_id', $reviews->map(fn (GateEvaluation $review): int => $review->gate->training_week_id)->unique()->values())
            ->where('submission_versions.submitted_at', '>', $reviews->min('evaluated_at'))
            ->groupBy(['activity_submissions.user_id', 'activities.training_week_id'])
            ->select(['activity_submissions.user_id', 'activities.training_week_id'])
            ->selectRaw('max(submission_versions.submitted_at) as latest_submitted_at')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->workKey((int) $row->user_id, (int) $row->training_week_id) => Date::parse($row->latest_submitted_at),
            ]);

        return $reviews->mapWithKeys(function (GateEvaluation $review) use ($latestByWeek): array {
            $latest = $latestByWeek->get($this->workKey((int) $review->user_id, (int) $review->gate->training_week_id));

            return $latest?->gt($review->evaluated_at) ? [$review->id => $latest] : [];
        });
    }

    /**
     * The id of the participant's current week among the curriculum's week states: the latest open week of an active
     * curriculum, or null when no week is open (the curriculum is unscheduled or upcoming) or the curriculum has ended.
     *
     * @param  Collection<int, ProgramWeekState>  $weekStates  keyed by week id (see weekStates())
     */
    public function currentWeekId(Collection $weekStates): ?int
    {
        if ($weekStates->contains(fn (ProgramWeekState $state): bool => $state->programCompleted)) {
            return null;
        }

        return $weekStates
            ->filter(fn (ProgramWeekState $state): bool => $state->isOpen())
            ->sortBy(fn (ProgramWeekState $state): int => $state->weekNumber)
            ->keys()
            ->last();
    }

    /**
     * Whether the user passed every active week of the curriculum (and it has at least one).
     */
    public function hasCompletedProgram(User $user, Program $program): bool
    {
        $weeks = $program->activeWeeks()->get();

        return $weeks->isNotEmpty() && $this->allWeeksPassed($weeks, $this->passedWeekIds($user, $weeks));
    }

    /**
     * The memberships whose participant passed every active week of the membership's curriculum (and it has at least
     * one), resolved with a fixed number of queries.
     *
     * @param  Collection<array-key, ProgramParticipant>  $memberships
     * @return Collection<array-key, ProgramParticipant>
     */
    public function completedMemberships(Collection $memberships): Collection
    {
        if ($memberships->isEmpty()) {
            return $memberships;
        }

        $weeks = TrainingWeek::query()
            ->whereIn('program_id', $memberships->pluck('program_id')->unique()->values())
            ->where('is_active', true)
            ->get();
        $gatesByWeek = $this->reviewedGatesByWeek($memberships->pluck('user_id'), $weeks);

        return $memberships->filter(function (ProgramParticipant $membership) use ($weeks, $gatesByWeek): bool {
            $programWeeks = $this->programWeeks((int) $membership->program_id, $weeks);

            return $programWeeks->isNotEmpty() && $programWeeks->every(fn (TrainingWeek $week): bool => $this->isPassed(
                $gatesByWeek->get($week->id, collect()),
                (int) $membership->user_id,
            ));
        });
    }

    /**
     * Refuse a participant a week of the curriculum they cannot view (not open, and the curriculum has not ended), with
     * the message its tab shows (when it opens, that it is not scheduled yet, or which week locks it).
     *
     * @throws AuthorizationException
     */
    public function ensureCanAccessWeek(User $user, Program $program, TrainingWeek $week): void
    {
        if ($user->isCoachOrAdmin()) {
            return;
        }

        $state = $user->role === UserRole::Participant ? $this->weekStates($user, $program)->get($week->id) : null;
        if ($state?->isViewable()) {
            return;
        }

        throw new AuthorizationException($state?->lockedMessage() ?? 'This training week is not available to your account.');
    }

    public function ensureCanAccessActivity(User $user, Program $program, TrainingWeek $week, Activity $activity): void
    {
        abort_unless($activity->training_week_id === $week->id, 404);
        $this->ensureCanAccessWeek($user, $program, $week);
    }

    /**
     * Whether the week is the participant's current week in the curriculum (see currentWeekId()).
     */
    public function isCurrentWeek(User $user, Program $program, TrainingWeek $week): bool
    {
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        return $this->currentWeekId($this->weekStates($user, $program)) === $week->id;
    }

    /**
     * Whether the participant must check in before working on the activity: in their current week, without an open
     * check-in, while their latest attempt at it is missing, a draft or needs revision.
     */
    public function requiresAttendanceForActivity(User $user, Program $program, TrainingWeek $week, Activity $activity): bool
    {
        if ($user->role !== UserRole::Participant || ! $this->isCurrentWeek($user, $program, $week) || $user->attendanceSessions()->whereNull('check_out_at')->exists()) {
            return false;
        }

        $submission = $this->latestAttempt($user, $activity);

        return ! $submission || $submission->isDraft() || $submission->needsRevision();
    }

    /**
     * Whether the participant must check in before starting the activity: in their current week, without an open
     * check-in, while they have no attempt at it.
     */
    public function requiresAttendanceForNewActivity(User $user, Program $program, TrainingWeek $week, Activity $activity): bool
    {
        return $this->isCurrentWeek($user, $program, $week)
            && ! $user->attendanceSessions()->whereNull('check_out_at')->exists()
            && ! $activity->submissions()->where('user_id', $user->id)->exists();
    }

    /**
     * Refuse the participant work on the activity: once the curriculum has ended, unless their latest attempt at it
     * was reopened (ensureProgramAcceptsWork()), and while they must check in first.
     *
     * @throws AuthorizationException
     */
    public function ensureCanEditActivity(User $user, Program $program, TrainingWeek $week, Activity $activity): void
    {
        $this->ensureProgramAcceptsWork($user, $program, $activity);
        if ($this->requiresAttendanceForActivity($user, $program, $week, $activity)) {
            abort(403, 'Please check in to continue editing this activity.');
        }
    }

    /**
     * Refuse the participant's write with "This curriculum has ended." once the curriculum has ended
     * (`Program::acceptsWork()`): with an activity, unless their latest attempt at it was reopened by a mentor or
     * admin; without one (the weekly video), always.
     *
     * @throws AuthorizationException
     */
    public function ensureProgramAcceptsWork(User $user, Program $program, ?Activity $activity = null): void
    {
        if (! $program->isCompleted()) {
            return;
        }

        $attempt = $activity === null ? null : $this->latestAttempt($user, $activity);

        if (! $program->acceptsWork($attempt)) {
            throw new AuthorizationException(Program::ENDED_MESSAGE);
        }
    }

    /**
     * The participant's latest attempt at the activity, if any.
     */
    private function latestAttempt(User $user, Activity $activity): ?ActivitySubmission
    {
        return $activity->submissions()
            ->where('user_id', $user->id)
            ->latest('attempt_number')
            ->first();
    }

    /**
     * The state of each of the curriculum's active weeks among the given weeks for one participant, keyed by week id in
     * week order. Every week after the week of the locking review is locked, and awaits verification when the review
     * has new work since it.
     *
     * @param  Collection<int, TrainingWeek>  $weeks  with their dates
     * @param  Collection<int, Collection<int, TrainingGate>>  $gatesByWeek  see reviewedGatesByWeek()
     * @param  ?GateEvaluation  $lockingReview  see lockingReview()
     * @param  Collection<int, CarbonInterface>  $latestWork  see latestWorkSinceReviews()
     * @return Collection<int, ProgramWeekState>
     */
    private function programWeekStates(int $programId, bool $programCompleted, int $userId, Collection $weeks, Collection $gatesByWeek, ?GateEvaluation $lockingReview, Collection $latestWork): Collection
    {
        $lockingWeekNumber = $lockingReview?->gate->week->week_number;
        $awaitingVerification = $lockingReview !== null && $latestWork->has($lockingReview->id);
        $states = collect();

        foreach ($this->programWeeks($programId, $weeks) as $week) {
            $isLocked = $lockingWeekNumber !== null && $week->week_number > $lockingWeekNumber;
            $access = match (true) {
                $isLocked => WeekAccessState::Locked,
                $week->isOpen() => WeekAccessState::Open,
                default => WeekAccessState::NotOpen,
            };
            $states->put($week->id, new ProgramWeekState(
                weekNumber: $week->week_number,
                access: $access,
                opensOn: $week->starts_on,
                lockedByWeekNumber: $isLocked ? $lockingWeekNumber : null,
                passed: $this->isPassed($gatesByWeek->get($week->id, collect()), $userId),
                lockingReviewId: $isLocked ? $lockingReview->id : null,
                awaitingVerification: $isLocked && $awaitingVerification,
                programCompleted: $programCompleted,
            ));
        }

        return $states;
    }

    /**
     * The participant's incomplete review that locks the later weeks of the curriculum: walking its active weeks in
     * order, the first incomplete review of an active Gate, with its Gate and the Gate's week set. Null when no week
     * has one.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @param  Collection<int, Collection<int, TrainingGate>>  $gatesByWeek  see reviewedGatesByWeek()
     */
    private function lockingReview(int $programId, int $userId, Collection $weeks, Collection $gatesByWeek): ?GateEvaluation
    {
        foreach ($this->programWeeks($programId, $weeks) as $week) {
            foreach ($gatesByWeek->get($week->id, collect())->where('is_active', true) as $gate) {
                $review = $gate->evaluations->first(fn (GateEvaluation $evaluation): bool => (int) $evaluation->user_id === $userId
                    && in_array($evaluation->status, GateEvaluationStatus::incompleteReviews(), true));

                if ($review) {
                    return $review->setRelation('gate', $gate->setRelation('week', $week));
                }
            }
        }

        return null;
    }

    /**
     * The locking review of each membership (lockingReview()), keyed like the memberships.
     *
     * @param  Collection<array-key, ProgramParticipant>  $memberships
     * @param  Collection<int, TrainingWeek>  $weeks
     * @param  Collection<int, Collection<int, TrainingGate>>  $gatesByWeek  see reviewedGatesByWeek()
     * @return Collection<array-key, ?GateEvaluation>
     */
    private function lockingReviewsOf(Collection $memberships, Collection $weeks, Collection $gatesByWeek): Collection
    {
        return $memberships->map(fn (ProgramParticipant $membership): ?GateEvaluation => $this->lockingReview(
            (int) $membership->program_id,
            (int) $membership->user_id,
            $weeks,
            $gatesByWeek,
        ));
    }

    /**
     * Whether each membership's curriculum has ended, keyed by program id: one status read per curriculum.
     *
     * @param  Collection<array-key, ProgramParticipant>  $memberships  with their program loaded
     * @return Collection<int, bool>
     */
    private function completedByProgram(Collection $memberships): Collection
    {
        return $memberships
            ->map(fn (ProgramParticipant $membership): Program => $membership->program)
            ->unique('id')
            ->mapWithKeys(fn (Program $program): array => [$program->id => $program->isCompleted()]);
    }

    /**
     * The curriculum's active weeks among the given weeks, in week order.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, TrainingWeek>
     */
    private function programWeeks(int $programId, Collection $weeks): Collection
    {
        return $weeks
            ->filter(fn (TrainingWeek $week): bool => (int) $week->program_id === $programId && $week->is_active)
            ->sortBy('week_number');
    }

    /**
     * The key of a participant's work in one week (see latestWorkSinceReviews()).
     */
    private function workKey(int $userId, int $trainingWeekId): string
    {
        return "{$userId}-{$trainingWeekId}";
    }

    /**
     * The Gates of the given weeks, grouped by week id, each with its completed and incomplete reviews by the given
     * users: two queries.
     *
     * @param  Collection<array-key, int>  $userIds
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, Collection<int, TrainingGate>>
     */
    private function reviewedGatesByWeek(Collection $userIds, Collection $weeks): Collection
    {
        if ($weeks->isEmpty() || $userIds->isEmpty()) {
            return collect();
        }

        return TrainingGate::query()
            ->select(['id', 'training_week_id', 'is_active'])
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->with(['evaluations' => fn (Relation $query): Relation => $query
                ->select(['id', 'training_gate_id', 'user_id', 'status', 'evaluated_at'])
                ->whereIn('user_id', $userIds->unique()->values())
                ->whereIn('status', [GateEvaluationStatus::Passed, ...GateEvaluationStatus::incompleteReviews()])])
            ->get()
            ->groupBy('training_week_id');
    }

    /**
     * Whether the week's Gates (at least one) each have a completed review by the user.
     *
     * @param  Collection<int, TrainingGate>  $gates  with their reviews loaded (see reviewedGatesByWeek())
     */
    private function isPassed(Collection $gates, int $userId): bool
    {
        return $gates->isNotEmpty() && $gates->every(fn (TrainingGate $gate): bool => $gate->evaluations->contains(
            fn (GateEvaluation $evaluation): bool => (int) $evaluation->user_id === $userId
                && $evaluation->status === GateEvaluationStatus::Passed,
        ));
    }

    /**
     * Ids of the given weeks that have at least one Gate and a passed evaluation by the user for every Gate.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, int>
     */
    private function passedWeekIds(User $user, Collection $weeks): Collection
    {
        if ($weeks->isEmpty()) {
            return collect();
        }

        return TrainingGate::query()
            ->select(['id', 'training_week_id'])
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->withExists(['evaluations as passed_by_user' => fn (Builder $query): Builder => $query
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
