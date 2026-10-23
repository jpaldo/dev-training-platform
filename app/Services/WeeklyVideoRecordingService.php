<?php

namespace App\Services;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use RuntimeException;

class WeeklyVideoRecordingService
{
    public function __construct(private readonly GoogleDriveService $drive) {}

    /**
     * Record the video the participant chose from their own Google Drive as their weekly video for the week, replacing
     * an earlier choice. The file is read once through the participant's Drive connection; a non-video, trashed or
     * not-owned file, an unreadable file, or a missing connection throws a `RuntimeException` with the
     * participant-facing message and saves nothing. Without posted notes the earlier notes are kept.
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

        $recording->fill([
            'drive_file_id' => $file['id'],
            'drive_resource_key' => $file['resource_key'],
            'name' => $file['name'],
            'mime_type' => $file['mime_type'],
            'size_bytes' => $file['size_bytes'],
            'drive_created_at' => $file['created_at'],
            'drive_modified_at' => $file['modified_at'],
            'notes' => $details['notes'] ?? $recording->notes,
            'chosen_at' => now(),
        ])->save();

        return $recording;
    }
}
