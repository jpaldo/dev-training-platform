<?php

namespace App\Services;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;

class WeeklyVideoRecordingPageService
{
    /**
     * Everything the Weekly Video Recording page renders: the participant's recording for the week (with its size and
     * upload time) and the upload form, which is replaced by a locked message once the participant has passed one of
     * the week's active Gates. The main action is the upload (or replace) submit at the end of the form.
     *
     * @return array{trainingWeek: TrainingWeek, recording: ?WeeklyVideoRecording, recordingDetails: ?string, isLocked: bool, storeUrl: string, backUrl: string, maxBytes: int, maxSizeLabel: string, submitLabel: string}
     */
    public function editData(TrainingWeek $week, User $user): array
    {
        $recording = WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $week->id)->first();
        $maxKb = (int) config('training.weekly_video_max_kb', 358400);

        return [
            'trainingWeek' => $week,
            'recording' => $recording,
            'recordingDetails' => $recording ? $this->recordingDetails($recording) : null,
            'isLocked' => $this->hasPassedActiveGate($week, $user),
            'storeUrl' => route('training.video-recording.store', $week),
            'backUrl' => route('training.weeks.show', $week),
            'maxBytes' => $maxKb * 1024,
            'maxSizeLabel' => round($maxKb / 1024, 1).' MB',
            'submitLabel' => $recording ? 'Replace Video Recording' : 'Upload Video Recording',
        ];
    }

    /**
     * The recording's size in MB and its upload time, e.g. "12.5 MB · Oct 22, 2026 9:30 AM".
     */
    private function recordingDetails(WeeklyVideoRecording $recording): string
    {
        return number_format($recording->size_bytes / 1048576, 1).' MB · '.$recording->uploaded_at?->format('M j, Y g:i A');
    }

    /**
     * Whether the user has passed one of the week's active Gates, which locks the recording.
     */
    private function hasPassedActiveGate(TrainingWeek $week, User $user): bool
    {
        return $week->gates()
            ->where('is_active', true)
            ->whereHas('evaluations', fn (Builder $query): Builder => $query->where('user_id', $user->id)->where('status', 'passed'))
            ->exists();
    }
}
