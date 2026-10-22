<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
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
        return $this->readinessForParticipants(collect([$participant]), $gates)->get($participant->id);
    }

    /**
     * Resolve the readiness reasons of several Gates for several participants with a fixed number of queries.
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
        $weeks = TrainingWeek::query()->findOrFail($weekIds)->keyBy('id');
        $dailyLogCounts = $this->dailyLogCountsByParticipantAndWeek($participantIds, $weekIds);
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
            ->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', self::SUBMITTED_STATUSES)])
            ->orderByDesc('attempt_number')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $submissions): Collection => $submissions->unique('activity_id')->keyBy('activity_id'));
        $latestVersions = $latestSubmissions->map(fn (Collection $submissions): Collection => $submissions
            ->map(fn (ActivitySubmission $submission): ?SubmissionVersion => $submission->versions->sortByDesc('version_number')->first())
            ->filter());
        (new EloquentCollection($latestVersions->collapse()))->load(['evidence' => fn (Relation $query): Relation => $query->select(['id', 'submission_version_id', 'evidence_requirement_id'])]);
        $videoCriteriaByGate = GateCriterion::query()
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->whereIn('criterion_type', ['weekly_video', 'weekly_loom'])
            ->get(['id', 'training_gate_id', 'criterion_type'])
            ->groupBy('training_gate_id');
        $recordedWeekIds = WeeklyVideoRecording::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('training_week_id', $weekIds)
            ->get(['user_id', 'training_week_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $recordings): Collection => $recordings->pluck('training_week_id'));
        $legacyLoomUrls = $this->legacyWeeklyLoomUrls($participantIds, $this->weeklyLoomWeekIds($gates, $videoCriteriaByGate));

        return $participants->mapWithKeys(fn (User $participant): array => [$participant->id => $gates->mapWithKeys(function (TrainingGate $gate) use ($participant, $weeks, $dailyLogCounts, $activitiesByWeek, $latestSubmissions, $latestVersions, $videoCriteriaByGate, $recordedWeekIds, $legacyLoomUrls): array {
            $week = $weeks->get($gate->training_week_id);
            $reasons = [];
            $logs = $dailyLogCounts->get($participant->id, collect())->get($week->id, 0);
            // Every active training week requires five participant daily logs.
            // The count is scoped through the week's activities, never globally.
            $minimumLogs = $this->minimumDailyLogs();
            if ($logs < $minimumLogs) {
                $remaining = $minimumLogs - $logs;
                $reasons['daily_logs'] = "Daily logs: {$logs} of {$minimumLogs} completed. Complete {$remaining} more Week {$week->week_number} daily log".($remaining === 1 ? '' : 's').' before this Gate can be passed.';
            }
            foreach ($activitiesByWeek->get($week->id, collect()) as $activity) {
                $submission = $latestSubmissions->get($participant->id)?->get($activity->id);
                if (! $submission || ! in_array($submission->status, self::SUBMITTED_STATUSES, true)) {
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

            $videoCriteria = $videoCriteriaByGate->get($gate->id);
            $weeklyVideoMissing = match (true) {
                $videoCriteria === null => true,
                $recordedWeekIds->get($participant->id, collect())->contains($week->id) => false,
                $videoCriteria->contains('criterion_type', 'weekly_loom') => ! $legacyLoomUrls->get($participant->id)?->get($week->id),
                default => true,
            };
            if ($weeklyVideoMissing) {
                $reasons['weekly_video'] = 'Required Weekly Video Recording is missing. Upload the video before this Gate can be passed.';
            }

            return [$gate->id => $reasons];
        })]);
    }

    public function minimumDailyLogs(): int
    {
        return (int) config('training.daily_logs_per_week', 5);
    }

    /**
     * Count the participant's daily logs per training week, scoped through each week's activities.
     *
     * @param  array<int, int>  $weekIds
     * @return Collection<int, int> daily log counts keyed by week id; weeks without logs are absent
     */
    public function dailyLogCountsByWeek(User $participant, array $weekIds): Collection
    {
        return $this->dailyLogCountsByParticipantAndWeek([$participant->id], $weekIds)->get($participant->id, collect());
    }

    /**
     * Count the daily logs of several participants per training week, scoped through each week's activities.
     *
     * @param  array<int, int>  $participantIds
     * @param  array<int, int>  $weekIds
     * @return Collection<int, Collection<int, int>> daily log counts keyed by participant id, then week id; participants and weeks without logs are absent
     */
    public function dailyLogCountsByParticipantAndWeek(array $participantIds, array $weekIds): Collection
    {
        $weekIdsByActivity = Activity::query()->whereIn('training_week_id', $weekIds)->pluck('training_week_id', 'id');
        if ($participantIds === [] || $weekIdsByActivity->isEmpty()) {
            return collect();
        }

        return DailyLog::query()
            ->select(['user_id', 'activity_id'])
            ->selectRaw('count(*) as daily_log_count')
            ->whereIn('user_id', $participantIds)
            ->whereIn('activity_id', $weekIdsByActivity->keys())
            ->groupBy('user_id', 'activity_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows): Collection => $rows
                ->groupBy(fn (DailyLog $row): int => $weekIdsByActivity->get($row->activity_id))
                ->map(fn (Collection $weekRows): int => (int) $weekRows->sum('daily_log_count')));
    }

    /** @return array{status:string,url:?string,activity:?object,criterion:?object,recording:?WeeklyVideoRecording} */
    public function weeklyLoom(User $participant, TrainingGate $gate): array
    {
        return $this->weeklyVideo($participant, $gate);
    }

    /** @return array{status:string,url:?string,activity:?object,criterion:?object,recording:?WeeklyVideoRecording} */
    public function weeklyVideo(User $participant, TrainingGate $gate): array
    {
        return $this->weeklyVideosForParticipants(collect([$participant]), collect([$gate]))->get($participant->id)->get($gate->id);
    }

    /**
     * Resolve the Weekly Video Recording state of several Gates for several participants with a fixed number of queries.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingGate>  $gates
     * @return Collection<int, Collection<int, array{status:string,url:?string,activity:null,criterion:?GateCriterion,recording:?WeeklyVideoRecording}>> keyed by participant id, then Gate id
     */
    public function weeklyVideosForParticipants(Collection $participants, Collection $gates): Collection
    {
        $participantIds = $participants->pluck('id')->all();
        $videoCriteriaByGate = GateCriterion::query()
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->whereIn('criterion_type', ['weekly_video', 'weekly_loom'])
            ->orderByDesc('criterion_type')
            ->orderBy('id')
            ->get()
            ->groupBy('training_gate_id');
        $weekIds = $gates->filter(fn (TrainingGate $gate): bool => $videoCriteriaByGate->has($gate->id))->pluck('training_week_id')->unique()->values()->all();
        $weeks = $weekIds === [] ? collect() : TrainingWeek::query()->findOrFail($weekIds)->keyBy('id');
        $recordings = $weekIds === [] ? collect() : WeeklyVideoRecording::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('training_week_id', $weekIds)
            ->latest('uploaded_at')
            ->get()
            ->unique(fn (WeeklyVideoRecording $recording): string => $recording->user_id.':'.$recording->training_week_id)
            ->keyBy(fn (WeeklyVideoRecording $recording): string => $recording->user_id.':'.$recording->training_week_id);
        $legacyLoomUrls = $this->legacyWeeklyLoomUrls($participantIds, $this->weeklyLoomWeekIds($gates, $videoCriteriaByGate));
        $evaluations = $weekIds === [] ? collect() : GateEvaluation::query()
            ->whereIn('training_gate_id', $videoCriteriaByGate->keys())
            ->whereIn('user_id', $participantIds)
            ->with('criterionEvaluations')
            ->get()
            ->keyBy(fn (GateEvaluation $evaluation): string => $evaluation->user_id.':'.$evaluation->training_gate_id);

        return $participants->mapWithKeys(fn (User $participant): array => [$participant->id => $gates->mapWithKeys(function (TrainingGate $gate) use ($participant, $videoCriteriaByGate, $weeks, $recordings, $legacyLoomUrls, $evaluations): array {
            $videoCriteria = $videoCriteriaByGate->get($gate->id);
            $criterion = $videoCriteria?->first();
            if (! $criterion) {
                return [$gate->id => ['status' => 'missing', 'url' => null, 'activity' => null, 'criterion' => null, 'recording' => null]];
            }

            $week = $weeks->get($gate->training_week_id);
            $recording = $recordings->get($participant->id.':'.$week->id);
            $legacyUrl = ! $recording && $videoCriteria->contains('criterion_type', 'weekly_loom') ? $legacyLoomUrls->get($participant->id)?->get($week->id) : null;
            $criterionEvaluation = $evaluations->get($participant->id.':'.$gate->id)?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id);

            return [$gate->id => [
                'status' => ! $recording && ! $legacyUrl ? 'missing' : ($criterionEvaluation?->isMet() ? 'met' : 'awaiting_review'),
                'url' => $recording ? route('training.video-recording.view', [$week, $recording]) : $legacyUrl,
                'activity' => null,
                'criterion' => $criterion,
                'recording' => $recording,
            ]];
        })]);
    }

    /**
     * Ids of the weeks whose Gates accept a legacy Weekly Loom link.
     *
     * @param  Collection<int, TrainingGate>  $gates
     * @param  Collection<int, Collection<int, GateCriterion>>  $videoCriteriaByGate  weekly video criteria keyed by Gate id
     * @return array<int, int>
     */
    private function weeklyLoomWeekIds(Collection $gates, Collection $videoCriteriaByGate): array
    {
        return $gates
            ->filter(fn (TrainingGate $gate): bool => $videoCriteriaByGate->get($gate->id)?->contains('criterion_type', 'weekly_loom') ?? false)
            ->pluck('training_week_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve the legacy Weekly Loom link each participant submitted as Gate evidence on Activity 3 of each week.
     *
     * @param  array<int, int>  $participantIds
     * @param  array<int, int>  $weekIds
     * @return Collection<int, Collection<int, ?string>> link keyed by participant id, then week id
     */
    private function legacyWeeklyLoomUrls(array $participantIds, array $weekIds): Collection
    {
        if ($participantIds === [] || $weekIds === []) {
            return collect();
        }

        $activitiesByWeek = Activity::query()
            ->whereIn('training_week_id', $weekIds)
            ->where('position', 3)
            ->orderBy('id')
            ->with(['requirements' => fn (Relation $query): Relation => $query->where('requirement_type', 'gate_evidence')->with('evidenceRequirements')])
            ->get()
            ->unique('training_week_id')
            ->keyBy('training_week_id');
        $submissions = $activitiesByWeek->isEmpty() ? collect() : ActivitySubmission::query()
            ->whereIn('user_id', $participantIds)
            ->whereIn('activity_id', $activitiesByWeek->pluck('id'))
            ->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', self::SUBMITTED_STATUSES)->latest('version_number')])
            ->latest('attempt_number')
            ->get()
            ->unique(fn (ActivitySubmission $submission): string => $submission->user_id.':'.$submission->activity_id)
            ->keyBy(fn (ActivitySubmission $submission): string => $submission->user_id.':'.$submission->activity_id);
        $legacyRequirementIds = $activitiesByWeek->mapWithKeys(fn (Activity $activity): array => [$activity->id => $activity->requirements->flatMap->evidenceRequirements->first()?->id]);
        (new EloquentCollection($submissions->map(fn (ActivitySubmission $submission): ?SubmissionVersion => $submission->versions->first())->filter()->values()))
            ->load(['evidence' => fn (Relation $query): Relation => $query->whereIn('evidence_requirement_id', $legacyRequirementIds->filter()->values())->latest()]);

        return collect($participantIds)->mapWithKeys(fn (int $participantId): array => [$participantId => collect($weekIds)->mapWithKeys(function (int $weekId) use ($participantId, $activitiesByWeek, $submissions, $legacyRequirementIds): array {
            $activity = $activitiesByWeek->get($weekId);
            $legacyRequirementId = $activity ? $legacyRequirementIds->get($activity->id) : null;
            $version = $activity ? $submissions->get($participantId.':'.$activity->id)?->versions->first() : null;

            return [$weekId => $version && $legacyRequirementId ? $version->evidence->firstWhere('evidence_requirement_id', $legacyRequirementId)?->url : null];
        })]);
    }

    public function canPass(User $participant, TrainingGate $gate): bool
    {
        return $this->readiness($participant, $gate) === []
            && $gate->criteria()->where('is_required', true)->count() > 0;
    }
}
