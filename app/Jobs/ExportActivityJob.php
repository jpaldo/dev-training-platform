<?php

namespace App\Jobs;

use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\SubmissionVersion;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveService;
use App\Services\TrainingExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $versionId) {}

    public function handle(TrainingExportService $exports, GoogleDriveService $drive, ?ExportLifecycleService $lifecycle = null): void
    {
        if (! app()->environment('testing')) {
            return;
        }
        $lifecycle ??= app(ExportLifecycleService::class);
        $version = SubmissionVersion::with(['submission.participant', 'submission.activity.week', 'evidence'])->find($this->versionId);
        $record = $version?->submission ? GoogleDriveExport::firstOrCreate(['user_id' => $version->submission->user_id, 'export_type' => 'activity', 'scope_key' => (string) $version->submission->activity_id]) : null;
        $record?->update(['status' => GoogleDriveExport::IN_PROGRESS, 'last_attempt_at' => now()]);
        $connection = $version?->submission?->participant?->googleDriveConnection;
        if (! $version || ! $connection) {
            $record?->update(['status' => GoogleDriveExport::FAILED, 'error' => $version ? 'Google Drive is not connected for this participant.' : 'The activity submission could not be found.', 'last_attempt_at' => now()]);

            return;
        }
        $participant = $version->submission->participant;
        $activity = $version->submission->activity;
        $week = $activity->week->week_number;
        try {
            [$destinationId, $trainingRoot] = $drive->participantTrainingRoot($participant, $connection);
            $record->update(['destination_folder_id' => $destinationId]);
            $weekFolder = $drive->ensureFolder($connection, "Week {$week}", $trainingRoot);
            $activityFolder = $drive->ensureFolder($connection, 'Activity '.$activity->position.' - '.$activity->title, $weekFolder);
            $record->update(['drive_file_id' => $drive->upsertCsv($connection, 'Activity Report.csv', $exports->activity($participant, $activity->id), $activityFolder), 'status' => GoogleDriveExport::COMPLETED, 'error' => null, 'last_synced_at' => now()]);
            $evidenceFolder = $drive->ensureFolder($connection, 'Evidence', $activityFolder);
            $evidenceFailure = null;
            foreach ($version->evidence as $evidence) {
                $status = GoogleDriveEvidenceExport::firstOrCreate(['submission_evidence_id' => $evidence->id]);
                $evidenceDisk = $evidence->disk ?: 'local';
                if (! $evidence->path || ! Storage::disk($evidenceDisk)->exists($evidence->path)) {
                    $status->update(['status' => GoogleDriveEvidenceExport::FAILED, 'error' => 'Original evidence file is unavailable.', 'last_attempt_at' => now()]);
                    $evidenceFailure ??= new \RuntimeException('One or more evidence files could not be exported.');

                    continue;
                }
                $existingDestination = $status->destination_folder_id;
                $status->update(['status' => GoogleDriveEvidenceExport::IN_PROGRESS, 'last_attempt_at' => now(), 'error' => null, 'destination_folder_id' => $destinationId]);
                try {
                    $existingId = $existingDestination === $destinationId ? $status->drive_file_id : null;
                    $status->update(['drive_file_id' => $drive->uploadFile($connection, $evidence->original_filename ?: "evidence-{$evidence->id}", Storage::disk($evidenceDisk)->get($evidence->path), $evidenceFolder, $evidence->mime_type ?: 'application/octet-stream', $existingId), 'status' => GoogleDriveEvidenceExport::COMPLETED, 'error' => null, 'last_synced_at' => now()]);
                } catch (Throwable $exception) {
                    if ($lifecycle->markEvidenceFailure($status, $this->job, $this->tries, $exception)) {
                        $evidenceFailure ??= $exception;
                    }
                }
            }
            if ($evidenceFailure) {
                throw $evidenceFailure;
            }
            ExportSummaryReportsJob::dispatch($participant->id);
        } catch (Throwable $e) {
            if ($lifecycle->markFailure($record, $this->job, $this->tries, $e, 'Google Drive activity export failed.')) {
                throw $e;
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $version = SubmissionVersion::with('submission')->find($this->versionId);
        if ($version?->submission) {
            GoogleDriveExport::query()->where('user_id', $version->submission->user_id)->where('export_type', 'activity')->where('scope_key', (string) $version->submission->activity_id)->update(['status' => GoogleDriveExport::FAILED, 'error' => 'Google Drive activity export failed.', 'last_attempt_at' => now(), 'updated_at' => now()]);
            GoogleDriveEvidenceExport::query()->whereIn('submission_evidence_id', $version->evidence()->pluck('id'))->whereIn('status', [GoogleDriveEvidenceExport::RETRYING, GoogleDriveEvidenceExport::IN_PROGRESS])->update(['status' => GoogleDriveEvidenceExport::FAILED, 'error' => 'Google Drive evidence export failed.', 'last_attempt_at' => now(), 'updated_at' => now()]);
        }
    }
}
