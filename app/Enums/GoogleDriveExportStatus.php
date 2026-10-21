<?php

namespace App\Enums;

/**
 * Where a tracked Google Drive export stands (stored in `google_drive_exports.status` and
 * `google_drive_evidence_exports.status`). A row is `Pending` (both columns' default) until a job sets a status; the
 * export jobs move it through `InProgress` (and `Retrying` while Laravel has another attempt) to `Completed` or
 * `Failed`. No code writes `Enqueued`, `Exporting` or `Synced`, but the activity page reads all three (the last two
 * are legacy names of `InProgress` and `Completed`) and a stale `Enqueued` export is reconciled.
 */
enum GoogleDriveExportStatus: string
{
    case Pending = 'pending';
    case Enqueued = 'enqueued';
    case InProgress = 'in_progress';
    case Exporting = 'exporting';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Synced = 'synced';
    case Failed = 'failed';

    /**
     * The sync status text of the activity page's Google Drive card.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not Exported',
            self::Enqueued => 'Enqueued',
            self::InProgress, self::Exporting => 'In Progress',
            self::Retrying => 'Retrying',
            self::Completed, self::Synced => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * The statuses of an export that is still running: a stale one is marked failed by
     * `ExportLifecycleService::reconcileStale()`.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Enqueued, self::InProgress, self::Retrying];
    }
}
