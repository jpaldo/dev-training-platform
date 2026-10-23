<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Week progression within a training session: a participant passes a week once every Gate of the week has a passed
 * evaluation in that session, and opens a week once every earlier week of the program is passed in that session (the
 * Gate chain, until 11.1). Work in another session never counts.
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

    public function canAccessWeek(User $user, TrainingSession $trainingSession, TrainingWeek $week): bool
    {
        if ($user->isCoachOrAdmin()) {
            return true;
        }
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        $priorWeeks = $week->program->weeks()->where('position', '<', $week->position)->orderBy('position')->get();

        return $this->allWeeksPassed($priorWeeks, $this->passedWeekIds($user, $trainingSession, $priorWeeks));
    }

    /**
     * Resolve the pass and access state of every week of one program for each session membership with a fixed number
     * of queries: a week is passed when the participant passed it in the membership's session, and accessible when
     * every earlier week of the program is passed in that session.
     *
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships  participants' memberships of sessions of the program
     * @param  Collection<int, TrainingWeek>  $programWeeks  every week of the program, active or not
     * @return Collection<array-key, Collection<int, array{passed: bool, accessible: bool}>> keyed like the memberships, then by week id
     */
    public function weekStatesForMemberships(Collection $memberships, Collection $programWeeks): Collection
    {
        $passedWeekIdsByMembership = $this->passedWeekIdsByMembership($memberships, $programWeeks);

        return $memberships->map(function (TrainingSessionParticipant $membership, int|string $key) use ($programWeeks, $passedWeekIdsByMembership): Collection {
            $passedWeekIds = $passedWeekIdsByMembership->get($key);

            return $programWeeks->mapWithKeys(fn (TrainingWeek $week): array => [$week->id => [
                'passed' => $passedWeekIds->contains($week->id),
                'accessible' => $this->allWeeksPassed($programWeeks->where('position', '<', $week->position), $passedWeekIds),
            ]]);
        });
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
        $passedWeekIdsByMembership = $this->passedWeekIdsByMembership($memberships, $weeks);

        return $memberships->filter(function (TrainingSessionParticipant $membership, int|string $key) use ($weeks, $passedWeekIdsByMembership): bool {
            $sessionWeeks = $weeks->filter(fn (TrainingWeek $week): bool => $membership->trainingSession->includesWeek($week));

            return $sessionWeeks->isNotEmpty() && $this->allWeeksPassed($sessionWeeks, $passedWeekIdsByMembership->get($key));
        });
    }

    /**
     * Per membership, the ids of the given weeks that have at least one Gate and a passed evaluation by the
     * membership's participant in its session for every Gate, resolved with a fixed number of queries.
     *
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<array-key, Collection<int, int>> keyed like the memberships
     */
    private function passedWeekIdsByMembership(Collection $memberships, Collection $weeks): Collection
    {
        if ($weeks->isEmpty() || $memberships->isEmpty()) {
            return $memberships->map(fn (): Collection => collect());
        }

        $gatesByWeek = TrainingGate::query()
            ->select(['id', 'training_week_id'])
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->with(['evaluations' => fn (Relation $query): Relation => $query
                ->select(['id', 'training_gate_id', 'training_session_id', 'user_id'])
                ->whereIn('training_session_id', $memberships->pluck('training_session_id')->unique()->values())
                ->whereIn('user_id', $memberships->pluck('user_id')->unique()->values())
                ->where('status', GateEvaluationStatus::Passed)])
            ->get()
            ->groupBy('training_week_id');

        return $memberships->map(fn (TrainingSessionParticipant $membership): Collection => $gatesByWeek
            ->filter(fn (Collection $gates): bool => $gates->every(fn (TrainingGate $gate): bool => $gate->evaluations->contains(
                fn (GateEvaluation $evaluation): bool => (int) $evaluation->training_session_id === (int) $membership->training_session_id
                    && (int) $evaluation->user_id === (int) $membership->user_id,
            )))
            ->keys());
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

    public function ensureCanAccessWeek(User $user, TrainingSession $trainingSession, TrainingWeek $week): void
    {
        if ($this->canAccessWeek($user, $trainingSession, $week)) {
            return;
        }
        $prior = $week->program->weeks()->where('position', '<', $week->position)->orderByDesc('position')->first();
        throw new AuthorizationException($prior ? "Complete Week {$prior->week_number} before proceeding to Week {$week->week_number}." : 'This training week is not available to your account.');
    }

    public function ensureCanAccessActivity(User $user, TrainingSession $trainingSession, TrainingWeek $week, Activity $activity): void
    {
        abort_unless($activity->training_week_id === $week->id, 404);
        $this->ensureCanAccessWeek($user, $trainingSession, $week);
    }

    /**
     * Whether the week is the participant's current week in the session: the first of the session's weeks they have
     * not passed there and can open.
     */
    public function isCurrentWeek(User $user, TrainingSession $trainingSession, TrainingWeek $week): bool
    {
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        $programWeeks = $week->program->weeks()->get();
        $passedWeekIds = $this->passedWeekIds($user, $trainingSession, $programWeeks);
        $currentWeek = $programWeeks
            ->filter(fn (TrainingWeek $candidate): bool => $trainingSession->includesWeek($candidate))
            ->sortBy('position')
            ->first(fn (TrainingWeek $candidate): bool => ! $passedWeekIds->contains($candidate->id)
                && $this->allWeeksPassed($programWeeks->where('position', '<', $candidate->position), $passedWeekIds));

        return $currentWeek?->is($week) ?? false;
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
}
