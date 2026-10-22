<?php

namespace App\Services;

use App\Models\SubmissionVersion;
use App\Models\User;

class TrainingExportCoordinator
{
    public function queueActivity(int $versionId): void
    {
        $version = SubmissionVersion::query()->with('submission')->findOrFail($versionId);
        $participant = User::find($version->submission->user_id);
        if ($participant?->googleDriveConnection && filled($participant->googleDriveConnection->drive_folder_id)) {
            app(TrainingSyncCoordinator::class)->queue($participant, 'activity_export');
        }
    }

    public function queueSummaryReports(User $participant): void
    {
        if (! $participant->googleDriveConnection || blank($participant->googleDriveConnection->drive_folder_id)) {
            return;
        }
        app(TrainingSyncCoordinator::class)->queue($participant, 'legacy_summary_redirect');
    }

    public function destination(User $participant): ?string
    {
        return app(GoogleDriveConnectionService::class)->destinationFolderId($participant);
    }
}
