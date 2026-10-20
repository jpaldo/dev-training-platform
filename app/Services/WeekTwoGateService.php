<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class WeekTwoGateService
{
    private const SUBMITTED_STATUSES = ['submitted', 'resubmitted', 'approved', 'completed'];

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
        if ($gates->isEmpty()) {
            return collect();
        }

        $weekIds = $gates->pluck('training_week_id')->unique()->values()->all();
        $weeks = TrainingWeek::query()->findOrFail($weekIds)->keyBy('id');
        $dailyLogCounts = $this->dailyLogCountsByWeek($participant, $weekIds);
        $activitiesByWeek = Activity::query()
            ->whereIn('training_week_id', $weekIds)
            ->where('is_active', true)
            ->orderBy('position')
            ->with(['requirements' => fn (Relation $query): Relation => $query->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')])
            ->get()
            ->groupBy('training_week_id');
        $latestSubmissions = $participant->activitySubmissions()
            ->whereIn('activity_id', $activitiesByWeek->collapse()->pluck('id'))
            ->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', self::SUBMITTED_STATUSES)])
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('activity_id')
            ->keyBy('activity_id');
        $latestVersions = new EloquentCollection($latestSubmissions
            ->map(fn (ActivitySubmission $submission): ?SubmissionVersion => $submission->versions->sortByDesc('version_number')->first())
            ->filter());
        $latestVersions->load(['evidence' => fn (Relation $query): Relation => $query->select(['id', 'submission_version_id', 'evidence_requirement_id'])]);
        $videoCriteriaByGate = GateCriterion::query()
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->whereIn('criterion_type', ['weekly_video', 'weekly_loom'])
            ->get(['id', 'training_gate_id', 'criterion_type'])
            ->groupBy('training_gate_id');
        $weekIdsWithRecording = WeeklyVideoRecording::query()
            ->where('user_id', $participant->id)
            ->whereIn('training_week_id', $weekIds)
            ->pluck('training_week_id');

        return $gates->mapWithKeys(function (TrainingGate $gate) use ($participant, $weeks, $dailyLogCounts, $activitiesByWeek, $latestSubmissions, $latestVersions, $videoCriteriaByGate, $weekIdsWithRecording): array {
            $week = $weeks->get($gate->training_week_id);
            $reasons = [];
            $logs = $dailyLogCounts->get($week->id, 0);
            // Every active training week requires five participant daily logs.
            // The count is scoped through the week's activities, never globally.
            $minimumLogs = $this->minimumDailyLogs();
            if ($logs < $minimumLogs) {
                $remaining = $minimumLogs - $logs;
                $reasons['daily_logs'] = "Daily logs: {$logs} of {$minimumLogs} completed. Complete {$remaining} more Week {$week->week_number} daily log".($remaining === 1 ? '' : 's').' before this Gate can be passed.';
            }
            foreach ($activitiesByWeek->get($week->id, collect()) as $activity) {
                $submission = $latestSubmissions->get($activity->id);
                if (! $submission || ! in_array($submission->status, self::SUBMITTED_STATUSES, true)) {
                    $reasons['activity_'.$activity->position] = "Activity {$activity->position} must have a submitted version.";

                    continue;
                }
                $version = $latestVersions->get($activity->id);
                foreach ($activity->requirements as $requirement) {
                    foreach ($requirement->evidenceRequirements as $evidence) {
                        if ($evidence->is_required && (! $version || $version->evidence->where('evidence_requirement_id', $evidence->id)->count() < $evidence->minimum_count)) {
                            $reasons['evidence_'.$activity->position] = "Required evidence for Activity {$activity->position} is missing.";
                        }
                    }
                }
            }

            $videoCriteria = $videoCriteriaByGate->get($gate->id);
            $weeklyVideoMissing = match (true) {
                $videoCriteria === null => true,
                $weekIdsWithRecording->contains($week->id) => false,
                $videoCriteria->contains('criterion_type', 'weekly_loom') => $this->weeklyVideo($participant, $gate)['status'] === 'missing',
                default => true,
            };
            if ($weeklyVideoMissing) {
                $reasons['weekly_video'] = 'Required Weekly Video Recording is missing. Upload the video before this Gate can be passed.';
            }

            return [$gate->id => $reasons];
        });
    }

    public function minimumDailyLogs(): int
    {
        return (int) config('training.daily_logs_per_week', 5);
    }

    /**
     * Count the participant's daily logs per training week, scoped through each week's activities.
     *
     * @param  array<int, int>  $weekIds
     * @return Collection<int, int> daily log counts keyed by week id; weeks without activities are absent
     */
    public function dailyLogCountsByWeek(User $participant, array $weekIds): Collection
    {
        return Activity::query()
            ->select(['id', 'training_week_id'])
            ->whereIn('training_week_id', $weekIds)
            ->withCount(['dailyLogs' => fn (Builder $query): Builder => $query->where('user_id', $participant->id)])
            ->get()
            ->groupBy('training_week_id')
            ->map(fn (Collection $activities): int => (int) $activities->sum('daily_logs_count'));
    }

    /** @return array{status:string,url:?string,activity:?object,criterion:?object,recording:?WeeklyVideoRecording} */
    public function weeklyLoom(User $participant, TrainingGate $gate): array
    {
        return $this->weeklyVideo($participant, $gate);
    }

    public function weeklyVideo(User $participant, TrainingGate $gate): array
    {
        $criterion = $gate->criteria()->whereIn('criterion_type', ['weekly_video', 'weekly_loom'])->orderByDesc('criterion_type')->first();
        if (! $criterion) {
            return ['status' => 'missing', 'url' => null, 'activity' => null, 'criterion' => null, 'recording' => null];
        }

        $week = $gate->week()->firstOrFail();
        $recording = WeeklyVideoRecording::query()->where('user_id', $participant->id)->where('training_week_id', $week->id)->latest('uploaded_at')->first();
        $legacyUrl = null;
        if (! $recording && $gate->criteria()->where('criterion_type', 'weekly_loom')->exists()) {
            $activity = $week->activities()->where('position', 3)->first();
            $legacyRequirement = $activity?->requirements()->where('requirement_type', 'gate_evidence')->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->first();
            $submission = $activity ? $participant->activitySubmissions()->where('activity_id', $activity->id)->latest('attempt_number')->first() : null;
            $version = $submission?->versions()->whereIn('status', ['submitted', 'resubmitted', 'approved', 'completed'])->latest('version_number')->first();
            $legacyUrl = $version && $legacyRequirement ? $version->evidence()->where('evidence_requirement_id', $legacyRequirement->id)->latest()->value('url') : null;
        }
        $evaluation = $gate->evaluations()->where('user_id', $participant->id)->with('criterionEvaluations')->first();
        $criterionEvaluation = $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id);

        return [
            'status' => ! $recording && ! $legacyUrl ? 'missing' : ($criterionEvaluation?->status === 'met' ? 'met' : 'awaiting_review'),
            'url' => $recording ? route('training.video-recording.view', [$week, $recording]) : $legacyUrl,
            'activity' => null,
            'criterion' => $criterion,
            'recording' => $recording,
        ];
    }

    public function canPass(User $participant, TrainingGate $gate): bool
    {
        return $this->readiness($participant, $gate) === []
            && $gate->criteria()->where('is_required', true)->count() > 0;
    }
}
