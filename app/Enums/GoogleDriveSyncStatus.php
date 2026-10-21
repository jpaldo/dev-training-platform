<?php

namespace App\Enums;

/**
 * Where a participant's full Google Drive sync stands (stored in `google_drive_syncs.status`). A sync is queued as
 * `Enqueued` (the column default), `SyncTrainingDataJob` moves it to `InProgress` (and `Retrying` while Laravel has
 * another attempt), and it ends `Completed`, `CompletedWithErrors` (some components failed) or `Failed`.
 */
enum GoogleDriveSyncStatus: string
{
    case Enqueued = 'enqueued';
    case InProgress = 'in_progress';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    /**
     * The generic status text (the profile's "Latest sync" line shows it for a sync that is neither running nor
     * completed).
     */
    public function label(): string
    {
        return match ($this) {
            self::Enqueued => 'Enqueued',
            self::InProgress => 'In progress',
            self::Retrying => 'Retrying',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
        };
    }

    /**
     * The statuses of a sync that is still running: it blocks a new sync, shows as syncing and is polled, and a stale
     * one is marked failed. Unlike `GoogleDriveExportStatus::active()`, `Retrying` is not among them.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Enqueued, self::InProgress];
    }
}
