<?php

namespace App\Services;

use App\Enums\GoogleDriveExportStatus;
use App\Enums\GoogleDriveSyncStatus;
use App\Jobs\SyncTrainingDataJob;
use App\Models\GoogleDriveExport;
use App\Models\GoogleDriveSync;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExportLifecycleService
{
    public const STALE_AFTER_MINUTES = 10;

    /**
     * The queued jobs that move a tracked export or a Drive sync forward; while one of them waits in the queue, an
     * export or sync record may look stale without being lost.
     *
     * @var list<class-string>
     */
    private const EXPORT_JOBS = [SyncTrainingDataJob::class];

    public function shouldRetry(?Job $job, int $tries, Throwable $exception): bool
    {
        if (! $this->isRetryable($exception)) {
            return false;
        }

        return $job && $job->attempts() < $tries;
    }

    public function isRetryable(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'google drive folder in your profile')
            || str_contains($message, 'invalid destination')
            || str_contains($message, 'permission_denied')
            || str_contains($message, 'accessnotconfigured')) {
            return false;
        }

        $status = method_exists($exception, 'response') ? $exception->response()?->status() : null;
        if (in_array($status, [400, 401, 403, 404], true)) {
            return false;
        }

        return true;
    }

    public function failureMessage(string $fallback, Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains(strtolower($message), 'google drive folder in your profile')) {
            return 'The Google Drive folder in your Profile could not be accessed. Check that your connected Google account has Editor/write access.';
        }

        return $fallback;
    }

    /**
     * Reconcile only stale active records when the database queue holds no export or sync job (other queued work, such
     * as participant notifications, does not block the repair). This avoids changing a legitimately running export or
     * sync while repairing records whose job disappeared before it could finish.
     */
    public function reconcileStale(?int $userId = null): int
    {
        if ($this->hasQueuedExportJobs()) {
            return 0;
        }

        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $query = GoogleDriveExport::query()
            ->whereIn('status', GoogleDriveExportStatus::active())
            ->whereNotNull('last_attempt_at')
            ->where('last_attempt_at', '<', $cutoff);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $reconciled = $query->update([
            'status' => GoogleDriveExportStatus::Failed,
            'error' => 'Export stopped before completion. Please retry the export.',
            'updated_at' => now(),
        ]);

        $reconciled += GoogleDriveSync::query()
            ->whereIn('status', GoogleDriveSyncStatus::active())
            ->where('updated_at', '<', $cutoff)
            ->when($userId !== null, fn (Builder $q): Builder => $q->where('user_id', $userId))
            ->update([
                'status' => GoogleDriveSyncStatus::Failed,
                'error' => 'Synchronization stopped before completion. Please retry the sync.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        return $reconciled;
    }

    /**
     * Whether the database queue holds an export or sync job, matched on the class name in its payload.
     */
    private function hasQueuedExportJobs(): bool
    {
        return DB::table('jobs')
            ->where(function (QueryBuilder $query): void {
                foreach (self::EXPORT_JOBS as $job) {
                    $query->orWhere('payload', 'like', '%'.class_basename($job).'"%');
                }
            })
            ->exists();
    }
}
