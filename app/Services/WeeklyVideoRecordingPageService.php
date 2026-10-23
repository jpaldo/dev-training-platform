<?php

namespace App\Services;

use App\Models\TrainingSession;
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
     * Everything the Weekly Video Recording page renders for the week in the session: the participant's chosen Google
     * Drive video for the week in that session, the participant's Google Drive state (`unavailable` while Drive cannot
     * connect, `not_connected` or `connected`) with the connect link, which brings the participant back to this page,
     * and the Picker settings and label. The Picker form posts within the session and is replaced by a locked message
     * once the participant has passed one of the week's active Gates in the session. `shareHint` says who a chosen
     * video is shared with (null without a share domain, when nothing is shared). Back goes to the week in the session.
     * The page never reads Google Drive.
     *
     * @return array{trainingWeek: TrainingWeek, recording: ?WeeklyVideoRecording, isLocked: bool, storeUrl: string, backUrl: string, driveState: string, connectUrl: string, picker: array{api_key: ?string, app_id: ?string, token_url: string, unavailable_message: ?string}, pickerLabel: string, shareHint: ?string}
     */
    public function editData(TrainingSession $trainingSession, TrainingWeek $week, User $user): array
    {
        $recording = WeeklyVideoRecording::query()
            ->where('training_session_id', $trainingSession->id)
            ->where('user_id', $user->id)
            ->where('training_week_id', $week->id)
            ->first();
        $shareDomain = $this->googleDrive->getShareDomain();

        return [
            'trainingWeek' => $week,
            'recording' => $recording,
            'isLocked' => $this->progression->hasPassedActiveGate($user, $trainingSession, $week),
            'storeUrl' => route('training.video-recording.store', [$trainingSession, $week]),
            'backUrl' => route('training.weeks.show', [$trainingSession, $week]),
            'driveState' => match (true) {
                ! $this->googleDrive->canConnect() => 'unavailable',
                $user->googleDriveConnection()->doesntExist() => 'not_connected',
                default => 'connected',
            },
            'connectUrl' => route('profile.google-drive.connect', ['return_to' => route('training.video-recording.edit', [$trainingSession, $week], absolute: false)]),
            'picker' => $this->googleDrive->pickerSettings('video'),
            'pickerLabel' => $recording ? 'Choose a Different Video' : 'Choose Video from Google Drive',
            'shareHint' => $shareDomain === null
                ? null
                : "Choosing a video shares it, view only, with everyone at {$shareDomain} who has the link, so your mentor can watch it.",
        ];
    }
}
