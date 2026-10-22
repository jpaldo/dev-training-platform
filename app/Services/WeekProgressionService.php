<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class WeekProgressionService
{
    public function isWeekPassed(User $user, TrainingWeek $week): bool
    {
        return $this->passedWeekIds($user, collect([$week]))->contains($week->id);
    }

    public function canAccessWeek(User $user, TrainingWeek $week): bool
    {
        if ($user->isCoachOrAdmin()) {
            return true;
        }
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        $priorWeeks = $week->program->weeks()->where('position', '<', $week->position)->orderBy('position')->get();

        return $this->allWeeksPassed($priorWeeks, $this->passedWeekIds($user, $priorWeeks));
    }

    /**
     * Resolve the pass and access state of every week of one program with a fixed number of queries.
     *
     * @param  Collection<int, TrainingWeek>  $programWeeks  every week of the program, active or not
     * @return Collection<int, array{passed: bool, accessible: bool}> keyed by week id
     */
    public function weekStates(User $user, Collection $programWeeks): Collection
    {
        $passedWeekIds = $this->passedWeekIds($user, $programWeeks);

        return $programWeeks->mapWithKeys(fn (TrainingWeek $week): array => [$week->id => [
            'passed' => $passedWeekIds->contains($week->id),
            'accessible' => match (true) {
                $user->isCoachOrAdmin() => true,
                $user->role !== UserRole::Participant => false,
                default => $this->allWeeksPassed($programWeeks->where('position', '<', $week->position), $passedWeekIds),
            },
        ]]);
    }

    public function isProgramCompleted(User $user, Program $program): bool
    {
        $weeks = $program->weeks()->where('is_active', true)->orderBy('position')->get();

        return $weeks->isNotEmpty() && $this->allWeeksPassed($weeks, $this->passedWeekIds($user, $weeks));
    }

    /**
     * Ids of the given participants who completed the program, resolved with a fixed number of queries.
     *
     * @param  Collection<int, User>  $participants
     * @return Collection<int, int>
     */
    public function completedParticipantIds(Collection $participants, Program $program): Collection
    {
        $weeks = $program->weeks()->where('is_active', true)->orderBy('position')->get();
        if ($weeks->isEmpty() || $participants->isEmpty()) {
            return collect();
        }

        $gatesByWeek = TrainingGate::query()
            ->select(['id', 'training_week_id'])
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->with(['evaluations' => fn (Relation $query): Relation => $query->select(['id', 'training_gate_id', 'user_id'])->whereIn('user_id', $participants->pluck('id'))->where('status', GateEvaluationStatus::Passed)])
            ->get()
            ->groupBy('training_week_id');

        return $participants
            ->filter(function (User $participant) use ($weeks, $gatesByWeek): bool {
                $passedWeekIds = $gatesByWeek
                    ->filter(fn (Collection $gates): bool => $gates->every(fn (TrainingGate $gate): bool => $gate->evaluations->contains('user_id', $participant->id)))
                    ->keys();

                return $this->allWeeksPassed($weeks, $passedWeekIds);
            })
            ->pluck('id')
            ->values();
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
            ->withExists(['evaluations as passed_by_user' => fn (Builder $query): Builder => $query->where('user_id', $user->id)->where('status', GateEvaluationStatus::Passed)])
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

    public function ensureCanAccessWeek(User $user, TrainingWeek $week): void
    {
        if ($this->canAccessWeek($user, $week)) {
            return;
        }
        $prior = $week->program->weeks()->where('position', '<', $week->position)->orderByDesc('position')->first();
        throw new AuthorizationException($prior ? "Complete Week {$prior->week_number} before proceeding to Week {$week->week_number}." : 'This training week is not available to your account.');
    }

    public function ensureCanAccessActivity(User $user, TrainingWeek $week, Activity $activity): void
    {
        abort_unless($activity->training_week_id === $week->id, 404);
        $this->ensureCanAccessWeek($user, $week);
        $this->ensurePriorWeekThreeActivities($user, $week, $activity);
    }

    private function ensurePriorWeekThreeActivities(User $user, TrainingWeek $week, Activity $activity): void
    {
        if ($user->role !== UserRole::Participant || $week->week_number !== 3 || $activity->position <= 1) {
            return;
        }

        $priorActivities = $week->activities()->where('position', '<', $activity->position)->orderBy('position')->get();
        foreach ($priorActivities as $prior) {
            $completed = $prior->submissions()->where('user_id', $user->id)->whereIn('status', ['submitted', 'resubmitted', 'approved', 'completed', 'under_review'])->exists();
            if (! $completed) {
                throw new AuthorizationException("Complete and submit Activity {$prior->position} before starting Activity {$activity->position}.");
            }
        }
    }

    public function isCurrentWeek(User $user, TrainingWeek $week): bool
    {
        if ($user->role !== UserRole::Participant) {
            return false;
        }

        $currentWeek = $week->program->weeks()
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->first(fn (TrainingWeek $candidate): bool => ! $this->isWeekPassed($user, $candidate) && $this->canAccessWeek($user, $candidate));

        return $currentWeek?->is($week) ?? false;
    }

    public function requiresAttendanceForActivity(User $user, TrainingWeek $week, Activity $activity): bool
    {
        if ($user->role !== UserRole::Participant || ! $this->isCurrentWeek($user, $week) || $user->attendanceSessions()->whereNull('check_out_at')->exists()) {
            return false;
        }

        $submission = $activity->submissions()->where('user_id', $user->id)->latest('attempt_number')->first();

        return ! $submission || $submission->isDraft() || $submission->needsRevision();
    }

    public function requiresAttendanceForNewActivity(User $user, TrainingWeek $week, Activity $activity): bool
    {
        return $this->isCurrentWeek($user, $week)
            && ! $user->attendanceSessions()->whereNull('check_out_at')->exists()
            && ! $activity->submissions()->where('user_id', $user->id)->exists();
    }

    public function ensureCanEditActivity(User $user, TrainingWeek $week, Activity $activity): void
    {
        if ($this->requiresAttendanceForActivity($user, $week, $activity)) {
            abort(403, 'Please check in to continue editing this activity.');
        }
    }
}
