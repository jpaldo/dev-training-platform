<?php

namespace App\Services;

use App\Models\TrainingGate;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;

class WeekTwoGateService
{
    public function readiness(User $participant, TrainingGate $gate): array
    {
        $week = $gate->week()->with('activities')->firstOrFail();
        $reasons = [];
        $logs = $participant->dailyLogs()->whereHas('activity', fn (Builder $q): Builder => $q->where('training_week_id', $week->id))->count();
        // Every active training week requires five participant daily logs.
        // The count is scoped through the week's activities, never globally.
        $minimumLogs = $this->minimumDailyLogs();
        if ($logs < $minimumLogs) {
            $remaining = $minimumLogs - $logs;
            $reasons['daily_logs'] = "Daily logs: {$logs} of {$minimumLogs} completed. Complete {$remaining} more Week {$week->week_number} daily log".($remaining === 1 ? '' : 's').' before this Gate can be passed.';
        }
        foreach ($week->activities()->where('is_active', true)->orderBy('position')->get() as $activity) {
            $submission = $participant->activitySubmissions()->where('activity_id', $activity->id)->orderByDesc('attempt_number')->first();
            if (! $submission || ! in_array($submission->status, ['submitted', 'resubmitted', 'approved', 'completed'], true)) {
                $reasons['activity_'.$activity->position] = "Activity {$activity->position} must have a submitted version.";

                continue;
            }
            $version = $submission->versions()->whereIn('status', ['submitted', 'resubmitted', 'approved', 'completed'])->latest('version_number')->first();
            foreach ($activity->requirements()->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')->get() as $requirement) {
                foreach ($requirement->evidenceRequirements as $evidence) {
                    if ($evidence->is_required && (! $version || $version->evidence()->where('evidence_requirement_id', $evidence->id)->count() < $evidence->minimum_count)) {
                        $reasons['evidence_'.$activity->position] = "Required evidence for Activity {$activity->position} is missing.";
                    }
                }
            }
        }

        $weeklyVideo = $this->weeklyVideo($participant, $gate);
        if ($weeklyVideo['status'] === 'missing') {
            $reasons['weekly_video'] = 'Required Weekly Video Recording is missing. Upload the video before this Gate can be passed.';
        }

        return $reasons;
    }

    public function minimumDailyLogs(): int
    {
        return (int) config('training.daily_logs_per_week', 5);
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
