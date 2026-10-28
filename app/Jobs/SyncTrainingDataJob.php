<?php

namespace App\Jobs;

use App\Enums\GoogleDriveExportStatus;
use App\Enums\GoogleDriveSyncStatus;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\GoogleDriveSync;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
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

/**
 * Syncs a participant's training work into the folder they chose in their own Google Drive: one managed folder per
 * curriculum they are in, holding Week N/Week N.xlsx (TrainingWorkbookService::forWeek()) and the week's
 * evidence files under Week N/Evidences/{activity}. Weekly videos stay where the participant keeps them and are not
 * copied. Each folder and file is tracked in `google_drive_exports` (and evidence files in
 * `google_drive_evidence_exports`) under a `program:{id}…` scope key and the destination, so a later run updates the
 * same Drive files instead of adding new ones.
 */
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
            $sync?->update(['status' => GoogleDriveSyncStatus::Failed, 'error' => 'Google Drive is not connected for this participant.', 'completed_at' => now()]);

            return;
        }
        $sync->update(['status' => GoogleDriveSyncStatus::InProgress, 'started_at' => now(), 'error' => null]);
        try {
            // Keep the destination captured when this run was queued. A Picker
            // destination may change while a job is processing; that change is
            // handled by the coalesced follow-up run.
            $destination = $sync->destination_folder_id;
            $drive->validateDestination($participant, $destination, $sync->destination_resource_key, $sync->destination_drive_id);
            $components = [];
            foreach ($this->programFolders($participant) as ['program' => $program, 'name' => $folderName, 'weeks' => $weeks]) {
                $programFolder = $this->managedFolder($participant, $connection, $drive, $folderName, $destination, 'program_folder', "program:{$program->id}", $destination);
                foreach ($weeks as $week) {
                    $scope = "program:{$program->id}:week:{$week->id}";
                    $weekFolder = $this->managedFolder($participant, $connection, $drive, "Week {$week->week_number}", $programFolder, 'week_folder', $scope, $destination);
                    $components[] = [
                        'type' => 'weekly_workbook_nested',
                        'scope' => $scope,
                        'name' => "Week {$week->week_number}.xlsx",
                        'label' => "{$folderName} / Week {$week->week_number}.xlsx",
                        'content' => $workbooks->forWeek($participant, $week),
                        'parent' => $weekFolder,
                        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'program' => $program,
                        'week' => $week,
                    ];
                }
            }
            $sync->update(['total_components' => count($components), 'successful_components' => 0, 'failed_components' => 0, 'skipped_components' => 0]);
            $componentErrors = [];
            $componentResults = [];
            $evidenceStats = ['eligible' => 0, 'completed' => 0, 'failed' => 0];
            $evidenceVersions = [];
            foreach ($components as $component) {
                $componentResults[$component['label']] = ['status' => 'pending'];
                $record = GoogleDriveExport::forDestination($participant->id, $component['type'], $component['scope'], $destination);
                try {
                    $existing = $record->destination_folder_id === $destination ? $record->drive_file_id : null;
                    $fileId = $drive->uploadFile($connection, $component['name'], $component['content'], $component['parent'], $component['mime'], $existing);
                    $record->update(['destination_folder_id' => $destination, 'drive_file_id' => $fileId, 'status' => GoogleDriveExportStatus::Completed, 'last_synced_at' => now(), 'error' => null]);
                    $componentResults[$component['label']] = ['status' => 'completed'];
                    $sync->increment('successful_components');
                } catch (Throwable $exception) {
                    $message = app(ExportLifecycleService::class)->failureMessage('Google Drive sync failed.', $exception);
                    $record->update(['destination_folder_id' => $destination, 'status' => GoogleDriveExportStatus::Failed, 'error' => $message]);
                    $componentResults[$component['label']] = ['status' => 'failed', 'message' => $message];
                    $componentErrors[] = $component['label'].': '.$message;
                    $sync->increment('failed_components');
                }
                if ($record->status === GoogleDriveExportStatus::Completed) {
                    $this->syncEvidence($participant, $component['program'], $component['week'], $connection, $drive, $destination, $component['parent'], $sync, $componentErrors, $evidenceStats, $evidenceVersions);
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
            $sync->update(['status' => $sync->failed_components > 0 ? GoogleDriveSyncStatus::CompletedWithErrors : GoogleDriveSyncStatus::Completed, 'component_results' => $componentResults, 'error' => $componentErrors ? implode(' | ', array_unique($componentErrors)) : null, 'completed_at' => now(), 'last_successful_sync_at' => $sync->failed_components > 0 ? $sync->last_successful_sync_at : now(), 'needs_follow_up' => false]);
            if ($needsFollowUp) {
                $syncs->request($participant, 'coalesced_change');
            }
        } catch (Throwable $exception) {
            $retry = $lifecycle->shouldRetry($this->job, $this->tries, $exception);
            $sync->update(['status' => $retry ? GoogleDriveSyncStatus::Retrying : GoogleDriveSyncStatus::Failed, 'error' => $lifecycle->failureMessage('Google Drive sync failed.', $exception), 'completed_at' => $retry ? null : now()]);
            if ($retry) {
                throw $exception;
            }
        }
    }

    /**
     * The participant's curricula, in the order they were created, each with the name of its managed folder
     * (folderName()) and its active weeks in week order, in two queries.
     *
     * @return list<array{program: Program, name: string, weeks: Collection<int, TrainingWeek>}>
     */
    private function programFolders(User $participant): array
    {
        $programs = $participant->programs()->orderBy('programs.id')->get();
        $programWeeks = TrainingWeek::query()
            ->whereIn('program_id', $programs->modelKeys())
            ->where('is_active', true)
            ->orderBy('week_number')
            ->get()
            ->groupBy('program_id');

        return $programs->map(fn (Program $program): array => [
            'program' => $program,
            'name' => $this->folderName($program, $programs),
            'weeks' => $programWeeks->get($program->id, collect())->values(),
        ])->all();
    }

    /**
     * The curriculum's folder name: its name, followed by its ID ("L4 Accelerator Training (#3)") when another of the
     * participant's curricula has the same name, so Drive's same-name folder lookup in
     * GoogleDriveService::ensureFolder() never puts two curricula in one folder.
     *
     * @param  Collection<int, Program>  $programs
     */
    private function folderName(Program $program, Collection $programs): string
    {
        return $programs->where('name', $program->name)->count() === 1
            ? $program->name
            : "{$program->name} (#{$program->id})";
    }

    /**
     * Upload the evidence of one curriculum week: the files of each attempt's latest version and of its latest
     * handed-in version, under Week N/Evidences/{activity}. The curriculum's versions are read once and kept in
     * $evidenceVersions (by program ID, null when they could not be read) for its other weeks.
     *
     * @param  list<string>  $componentErrors
     * @param  array{eligible: int, completed: int, failed: int}  $evidenceStats
     * @param  array<int, ?Collection<int, Collection<int, SubmissionVersion>>>  $evidenceVersions
     */
    private function syncEvidence(User $participant, Program $program, TrainingWeek $week, GoogleDriveConnection $connection, GoogleDriveService $drive, string $destination, string $weekFolder, GoogleDriveSync $sync, array &$componentErrors, array &$evidenceStats, array &$evidenceVersions): void
    {
        $scope = "program:{$program->id}:week:{$week->id}";
        $evidenceWeekFolder = null;
        $ensureEvidenceFolder = function () use (&$evidenceWeekFolder, $participant, $connection, $drive, $scope, $weekFolder, $destination): string {
            return $evidenceWeekFolder ??= $this->managedFolder($participant, $connection, $drive, 'Evidences', $weekFolder, 'week_evidences_folder', $scope, $destination);
        };

        if (! array_key_exists($program->id, $evidenceVersions)) {
            try {
                $evidenceVersions[$program->id] = $this->evidenceVersionsByWeek($participant, $program);
            } catch (Throwable $exception) {
                $evidenceVersions[$program->id] = null;
                $sync->increment('failed_components');
                $componentErrors[] = 'Evidences: Unable to discover activity evidence.';
            }
        }
        if ($evidenceVersions[$program->id] === null) {
            return;
        }
        $versions = $evidenceVersions[$program->id]->get($week->id, collect());
        $seenEvidence = [];
        foreach ($versions as $version) {
            $activity = $version->submission->activity;
            if ($version->evidence->isEmpty()) {
                continue;
            }
            try {
                $evidenceWeekFolder = $ensureEvidenceFolder();
                $evidenceActivityFolder = $this->managedFolder($participant, $connection, $drive, $activity->title, $evidenceWeekFolder, 'evidence_activity_folder', "{$scope}:activity:{$activity->id}", $destination);
            } catch (Throwable $exception) {
                $sync->increment('failed_components');
                $componentErrors[] = "Evidences (Week {$week->week_number}): Unable to prepare evidence folder.";

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
                    $status->update(['status' => GoogleDriveExportStatus::Failed, 'error' => 'Original evidence file is unavailable.', 'last_attempt_at' => now()]);
                    $sync->increment('failed_components');
                    $evidenceStats['failed']++;
                    $componentErrors[] = 'Evidences: Original evidence file is unavailable.';

                    continue;
                }
                try {
                    $existing = $status->destination_folder_id === $destination ? $status->drive_file_id : null;
                    $status->update(['status' => GoogleDriveExportStatus::InProgress, 'destination_folder_id' => $destination, 'last_attempt_at' => now(), 'error' => null]);
                    $status->update(['drive_file_id' => $drive->uploadFile($connection, $evidence->original_filename ?: "evidence-{$evidence->id}", Storage::disk($evidenceDisk)->get($evidence->path), $evidenceActivityFolder, $evidence->mime_type ?: 'application/octet-stream', $existing), 'status' => GoogleDriveExportStatus::Completed, 'last_synced_at' => now()]);
                    $sync->increment('successful_components');
                    $evidenceStats['completed']++;
                } catch (Throwable $exception) {
                    $status->update(['status' => GoogleDriveExportStatus::Failed, 'error' => app(ExportLifecycleService::class)->failureMessage('Google Drive evidence sync failed.', $exception), 'last_attempt_at' => now()]);
                    $sync->increment('failed_components');
                    $evidenceStats['failed']++;
                    $componentErrors[] = 'Evidences: One evidence file could not be synchronized.';
                }
            }
        }
    }

    /**
     * The participant's versions in the curriculum whose evidence is synced, by training week ID: per attempt, the
     * latest version and the latest handed-in one. One query with its eager loads.
     *
     * @return Collection<int, Collection<int, SubmissionVersion>>
     */
    private function evidenceVersionsByWeek(User $participant, Program $program): Collection
    {
        return SubmissionVersion::query()->with(['submission.activity', 'evidence'])
            ->whereHas('submission', fn (Builder $query): Builder => $query
                ->where('user_id', $participant->id)
                ->whereHas('activity.week', fn (Builder $weeks): Builder => $weeks->where('program_id', $program->id)))
            ->get()
            ->groupBy('activity_submission_id')
            ->flatMap(function (Collection $items): Collection {
                $latest = $items->sortByDesc('version_number')->first();
                $latestSubmitted = $items->sortByDesc('version_number')->first(fn (SubmissionVersion $version): bool => ! $version->isDraft());

                return collect([$latest, $latestSubmitted])->filter()->unique('id');
            })
            ->groupBy(fn (SubmissionVersion $version): int => $version->submission->activity->training_week_id);
    }

    private function managedFolder(User $participant, GoogleDriveConnection $connection, GoogleDriveService $drive, string $name, string $parent, string $type, string $scope, string $destination): string
    {
        $tracking = GoogleDriveExport::forDestination($participant->id, $type, $scope, $destination);
        $folder = $drive->ensureFolder($connection, $name, $parent, $tracking->drive_file_id);
        $tracking->update([
            'drive_file_id' => $folder,
            'destination_folder_id' => $destination,
            'status' => GoogleDriveExportStatus::Completed,
            'last_synced_at' => now(),
            'error' => null,
        ]);

        return $folder;
    }
}
