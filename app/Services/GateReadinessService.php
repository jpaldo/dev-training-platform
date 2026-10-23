<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Resolves whether a participant has done the work a Gate checks in a training session, for any week of any program:
 * every active activity of the Gate's week has a handed-in latest attempt in the session, the latest handed-in version
 * of each has its required evidence, and, only when the Gate has a weekly video criterion, the participant has chosen
 * the week's Weekly Video Recording in the session. Work in another session never counts.
 */
class GateReadinessService
{
    /** @return array<string, string> */
    public function readiness(TrainingSession $trainingSession, User $participant, TrainingGate $gate): array
    {
        return $this->readinessForGates($trainingSession, $participant, collect([$gate]))->get($gate->id);
    }

    /**
     * Resolve the readiness reasons of several Gates for one participant in the session with a fixed number of queries.
     *
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<int, array<string, string>> readiness reasons keyed by Gate id
     */
    public function readinessForGates(TrainingSession $trainingSession, User $participant, Collection $gates): Collection
    {
        return $this->readinessForMemberships(collect([$this->membership($trainingSession, $participant)]), $gates)->first();
    }

    /**
     * Resolve the readiness reasons of several Gates for several session memberships with a fixed number of queries,
     * each from the participant's work in the membership's session: one `activity_{position}` reason per active
     * activity without a handed-in latest attempt, one `evidence_{position}` reason per activity whose latest handed-in
     * version lacks required evidence, and `weekly_video` when the Gate has a weekly video criterion and the week has no
     * recording.
     *
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<array-key, Collection<int, array<string, string>>> readiness reasons keyed like the memberships, then by Gate id
     */
    public function readinessForMemberships(Collection $memberships, Collection $gates): Collection
    {
        if ($gates->isEmpty() || $memberships->isEmpty()) {
            return $memberships->map(fn (): Collection => collect());
        }

        $weekIds = $gates->pluck('training_week_id')->unique()->values()->all();
        $activitiesByWeek = Activity::query()
            ->whereIn('training_week_id', $weekIds)
            ->where('is_active', true)
            ->orderBy('position')
            ->with(['requirements' => fn (Relation $query): Relation => $query->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')])
            ->get()
            ->groupBy('training_week_id');
        $latestSubmissions = $this->forMemberships(ActivitySubmission::query(), $memberships)
            ->whereIn('activity_id', $activitiesByWeek->collapse()->pluck('id'))
            ->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', SubmissionStatus::handedIn())])
            ->orderByDesc('attempt_number')
            ->get()
            ->groupBy(fn (ActivitySubmission $submission): string => $this->key($submission->training_session_id, $submission->user_id))
            ->map(fn (Collection $submissions): Collection => $submissions->unique('activity_id')->keyBy('activity_id'));
        $latestVersions = $latestSubmissions->map(fn (Collection $submissions): Collection => $submissions
            ->map(fn (ActivitySubmission $submission): ?SubmissionVersion => $submission->versions->sortByDesc('version_number')->first())
            ->filter());
        (new EloquentCollection($latestVersions->collapse()))->load(['evidence' => fn (Relation $query): Relation => $query->select(['id', 'submission_version_id', 'evidence_requirement_id'])]);
        $videoGateIds = GateCriterion::query()
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->weeklyVideo()
            ->distinct()
            ->pluck('training_gate_id');
        $recordedWeekIds = $this->forMemberships(WeeklyVideoRecording::query(), $memberships)
            ->whereIn('training_week_id', $weekIds)
            ->get(['training_session_id', 'user_id', 'training_week_id'])
            ->groupBy(fn (WeeklyVideoRecording $recording): string => $this->key($recording->training_session_id, $recording->user_id))
            ->map(fn (Collection $recordings): Collection => $recordings->pluck('training_week_id'));

        return $memberships->map(function (TrainingSessionParticipant $membership) use ($gates, $activitiesByWeek, $latestSubmissions, $latestVersions, $videoGateIds, $recordedWeekIds): Collection {
            $membershipKey = $this->key($membership->training_session_id, $membership->user_id);

            return $gates->mapWithKeys(function (TrainingGate $gate) use ($membershipKey, $activitiesByWeek, $latestSubmissions, $latestVersions, $videoGateIds, $recordedWeekIds): array {
                $reasons = [];
                foreach ($activitiesByWeek->get($gate->training_week_id, collect()) as $activity) {
                    $submission = $latestSubmissions->get($membershipKey)?->get($activity->id);
                    if (! $submission || ! $submission->isHandedIn()) {
                        $reasons['activity_'.$activity->position] = "Activity {$activity->position} must have a submitted version.";

                        continue;
                    }
                    $version = $latestVersions->get($membershipKey)?->get($activity->id);
                    foreach ($activity->requirements as $requirement) {
                        foreach ($requirement->evidenceRequirements as $evidence) {
                            if ($evidence->is_required && (! $version || $version->evidence->where('evidence_requirement_id', $evidence->id)->count() < $evidence->minimum_count)) {
                                $reasons['evidence_'.$activity->position] = "Required evidence for Activity {$activity->position} is missing.";
                            }
                        }
                    }
                }

                $isWeeklyVideoMissing = $videoGateIds->contains($gate->id)
                    && ! $recordedWeekIds->get($membershipKey, collect())->contains($gate->training_week_id);
                if ($isWeeklyVideoMissing) {
                    $reasons['weekly_video'] = 'Required Weekly Video Recording is missing. Choose your video from Google Drive before this week can be marked completed.';
                }

                return [$gate->id => $reasons];
            });
        });
    }

    /** @return array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording} */
    public function weeklyVideo(TrainingSession $trainingSession, User $participant, TrainingGate $gate): array
    {
        return $this->weeklyVideosForMemberships(collect([$this->membership($trainingSession, $participant)]), collect([$gate]))->first()->get($gate->id);
    }

    /**
     * Resolve the Weekly Video Recording state of several Gates for several session memberships with a fixed number of
     * queries, each from the participant's recording and Gate evaluation in the membership's session. The status is
     * `not_required` for a Gate without a weekly video criterion, otherwise `missing`, `awaiting_review` or `met` (the
     * Gate evaluation marks the criterion met).
     *
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<array-key, Collection<int, array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording}>> keyed like the memberships, then by Gate id
     */
    public function weeklyVideosForMemberships(Collection $memberships, Collection $gates): Collection
    {
        $videoCriteriaByGate = GateCriterion::query()
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->weeklyVideo()
            ->orderBy('id')
            ->get()
            ->groupBy('training_gate_id');
        $weekIds = $gates->filter(fn (TrainingGate $gate): bool => $videoCriteriaByGate->has($gate->id))->pluck('training_week_id')->unique()->values()->all();
        $hasVideoGates = $weekIds !== [] && $memberships->isNotEmpty();
        $recordings = ! $hasVideoGates ? collect() : $this->forMemberships(WeeklyVideoRecording::query(), $memberships)
            ->whereIn('training_week_id', $weekIds)
            ->get()
            ->keyBy(fn (WeeklyVideoRecording $recording): string => $this->key($recording->training_session_id, $recording->user_id).':'.$recording->training_week_id);
        $evaluations = ! $hasVideoGates ? collect() : $this->forMemberships(GateEvaluation::query(), $memberships)
            ->whereIn('training_gate_id', $videoCriteriaByGate->keys())
            ->with('criterionEvaluations')
            ->get()
            ->keyBy(fn (GateEvaluation $evaluation): string => $this->key($evaluation->training_session_id, $evaluation->user_id).':'.$evaluation->training_gate_id);

        return $memberships->map(function (TrainingSessionParticipant $membership) use ($gates, $videoCriteriaByGate, $recordings, $evaluations): Collection {
            $membershipKey = $this->key($membership->training_session_id, $membership->user_id);

            return $gates->mapWithKeys(function (TrainingGate $gate) use ($membershipKey, $videoCriteriaByGate, $recordings, $evaluations): array {
                $criterion = $videoCriteriaByGate->get($gate->id)?->first();
                if (! $criterion) {
                    return [$gate->id => ['status' => 'not_required', 'url' => null, 'criterion' => null, 'recording' => null]];
                }

                $recording = $recordings->get($membershipKey.':'.$gate->training_week_id);
                $criterionEvaluation = $evaluations->get($membershipKey.':'.$gate->id)?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id);

                return [$gate->id => [
                    'status' => match (true) {
                        ! $recording => 'missing',
                        $criterionEvaluation?->isMet() === true => 'met',
                        default => 'awaiting_review',
                    },
                    'url' => $recording?->driveViewUrl(),
                    'criterion' => $criterion,
                    'recording' => $recording,
                ]];
            });
        });
    }

    /**
     * The participant's membership of the session as an unsaved row, so one participant resolves through the membership
     * methods.
     */
    private function membership(TrainingSession $trainingSession, User $participant): TrainingSessionParticipant
    {
        return new TrainingSessionParticipant(['training_session_id' => $trainingSession->id, 'user_id' => $participant->id]);
    }

    /**
     * Limit a query on a table with `training_session_id` and `user_id` to the memberships' sessions and participants;
     * callers then match the exact pairs with key().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Collection<array-key, TrainingSessionParticipant>  $memberships
     * @return Builder<TModel>
     */
    private function forMemberships(Builder $query, Collection $memberships): Builder
    {
        return $query
            ->whereIn('training_session_id', $memberships->pluck('training_session_id')->unique()->values())
            ->whereIn('user_id', $memberships->pluck('user_id')->unique()->values());
    }

    /**
     * The lookup key of a participant in a session, e.g. "3:17".
     */
    private function key(int|string|null $trainingSessionId, int|string $userId): string
    {
        return $trainingSessionId.':'.$userId;
    }
}
