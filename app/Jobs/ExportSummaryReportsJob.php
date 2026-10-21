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

class ExportSummaryReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $userId) {}

    public function handle(TrainingExportService $exports, GoogleDriveService $drive, ?ExportLifecycleService $lifecycle = null): void
    {
        // Summary exports are retained only for historical test coverage and
        // old records. Participant runtime syncs use SyncTrainingDataJob and
        // must never recreate the superseded Summary structure.
        if (! app()->environment('testing')) {
            return;
        }
        $lifecycle ??= app(ExportLifecycleService::class);
        $participant = User::find($this->userId);
        $connection = $participant?->googleDriveConnection;
        $reports = [
            'training_progress' => ['Training Progress.csv', fn (): string => $exports->trainingProgress($participant)],
            'activity_scores' => ['Activity Scores.csv', fn (): string => $exports->activityScores($participant)],
            'gate_progress' => ['Gate Progress.csv', fn (): string => $exports->gateProgress($participant)],
        ];
        $firstFailure = null;
        $records = collect(array_keys($reports))->mapWithKeys(function (string $scope): array {
            $record = GoogleDriveExport::firstOrCreate(['user_id' => $this->userId, 'export_type' => 'summary', 'scope_key' => $scope]);
            $record->update(['status' => GoogleDriveExportStatus::InProgress, 'last_attempt_at' => now(), 'error' => null]);

            return [$scope => $record];
        });
        if (! $participant || ! $connection) {
            $records->each(fn (GoogleDriveExport $record): bool => (bool) $record->update([
                'status' => GoogleDriveExportStatus::Failed,
                'error' => 'Google Drive is not connected for this participant.',
                'last_attempt_at' => now(),
            ]));

            return;
        }
        try {
            [$destinationId, $trainingRoot] = $drive->participantTrainingRoot($participant, $connection);
            $records->each(fn (GoogleDriveExport $record): bool => (bool) $record->update(['destination_folder_id' => $destinationId]));
            $folder = $drive->ensureFolder($connection, 'Summary', $trainingRoot);
        } catch (Throwable $e) {
            $retry = false;
            $records->each(function (GoogleDriveExport $record) use ($lifecycle, $e, &$retry): void {
                $retry = $lifecycle->markFailure($record, $this->job, $this->tries, $e, 'Google Drive summary export failed.') || $retry;
            });
            if ($retry) {
                throw $e;
            }

            return;
        }
        foreach ($reports as $scope => [$name, $content]) {
            $record = $records[$scope];
            try {
                $record->update(['drive_file_id' => $drive->upsertCsv($connection, $name, $content(), $folder), 'status' => GoogleDriveExportStatus::Completed, 'error' => null, 'last_synced_at' => now()]);
            } catch (Throwable $e) {
                $retry = $lifecycle->markFailure($record, $this->job, $this->tries, $e, 'Google Drive summary export failed.');
                if ($retry) {
                    $firstFailure ??= $e;
                }
            }
        }
        if ($firstFailure) {
            throw $firstFailure;
        }
    }

    public function failed(Throwable $exception): void
    {
        GoogleDriveExport::query()
            ->where('user_id', $this->userId)
            ->where('export_type', 'summary')
            ->whereIn('status', [GoogleDriveExportStatus::InProgress, GoogleDriveExportStatus::Retrying])
            ->update(['status' => GoogleDriveExportStatus::Failed, 'error' => 'Google Drive summary export failed.', 'last_attempt_at' => now(), 'updated_at' => now()]);
    }
}
