<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Resolves whether a participant has done the work a Gate checks, for any week of any program: every active activity
 * of the Gate's week has a handed-in latest attempt, the latest handed-in version of each has its required evidence,
 * and, only when the Gate has a weekly video criterion, the participant has chosen the week's Weekly Video Recording.
 */
class GateReadinessService
{
    /** @return array<string, string> */
    public function readiness(User $participant, TrainingGate $gate): array
    {
        return $this->readinessForGates($participant, collect([$gate]))->get($gate->id);
    }

    /**
     * Resolve the readiness reasons of several Gates for one participant with a fixed number of queries.
     *
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<int, array<string, string>> readiness reasons keyed by Gate id
     */
    public function readinessForGates(User $participant, Collection $gates): Collection
    {
        return $this->readinessForParticipants(collect([$participant]), $gates)->get($participant->id);
    }

    /**
     * Resolve the readiness reasons of several Gates for several participants with a fixed number of queries: one
     * `activity_{position}` reason per active activity without a handed-in latest attempt, one `evidence_{position}`
     * reason per activity whose latest handed-in version lacks required evidence, and `weekly_video` when the Gate has
     * a weekly video criterion and the week has no recording.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<int, Collection<int, array<string, string>>> readiness reasons keyed by participant id, then Gate id
     */
    public function readinessForParticipants(Collection $participants, Collection $gates): Collection
    {
        if ($gates->isEmpty()) {
            return $participants->mapWithKeys(fn (User $participant): array => [$participant->id => collect()]);
        }

        $participantIds = $participants->pluck('id')->all();
        $weekIds = $gates->pluck('training_week_id')->unique()->values()->all();
        $activitiesByWeek = Activity::query()
            ->whereIn('training_week_id', $weekIds)
            ->where('is_active', true)
            ->orderBy('position')
            ->with(['requirements' => fn (Relation $query): Relation => $query->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')])
            ->get()
            ->groupBy('training_week_id');
        $latestSubmissions = ActivitySubmission::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('activity_id', $activitiesByWeek->collapse()->pluck('id'))
            ->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', SubmissionStatus::handedIn())])
            ->orderByDesc('attempt_number')
            ->get()
            ->groupBy('user_id')
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
        $recordedWeekIds = WeeklyVideoRecording::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('training_week_id', $weekIds)
            ->get(['user_id', 'training_week_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $recordings): Collection => $recordings->pluck('training_week_id'));

        return $participants->mapWithKeys(fn (User $participant): array => [$participant->id => $gates->mapWithKeys(function (TrainingGate $gate) use ($participant, $activitiesByWeek, $latestSubmissions, $latestVersions, $videoGateIds, $recordedWeekIds): array {
            $reasons = [];
            foreach ($activitiesByWeek->get($gate->training_week_id, collect()) as $activity) {
                $submission = $latestSubmissions->get($participant->id)?->get($activity->id);
                if (! $submission || ! $submission->isHandedIn()) {
                    $reasons['activity_'.$activity->position] = "Activity {$activity->position} must have a submitted version.";

                    continue;
                }
                $version = $latestVersions->get($participant->id)?->get($activity->id);
                foreach ($activity->requirements as $requirement) {
                    foreach ($requirement->evidenceRequirements as $evidence) {
                        if ($evidence->is_required && (! $version || $version->evidence->where('evidence_requirement_id', $evidence->id)->count() < $evidence->minimum_count)) {
                            $reasons['evidence_'.$activity->position] = "Required evidence for Activity {$activity->position} is missing.";
                        }
                    }
                }
            }

            $isWeeklyVideoMissing = $videoGateIds->contains($gate->id)
                && ! $recordedWeekIds->get($participant->id, collect())->contains($gate->training_week_id);
            if ($isWeeklyVideoMissing) {
                $reasons['weekly_video'] = 'Required Weekly Video Recording is missing. Choose your video from Google Drive before this week can be marked completed.';
            }

            return [$gate->id => $reasons];
        })]);
    }

    /** @return array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording} */
    public function weeklyVideo(User $participant, TrainingGate $gate): array
    {
        return $this->weeklyVideosForParticipants(collect([$participant]), collect([$gate]))->get($participant->id)->get($gate->id);
    }

    /**
     * Resolve the Weekly Video Recording state of several Gates for several participants with a fixed number of queries.
     * The status is `not_required` for a Gate without a weekly video criterion, otherwise `missing`, `awaiting_review`
     * or `met` (the Gate evaluation marks the criterion met).
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<int, Collection<int, array{status: string, url: ?string, criterion: ?GateCriterion, recording: ?WeeklyVideoRecording}>> keyed by participant id, then Gate id
     */
    public function weeklyVideosForParticipants(Collection $participants, Collection $gates): Collection
    {
        $participantIds = $participants->pluck('id')->all();
        $videoCriteriaByGate = GateCriterion::query()
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->weeklyVideo()
            ->orderBy('id')
            ->get()
            ->groupBy('training_gate_id');
        $weekIds = $gates->filter(fn (TrainingGate $gate): bool => $videoCriteriaByGate->has($gate->id))->pluck('training_week_id')->unique()->values()->all();
        $recordings = $weekIds === [] ? collect() : WeeklyVideoRecording::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('training_week_id', $weekIds)
            ->latest('chosen_at')
            ->get()
            ->unique(fn (WeeklyVideoRecording $recording): string => $recording->user_id.':'.$recording->training_week_id)
            ->keyBy(fn (WeeklyVideoRecording $recording): string => $recording->user_id.':'.$recording->training_week_id);
        $evaluations = $weekIds === [] ? collect() : GateEvaluation::query()
            ->whereIn('training_gate_id', $videoCriteriaByGate->keys())
            ->whereIn('user_id', $participantIds)
            ->with('criterionEvaluations')
            ->get()
            ->keyBy(fn (GateEvaluation $evaluation): string => $evaluation->user_id.':'.$evaluation->training_gate_id);

        return $participants->mapWithKeys(fn (User $participant): array => [$participant->id => $gates->mapWithKeys(function (TrainingGate $gate) use ($participant, $videoCriteriaByGate, $recordings, $evaluations): array {
            $criterion = $videoCriteriaByGate->get($gate->id)?->first();
            if (! $criterion) {
                return [$gate->id => ['status' => 'not_required', 'url' => null, 'criterion' => null, 'recording' => null]];
            }

            $recording = $recordings->get($participant->id.':'.$gate->training_week_id);
            $criterionEvaluation = $evaluations->get($participant->id.':'.$gate->id)?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id);

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
        })]);
    }
}
