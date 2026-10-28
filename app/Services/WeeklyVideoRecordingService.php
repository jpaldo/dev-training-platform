<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WeeklyVideoRecordingService
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly GoogleDriveConfigurationService $configuration,
    ) {}

    /**
     * Record the video the participant chose from their own Google Drive as their weekly video for the week, replacing
     * an earlier choice (one video per participant and week). The file is read once through the participant's Drive
     * connection; a non-video, trashed or not-owned file, an unreadable file, or a missing connection throws a
     * `RuntimeException` with the participant-facing message and saves nothing. Without posted notes the earlier notes
     * are kept.
     *
     * Once saved, the video is shared view only with the company's Google Workspace domain, and the permission's ID
     * and `shared_at` are stored. Without a share domain, or when Drive refuses the share, the choice is kept with
     * `shared_at` null and the failure is logged (see `sharingWarning()`). When the choice replaces a different file,
     * that file's earlier share is then removed, best effort; choosing the same file again never removes its share.
     *
     * @param  array{drive_file_id: string, resource_key?: ?string, notes?: ?string}  $details
     *
     * @throws RuntimeException
     */
    public function choose(User $participant, TrainingWeek $week, array $details): WeeklyVideoRecording
    {
        $connection = $participant->googleDriveConnection;

        if ($connection === null) {
            throw new RuntimeException('Connect Google Drive before choosing your video.');
        }

        $file = $this->drive->videoFile($connection, $details['drive_file_id'], $details['resource_key'] ?? null);
        $recording = WeeklyVideoRecording::query()->firstOrNew([
            'user_id' => $participant->id,
            'training_week_id' => $week->id,
        ]);
        $earlierFileId = $recording->drive_file_id;
        $earlierPermissionId = $recording->drive_permission_id;
        $isSameFile = $recording->exists && $earlierFileId === $file['id'];

        $recording->fill([
            'drive_file_id' => $file['id'],
            'drive_resource_key' => $file['resource_key'],
            'drive_permission_id' => $isSameFile ? $earlierPermissionId : null,
            'name' => $file['name'],
            'mime_type' => $file['mime_type'],
            'size_bytes' => $file['size_bytes'],
            'drive_created_at' => $file['created_at'],
            'drive_modified_at' => $file['modified_at'],
            'notes' => $details['notes'] ?? $recording->notes,
            'chosen_at' => now(),
            'shared_at' => null,
        ])->save();

        $this->shareWithDomain($connection, $recording);

        if (! $isSameFile && filled($earlierFileId) && filled($earlierPermissionId)) {
            $this->removeEarlierShare($connection, $earlierFileId, $earlierPermissionId);
        }

        return $recording;
    }

    /**
     * What to tell the participant when their chosen video could not be shared, or null once it is shared, e.g.
     * "Your video is saved, but it could not be shared. In Google Drive, share it with everyone at fullscale.io as
     * Viewer." Without a configured domain it asks them to share it with their mentor instead.
     */
    public function sharingWarning(WeeklyVideoRecording $recording): ?string
    {
        if ($recording->isShared()) {
            return null;
        }

        $domain = $this->configuration->getShareDomain();
        $audience = $domain === null ? 'your mentor' : "everyone at {$domain}";

        return "Your video is saved, but it could not be shared. In Google Drive, share it with {$audience} as Viewer.";
    }

    /**
     * Share the saved video with the configured domain and store the permission. A missing domain is logged here;
     * a refused share is logged by `GoogleDriveService::shareWithDomain()`.
     */
    private function shareWithDomain(GoogleDriveConnection $connection, WeeklyVideoRecording $recording): void
    {
        $domain = $this->configuration->getShareDomain();

        if ($domain === null) {
            Log::warning('Weekly video not shared: no Google Workspace share domain is configured.', [
                'weekly_video_recording_id' => $recording->id,
            ]);

            return;
        }

        try {
            $permissionId = $this->drive->shareWithDomain($connection, $recording->drive_file_id, $recording->drive_resource_key, $domain);
        } catch (RuntimeException) {
            return;
        }

        $recording->fill([
            'drive_permission_id' => $permissionId,
            'shared_at' => now(),
        ])->save();
    }

    /**
     * Stop sharing the file the participant replaced. A failure only leaves that file shared, so it is logged by
     * `GoogleDriveService::removePermission()` and otherwise ignored.
     */
    private function removeEarlierShare(GoogleDriveConnection $connection, string $fileId, string $permissionId): void
    {
        try {
            $this->drive->removePermission($connection, $fileId, $permissionId);
        } catch (RuntimeException) {
            return;
        }
    }
}
