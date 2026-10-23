<?php

namespace App\Services;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;

class WeeklyVideoRecordingPageService
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly GoogleDriveConfigurationService $googleDrive,
    ) {}

    /**
     * Everything the Weekly Video Recording page renders: the participant's chosen Google Drive video for the week
     * (with its name, size and when it was chosen), the participant's Google Drive state (`unavailable` while Drive
     * cannot connect, `not_connected` or `connected`) with the connect link, and the Picker settings and label. The
     * Picker form is replaced by a locked message once the participant has passed one of the week's active Gates. The
     * page never reads Google Drive.
     *
     * @return array{trainingWeek: TrainingWeek, recording: ?WeeklyVideoRecording, recordingDetails: ?string, isLocked: bool, storeUrl: string, backUrl: string, driveState: string, connectUrl: string, picker: array{api_key: ?string, app_id: ?string, token_url: string, unavailable_message: ?string}, pickerLabel: string}
     */
    public function editData(TrainingWeek $week, User $user): array
    {
        $recording = WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $week->id)->first();

        return [
            'trainingWeek' => $week,
            'recording' => $recording,
            'recordingDetails' => $recording ? $this->recordingDetails($recording) : null,
            'isLocked' => $this->progression->hasPassedActiveGate($user, $week),
            'storeUrl' => route('training.video-recording.store', $week),
            'backUrl' => route('training.weeks.show', $week),
            'driveState' => match (true) {
                ! $this->googleDrive->canConnect() => 'unavailable',
                $user->googleDriveConnection()->doesntExist() => 'not_connected',
                default => 'connected',
            },
            'connectUrl' => route('profile.google-drive.connect'),
            'picker' => $this->googleDrive->pickerSettings('video'),
            'pickerLabel' => $recording ? 'Choose a Different Video' : 'Choose Video from Google Drive',
        ];
    }

    /**
     * The video's name, size and when it was chosen, e.g. "week-1.mp4 · 12.5 MB · chosen Oct 22, 2026 9:30 AM".
     */
    private function recordingDetails(WeeklyVideoRecording $recording): string
    {
        return collect([$recording->name, $recording->detailsLabel()])->filter()->implode(' · ');
    }
}
