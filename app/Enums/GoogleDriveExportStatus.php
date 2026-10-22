<?php

namespace App\Enums;

/**
 * Where a tracked Google Drive export stands (stored in `google_drive_exports.status` and
 * `google_drive_evidence_exports.status`). A row is `Pending` (both columns' default) until `SyncTrainingDataJob` sets
 * a status: it moves an evidence file through `InProgress` and marks each workbook, folder and evidence file
 * `Completed` or `Failed`. No code writes `Enqueued`, `Retrying`, `Exporting` or `Synced` any more (the last two are
 * legacy names of `InProgress` and `Completed`); the activity page counts `Synced` evidence as completed, and a stale
 * `Enqueued`, `InProgress` or `Retrying` export is reconciled.
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
