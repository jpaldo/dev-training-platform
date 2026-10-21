<?php

namespace App\Jobs;

use App\Enums\GoogleDriveExportStatus;
use App\Models\GoogleDriveExport;
use App\Models\User;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveService;
use App\Services\TrainingExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExportDailyLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $userId, public int $weekNumber) {}

    public function handle(TrainingExportService $exports, GoogleDriveService $drive, ?ExportLifecycleService $lifecycle = null): void
    {
        if (! app()->environment('testing')) {
            return;
        }
        $lifecycle ??= app(ExportLifecycleService::class);
        $participant = User::find($this->userId);
        $record = GoogleDriveExport::firstOrCreate(['user_id' => $this->userId, 'export_type' => 'daily_logs', 'scope_key' => (string) $this->weekNumber]);
        $record->update(['status' => GoogleDriveExportStatus::InProgress, 'last_attempt_at' => now()]);
        $connection = $participant?->googleDriveConnection;
        if (! $participant || ! $connection) {
            $record->update(['status' => GoogleDriveExportStatus::Failed, 'error' => 'Google Drive is not connected for this participant.', 'last_attempt_at' => now()]);

            return;
        }
        try {
            $record->update(['status' => GoogleDriveExportStatus::InProgress, 'last_attempt_at' => now(), 'error' => null]);
            [$destinationId, $trainingRoot] = $drive->participantTrainingRoot($participant, $connection);
            $record->update(['destination_folder_id' => $destinationId]);
            $folder = $drive->ensureFolder($connection, "Week {$this->weekNumber}", $trainingRoot);
            $folder = $drive->ensureFolder($connection, 'Daily Logs', $folder);
            $record->update(['drive_file_id' => $drive->upsertCsv($connection, 'Daily Logs.csv', $exports->dailyLogs($participant, $this->weekNumber), $folder), 'status' => GoogleDriveExportStatus::Completed, 'error' => null, 'last_synced_at' => now()]);
            ExportSummaryReportsJob::dispatch($participant->id);
        } catch (Throwable $e) {
            if ($lifecycle->markFailure($record, $this->job, $this->tries, $e, 'Google Drive Daily Log export failed.')) {
                throw $e;
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        GoogleDriveExport::query()->where('user_id', $this->userId)->where('export_type', 'daily_logs')->where('scope_key', (string) $this->weekNumber)->update(['status' => GoogleDriveExportStatus::Failed, 'error' => 'Google Drive Daily Log export failed.', 'last_attempt_at' => now(), 'updated_at' => now()]);
    }
}
