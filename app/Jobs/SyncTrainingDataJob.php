<?php

namespace App\Jobs;

use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\GoogleDriveSync;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveConnectionService;
use App\Services\GoogleDriveService;
use App\Services\TrainingExportService;
use App\Services\TrainingSyncCoordinator;
use App\Services\TrainingWorkbookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncTrainingDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $syncId) {}

    public function handle(GoogleDriveConnectionService $connections, GoogleDriveService $drive, TrainingExportService $exports, TrainingWorkbookService $workbooks, ExportLifecycleService $lifecycle, TrainingSyncCoordinator $syncs): void
    {
        $sync = GoogleDriveSync::find($this->syncId);
        $participant = $sync?->participant;
        $connection = $participant?->googleDriveConnection;
        if (! $sync || ! $participant || ! $connection) {
            $sync?->update(['status' => GoogleDriveSync::FAILED, 'error' => 'Google Drive is not connected for this participant.', 'completed_at' => now()]);

            return;
        }
        $sync->update(['status' => GoogleDriveSync::IN_PROGRESS, 'started_at' => now(), 'error' => null]);
        try {
            // Keep the destination captured when this run was queued. A Picker
            // destination may change while a job is processing; that change is
            // handled by the coalesced follow-up run.
            $destination = $sync->destination_folder_id;
            $drive->validateDestination($participant, $destination, $sync->destination_resource_key, $sync->destination_drive_id);
            $components = [];
            $weekContexts = [];
            foreach ([1, 2, 3, 4] as $week) {
                $weekFolder = $this->managedFolder($participant, $connection, $drive, "Week {$week}", $destination, 'week_folder', (string) $week, $destination);
                if ($week === 1) {
                    // General Training is reporting-only, but its canonical
                    // evidence location is still part of the Week 1 export
                    // contract. Reuse/create only Week 1/Evidences.
                    $this->managedFolder($participant, $connection, $drive, 'Evidences', $weekFolder, 'week_evidences_folder', '1', $destination);
                }
                $weekContexts[$week] = ['folder' => $weekFolder];
                $content = $week === 1 ? $workbooks->forGeneralTraining($participant) : $workbooks->forWeek($participant, $week);
                $components[] = ['type' => 'weekly_workbook_nested', 'scope' => (string) $week, 'name' => "Week {$week}.xlsx", 'content' => $content, 'parent' => $weekFolder, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            }
            $sync->update(['total_components' => count($components), 'successful_components' => 0, 'failed_components' => 0, 'skipped_components' => 0]);
            $componentErrors = [];
            $componentResults = [];
            $evidenceStats = ['eligible' => 0, 'completed' => 0, 'failed' => 0];
            foreach ($components as $component) {
                $componentResults[$component['name']] = ['status' => 'pending'];
                $record = GoogleDriveExport::forDestination($participant->id, $component['type'], $component['scope'], $destination);
                try {
                    $existing = $record->destination_folder_id === $destination ? $record->drive_file_id : null;
                    $fileId = $drive->uploadFile($connection, $component['name'], $component['content'], $component['parent'], $component['mime'], $existing);
                    $record->update(['destination_folder_id' => $destination, 'drive_file_id' => $fileId, 'status' => GoogleDriveExport::COMPLETED, 'last_synced_at' => now(), 'error' => null]);
                    $componentResults[$component['name']] = ['status' => 'completed'];
                    $sync->increment('successful_components');
                } catch (Throwable $exception) {
                    $message = app(ExportLifecycleService::class)->failureMessage('Google Drive sync failed.', $exception);
                    $record->update(['destination_folder_id' => $destination, 'status' => GoogleDriveExport::FAILED, 'error' => $message]);
                    $componentResults[$component['name']] = ['status' => 'failed', 'message' => $message];
                    $componentErrors[] = $component['name'].': '.$message;
                    $sync->increment('failed_components');
                }
                if ($component['type'] === 'weekly_workbook_nested' && (int) $component['scope'] >= 2 && $record->status === GoogleDriveExport::COMPLETED) {
                    $this->syncEvidence($participant, (int) $component['scope'], $connection, $drive, $destination, $weekContexts[(int) $component['scope']]['folder'], $sync, $componentErrors, $evidenceStats);
                }
            }
            $sync->refresh();
            $needsFollowUp = $sync->needs_follow_up;
            $componentResults['Evidences'] = [
                'status' => $evidenceStats['failed'] > 0 ? 'failed' : ($evidenceStats['eligible'] > 0 ? 'completed' : 'skipped'),
                'eligible' => $evidenceStats['eligible'],
                'completed' => $evidenceStats['completed'],
                'failed' => $evidenceStats['failed'],
                'message' => $evidenceStats['failed'] > 0 ? 'One or more evidence items failed.' : ($evidenceStats['eligible'] > 0 ? 'Evidence synchronized.' : 'No eligible binary evidence.'),
            ];
            $sync->update(['status' => $sync->failed_components > 0 ? GoogleDriveSync::COMPLETED_WITH_ERRORS : GoogleDriveSync::COMPLETED, 'component_results' => $componentResults, 'error' => $componentErrors ? implode(' | ', array_unique($componentErrors)) : null, 'completed_at' => now(), 'last_successful_sync_at' => $sync->failed_components > 0 ? $sync->last_successful_sync_at : now(), 'needs_follow_up' => false]);
            if ($needsFollowUp) {
                $syncs->request($participant, 'coalesced_change');
            }
        } catch (Throwable $exception) {
            $retry = $lifecycle->shouldRetry($this->job, $this->tries, $exception);
            $sync->update(['status' => $retry ? 'retrying' : GoogleDriveSync::FAILED, 'error' => $lifecycle->failureMessage('Google Drive sync failed.', $exception), 'completed_at' => $retry ? null : now()]);
            if ($retry) {
                throw $exception;
            }
        }
    }

    private function syncEvidence(User $participant, int $weekNumber, GoogleDriveConnection $connection, GoogleDriveService $drive, string $destination, string $weekFolder, GoogleDriveSync $sync, array &$componentErrors, array &$evidenceStats): void
    {
        $evidenceWeekFolder = null;
        $ensureEvidenceFolder = function () use (&$evidenceWeekFolder, $participant, $connection, $drive, $weekNumber, $weekFolder, $destination): string {
            return $evidenceWeekFolder ??= $this->managedFolder($participant, $connection, $drive, 'Evidences', $weekFolder, 'week_evidences_folder', (string) $weekNumber, $destination);
        };

        try {
            $versions = SubmissionVersion::query()->with(['submission.activity.week', 'evidence'])
                ->whereHas('submission', fn (Builder $q): Builder => $q->where('user_id', $participant->id))
                ->whereHas('submission.activity.week', fn (Builder $q): Builder => $q->where('week_number', $weekNumber))
                ->get()
                ->groupBy('activity_submission_id')
                ->flatMap(function (Collection $items): Collection {
                    $latest = $items->sortByDesc('version_number')->first();
                    $latestSubmitted = $items->sortByDesc('version_number')->first(fn (SubmissionVersion $version): bool => $version->status !== 'draft');

                    return collect([$latest, $latestSubmitted])->filter()->unique('id');
                });
        } catch (Throwable $exception) {
            $sync->increment('failed_components');
            $componentErrors[] = 'Evidences: Unable to discover activity evidence.';

            return;
        }
        $seenEvidence = [];
        foreach ($versions as $version) {
            $activity = $version->submission->activity;
            if ($version->evidence->isEmpty()) {
                continue;
            }
            try {
                $evidenceWeekFolder = $ensureEvidenceFolder();
                $evidenceActivityFolder = $this->managedFolder($participant, $connection, $drive, $activity->title, $evidenceWeekFolder, 'evidence_activity_folder', "{$weekNumber}:{$activity->id}", $destination);
            } catch (Throwable $exception) {
                $sync->increment('failed_components');
                $componentErrors[] = "Evidences (Week {$weekNumber}): Unable to prepare evidence folder.";

                continue;
            }
            foreach ($version->evidence as $evidence) {
                if (isset($seenEvidence[$evidence->id])) {
                    continue;
                }
                $seenEvidence[$evidence->id] = true;
                $evidenceStats['eligible']++;
                $status = GoogleDriveEvidenceExport::forDestination($evidence->id, $destination);
                $evidenceDisk = $evidence->disk ?: 'local';
                if (! $evidence->path || ! Storage::disk($evidenceDisk)->exists($evidence->path)) {
                    $status->update(['status' => 'failed', 'error' => 'Original evidence file is unavailable.', 'last_attempt_at' => now()]);
                    $sync->increment('failed_components');
                    $evidenceStats['failed']++;
                    $componentErrors[] = 'Evidences: Original evidence file is unavailable.';

                    continue;
                }
                try {
                    $existing = $status->destination_folder_id === $destination ? $status->drive_file_id : null;
                    $status->update(['status' => 'in_progress', 'destination_folder_id' => $destination, 'last_attempt_at' => now(), 'error' => null]);
                    $status->update(['drive_file_id' => $drive->uploadFile($connection, $evidence->original_filename ?: "evidence-{$evidence->id}", Storage::disk($evidenceDisk)->get($evidence->path), $evidenceActivityFolder, $evidence->mime_type ?: 'application/octet-stream', $existing), 'status' => 'completed', 'last_synced_at' => now()]);
                    $sync->increment('successful_components');
                    $evidenceStats['completed']++;
                } catch (Throwable $exception) {
                    $status->update(['status' => 'failed', 'error' => app(ExportLifecycleService::class)->failureMessage('Google Drive evidence sync failed.', $exception), 'last_attempt_at' => now()]);
                    $sync->increment('failed_components');
                    $evidenceStats['failed']++;
                    $componentErrors[] = 'Evidences: One evidence file could not be synchronized.';
                }
            }
        }

        try {
            $linkedRecords = $participant->validationSubmissions()->whereNotNull('evidence_files')->get();
        } catch (Throwable $exception) {
            $sync->increment('failed_components');
            $componentErrors[] = 'Evidences: Unable to discover Lab evidence.';

            return;
        }
        foreach ($linkedRecords as $record) {
            $version = SubmissionVersion::query()->with('submission.activity.week')
                ->where('validation_submission_id', $record->id)
                ->whereHas('submission', fn (Builder $q): Builder => $q->where('user_id', $participant->id))
                ->latest('version_number')->first();
            if (! $version || (int) $version->submission->activity->week->week_number !== $weekNumber) {
                continue;
            }
            $files = $record->evidence_files ?? [];
            if ($files === []) {
                continue;
            }
            try {
                $evidenceWeekFolder = $ensureEvidenceFolder();
                $evidenceActivityFolder = $this->managedFolder($participant, $connection, $drive, $version->submission->activity->title, $evidenceWeekFolder, 'evidence_activity_folder', "{$weekNumber}:{$version->submission->activity->id}", $destination);
            } catch (Throwable $exception) {
                $sync->increment('failed_components');
                $componentErrors[] = "Evidences (Week {$weekNumber}): Unable to prepare Lab evidence folder.";

                continue;
            }
            foreach ($files as $index => $file) {
                $fileDisk = $file['disk'] ?? 'local';
                if (! ($file['path'] ?? null) || ! Storage::disk($fileDisk)->exists($file['path'])) {
                    $evidenceStats['eligible']++;
                    $evidenceStats['failed']++;
                    $sync->increment('failed_components');
                    $componentErrors[] = 'Evidences: One Lab evidence file could not be read.';

                    continue;
                }
                try {
                    $existing = ($file['destination_folder_id'] ?? null) === $destination ? ($file['drive_file_id'] ?? null) : null;
                    $driveId = $drive->uploadFile($connection, $file['original_filename'] ?? "lab-evidence-{$record->id}-{$index}", Storage::disk($fileDisk)->get($file['path']), $evidenceActivityFolder, $file['mime_type'] ?? 'application/octet-stream', $existing);
                    $files[$index]['destination_folder_id'] = $destination;
                    $files[$index]['drive_file_id'] = $driveId;
                    $sync->increment('successful_components');
                    $evidenceStats['eligible']++;
                    $evidenceStats['completed']++;
                } catch (Throwable $exception) {
                    $sync->increment('failed_components');
                    $evidenceStats['eligible']++;
                    $evidenceStats['failed']++;
                    $componentErrors[] = 'Evidences: One Lab evidence file could not be synchronized.';
                }
            }
            $record->update(['evidence_files' => $files]);
        }

        $video = WeeklyVideoRecording::query()->where('user_id', $participant->id)->whereHas('week', fn (Builder $q): Builder => $q->where('week_number', $weekNumber))->first();
        if ($video && $video->disk && $video->path && Storage::disk($video->disk)->exists($video->path)) {
            try {
                $evidenceWeekFolder = $ensureEvidenceFolder();
                $videoFolder = $this->managedFolder($participant, $connection, $drive, 'Video Recording', $evidenceWeekFolder, 'weekly_video_folder', (string) $weekNumber, $destination);
                $existing = $video->drive_destination_folder_id === $destination ? $video->drive_file_id : null;
                $driveId = $drive->uploadFile($connection, $video->original_filename, Storage::disk($video->disk)->get($video->path), $videoFolder, $video->mime_type, $existing);
                $video->update(['drive_file_id' => $driveId ?: $existing, 'drive_destination_folder_id' => $destination]);
                $sync->increment('successful_components');
            } catch (Throwable $exception) {
                $sync->increment('failed_components');
                $componentErrors[] = 'Video Recording: Unable to synchronize video.';
            }
        }
    }

    private function managedFolder(User $participant, GoogleDriveConnection $connection, GoogleDriveService $drive, string $name, string $parent, string $type, string $scope, string $destination): string
    {
        $tracking = GoogleDriveExport::forDestination($participant->id, $type, $scope, $destination);
        $folder = $drive->ensureFolder($connection, $name, $parent, $tracking->drive_file_id);
        $tracking->update([
            'drive_file_id' => $folder,
            'destination_folder_id' => $destination,
            'status' => GoogleDriveExport::COMPLETED,
            'last_synced_at' => now(),
            'error' => null,
        ]);

        return $folder;
    }
}
