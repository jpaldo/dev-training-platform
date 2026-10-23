<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveExportStatus;
use App\Enums\GoogleDriveSyncStatus;
use App\Jobs\SyncTrainingDataJob;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\GoogleDriveSync;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveConnectionService;
use App\Services\GoogleDriveService;
use App\Services\TrainingExportService;
use App\Services\TrainingSyncCoordinator;
use App\Services\TrainingWorkbookService;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class GoogleDriveSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The worksheet and shared-string XML of a generated workbook, where its cell text lives.
     */
    private function workbookText(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qa-workbook-');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/') || $name === 'xl/sharedStrings.xml') {
                $xml .= $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);

        return $xml;
    }

    private function configureDrive(): void
    {
        config([
            'services.google_drive.enabled' => true,
            'services.google_drive.client_id' => 'client',
            'services.google_drive.client_secret' => 'secret',
            'services.google_drive.redirect_uri' => 'https://example.test/callback',
            'services.google_drive.picker_api_key' => 'picker-key',
            'services.google_drive.project_number' => '123456789',
        ]);
    }

    public function test_week_workbook_contains_one_sheet_per_activity_and_no_daily_logs(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        foreach (['AI Output Error Detection', 'Validation Checklist', 'Pre-acceptance Check'] as $position => $title) {
            Activity::factory()->create(['training_week_id' => $week->id, 'title' => $title, 'position' => $position + 1]);
        }

        $content = app(TrainingWorkbookService::class)->forWeek($participant, 2);
        $path = tempnam(sys_get_temp_dir(), 'qa-sync-test-');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($path);
        $this->assertSame(3, substr_count($workbook, '<sheet '));
        $this->assertStringContainsString('AI Output Error Detection', $workbook);
        $this->assertStringContainsString('Validation Checklist', $workbook);
        $this->assertStringContainsString('Pre-acceptance Check', $workbook);
        $this->assertStringNotContainsString('Daily Logs', $workbook);
    }

    public function test_week_workbook_without_activities_has_one_header_only_activities_sheet(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $content = app(TrainingWorkbookService::class)->forWeek($participant, 1);
        $path = tempnam(sys_get_temp_dir(), 'qa-empty-week-');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $zip->close();
        @unlink($path);
        $this->assertSame(1, substr_count($workbook, '<sheet '));
        $this->assertStringContainsString('<sheet name="Activities" sheetId="1" r:id="rId1"/>', $workbook);
        $this->assertSame(1, substr_count($contentTypes, '/xl/worksheets/sheet'));
        $this->assertSame(1, substr_count($sheet, '<row '));
        $this->assertStringContainsString('>Participant</t>', $sheet);
    }

    public function test_participant_week_workbook_contains_no_internal_scoring_columns(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 1))->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => []]);
        $content = app(TrainingWorkbookService::class)->forWeek($participant, 1);
        $path = tempnam(sys_get_temp_dir(), 'qa-score-');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = '';
        for ($i = 1; $i <= $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/')) {
                $xml .= $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);
        $this->assertStringNotContainsString('Activity Score', $xml);
        $this->assertStringNotContainsString('Total Score', $xml);
        $this->assertStringNotContainsString('>D1<', $xml);
        $this->assertStringNotContainsString('Score Band', $xml);
    }

    public function test_week_workbook_prefers_the_latest_submitted_version_over_a_newer_revision_draft(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $activity = Activity::query()->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 1))->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted', 'participant_note' => 'Submitted note']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'draft', 'participant_note' => 'Revision draft note']);

        $xml = $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, 1));

        $this->assertStringContainsString('Submitted note', $xml);
        $this->assertStringNotContainsString('Revision draft note', $xml);
    }

    public function test_week_workbooks_print_the_submission_status_and_configured_answers_of_the_activity(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        foreach ([1 => 'under_review', 2 => 'needs_revision'] as $weekNumber => $status) {
            $activity = Activity::query()->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', $weekNumber))->where('is_active', true)->orderBy('position')->firstOrFail();
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $status]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => $weekNumber === 2 ? ['deliverable_url' => 'https://github.com/example/checkout'] : []]);
        }

        $this->assertStringContainsString('<t xml:space="preserve">under_review</t>', $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, 1)));
        $weekTwoWorkbook = $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, 2));
        $this->assertStringContainsString('<t xml:space="preserve">needs_revision</t>', $weekTwoWorkbook);
        $this->assertStringContainsString('<t xml:space="preserve">Deliverable link</t>', $weekTwoWorkbook);
        $this->assertStringContainsString('<t xml:space="preserve">https://github.com/example/checkout</t>', $weekTwoWorkbook);
    }

    public function test_sqlite_evidence_tracking_references_submission_evidence_table(): void
    {
        $foreignKey = collect(DB::select('PRAGMA foreign_key_list(google_drive_evidence_exports)'))
            ->firstWhere('from', 'submission_evidence_id');

        $this->assertNotNull($foreignKey);
        $this->assertSame('submission_evidence', $foreignKey->table);
    }

    public function test_participant_sync_requires_picker_destination_and_queues_one_batch(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'folder', 'destination_source' => 'picker', 'connected_at' => now()]);
        Queue::fake();

        $this->actingAs($participant)->post(route('google-drive.sync'))->assertRedirect()->assertSessionHas('status', 'Sync queued.');
        Queue::assertPushed(SyncTrainingDataJob::class);
        $this->assertDatabaseHas('google_drive_syncs', ['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => 'enqueued']);
        $this->actingAs($participant)->post(route('google-drive.sync'))->assertRedirect()->assertSessionHas('status', 'Google Drive sync is already in progress.');
        $this->assertDatabaseCount('google_drive_syncs', 1);
    }

    public function test_a_sync_waiting_for_its_retry_blocks_a_second_sync(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'folder', 'destination_source' => 'picker', 'connected_at' => now()]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => GoogleDriveSyncStatus::Retrying]);
        Queue::fake();

        $this->actingAs($participant)->post(route('google-drive.sync'))->assertRedirect()->assertSessionHas('status', 'Google Drive sync is already in progress.');
        Queue::assertNotPushed(SyncTrainingDataJob::class);
        $this->assertDatabaseCount('google_drive_syncs', 1);
    }

    public function test_stale_queued_sync_is_failed_by_status_endpoint_instead_of_polling_forever(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'folder', 'destination_source' => 'picker', 'connected_at' => now()]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => GoogleDriveSyncStatus::InProgress]);
        DB::table('google_drive_syncs')->where('id', $sync->id)->update(['updated_at' => now()->subSeconds(601)]);

        $response = $this->actingAs($participant)->getJson(route('google-drive.sync.status'));

        $response->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('finished', true);
        $this->assertDatabaseHas('google_drive_syncs', ['id' => $sync->id, 'status' => 'failed']);
    }

    public function test_failed_sync_is_retrying_while_laravel_has_another_attempt_and_failed_after_the_last(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->twice()->andThrow(new RuntimeException('temporary network timeout'));
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturnValues([1, 3]);
        $job = new SyncTrainingDataJob($sync->id);
        $job->setJob($queueJob);
        $runJob = fn (): mixed => $job->handle(app(GoogleDriveConnectionService::class), $drive, Mockery::mock(TrainingExportService::class), Mockery::mock(TrainingWorkbookService::class), app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        try {
            $runJob();
            $this->fail('A retried sync must rethrow so Laravel runs the next attempt.');
        } catch (RuntimeException $exception) {
            $this->assertSame('temporary network timeout', $exception->getMessage());
        }
        $this->assertSame(GoogleDriveSyncStatus::Retrying, $sync->fresh()->status);
        $this->assertNull($sync->fresh()->completed_at);

        $runJob();

        $this->assertSame(GoogleDriveSyncStatus::Failed, $sync->fresh()->status);
        $this->assertSame('Google Drive sync failed.', $sync->fresh()->error);
        $this->assertNotNull($sync->fresh()->completed_at);
    }

    public function test_export_tracking_is_isolated_by_destination(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $folderA = GoogleDriveExport::forDestination($participant->id, 'weekly_workbook', '2', 'folder-a');
        $folderA->update(['drive_file_id' => 'a-123', 'status' => GoogleDriveExportStatus::Completed]);
        $folderB = GoogleDriveExport::forDestination($participant->id, 'weekly_workbook', '2', 'folder-b');

        $this->assertNull($folderB->drive_file_id);
        $this->assertSame(GoogleDriveExportStatus::Pending, $folderB->fresh()->status);
        $this->assertSame('a-123', GoogleDriveExport::forDestination($participant->id, 'weekly_workbook', '2', 'folder-a')->drive_file_id);
        $this->assertSame(2, GoogleDriveExport::query()->where('user_id', $participant->id)->count());
    }

    public function test_nested_sync_does_not_adopt_legacy_root_workbook_tracking(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $legacy = GoogleDriveExport::create(['user_id' => $participant->id, 'export_type' => 'weekly_workbook', 'scope_key' => '2', 'destination_folder_id' => 'root', 'drive_file_id' => 'legacy-root-workbook', 'status' => GoogleDriveExportStatus::Completed]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->once()->andReturn('root');
        $drive->shouldReceive('ensureFolder')->times(5)->andReturnUsing(fn ($received, string $name, string $parent): string => 'folder-'.md5($name.'|'.$parent));
        $drive->shouldReceive('uploadFile')->times(4)->andReturnUsing(fn ($received, string $name): string => 'nested-'.$name);
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->times(4)->andReturn('xlsx');

        (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, Mockery::mock(TrainingExportService::class), $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        $this->assertSame('legacy-root-workbook', $legacy->fresh()->drive_file_id);
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'weekly_workbook_nested', 'scope_key' => '2', 'drive_file_id' => 'nested-Week 2.xlsx']);
    }

    public function test_full_sync_exports_the_evidence_of_the_latest_submitted_version_and_of_a_newer_revision_draft(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        $activity = Activity::factory()->create(['training_week_id' => $week->id, 'position' => 1, 'title' => 'Validation Checklist']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $evidence = collect(['submitted' => 1, 'draft' => 2])->map(function (int $versionNumber, string $status) use ($submission): SubmissionEvidence {
            $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $versionNumber, 'status' => $status]);
            $item = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => "evidence/{$status}-proof.png", 'original_filename' => "{$status}-proof.png", 'mime_type' => 'image/png']);
            Storage::disk('local')->put($item->path, 'activity evidence');

            return $item;
        });
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->once()->andReturn('root');
        $drive->shouldReceive('ensureFolder')->andReturnUsing(fn (GoogleDriveConnection $received, string $name, string $parent): string => 'folder-'.md5($name.'|'.$parent));
        $drive->shouldReceive('uploadFile')->andReturnUsing(fn (GoogleDriveConnection $received, string $name): string => 'drive-'.$name);
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->andReturn('xlsx');

        app()->instance(GoogleDriveService::class, $drive);
        (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, Mockery::mock(TrainingExportService::class), $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        $this->assertSame(2, $sync->fresh()->component_results['Evidences']['completed']);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $evidence['submitted']->id, 'status' => 'completed']);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $evidence['draft']->id, 'status' => 'completed']);
    }

    public function test_full_sync_exports_evidence_attached_to_the_current_draft_version(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        $activity = Activity::factory()->create(['training_week_id' => $week->id, 'position' => 1, 'title' => 'Validation Checklist']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => 'evidence/activity-proof.png', 'original_filename' => 'activity-proof.png', 'mime_type' => 'image/png']);
        Storage::disk('local')->put($evidence->path, 'activity evidence');
        $connection = GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->once()->andReturn('root');
        $drive->shouldReceive('ensureFolder')->times(7)->andReturnUsing(function ($received, string $name, string $parent, ?string $existing = null): string {
            return 'folder-'.md5($name.'|'.$parent);
        });
        $drive->shouldReceive('uploadFile')->times(5)->andReturnUsing(fn ($received, $name) => 'drive-'.$name);
        $exports = Mockery::mock(TrainingExportService::class);
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->times(4)->andReturn('xlsx');

        app()->instance(GoogleDriveService::class, $drive);
        (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, $exports, $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        $this->assertSame(GoogleDriveSyncStatus::Completed, $sync->fresh()->status);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $evidence->id, 'status' => 'completed', 'destination_folder_id' => 'root']);
        $this->assertSame('completed', $sync->fresh()->component_results['Evidences']['status']);
        $this->assertSame(1, $sync->fresh()->component_results['Evidences']['completed']);
        $this->assertSame(0, $sync->fresh()->component_results['Evidences']['failed']);
    }

    public function test_sync_reconciles_five_evidence_files_with_partial_drive_tracking_and_reuses_ids(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        $activity = Activity::factory()->create(['training_week_id' => $week->id, 'position' => 2, 'title' => 'AI Output Error Detection']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted']);
        $names = array_map(static fn (int $index): string => "Week2-Activity2-{$index}.png", range(1, 5));
        $evidence = collect($names)->map(function (string $name) use ($version): SubmissionEvidence {
            $record = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create([
                'evidence_type' => 'file',
                'disk' => 'local',
                'path' => 'evidence/'.$name,
                'original_filename' => $name,
                'mime_type' => 'image/png',
            ]);
            Storage::disk('local')->put($record->path, 'image-'.$name);

            return $record;
        });
        $connection = GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $existingIds = ['drive-1', 'drive-2', 'drive-3'];
        foreach ($evidence->take(3) as $index => $record) {
            GoogleDriveEvidenceExport::create([
                'submission_evidence_id' => $record->id,
                'destination_folder_id' => 'root',
                'drive_file_id' => $existingIds[$index],
                'status' => 'completed',
            ]);
        }
        $syncs = collect([
            GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]),
            GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]),
        ]);
        $calls = [];
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->twice()->andReturn('root');
        $drive->shouldReceive('ensureFolder')->times(14)->andReturnUsing(fn ($received, string $name, string $parent, ?string $existing = null): string => 'folder-'.md5($name.'|'.$parent));
        $drive->shouldReceive('uploadFile')->times(18)->andReturnUsing(function ($received, string $name, string $content, string $parent, string $mime, ?string $existing = null) use (&$calls): string {
            $calls[] = [$name, $existing];

            return 'drive-'.$name;
        });
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->times(8)->andReturn('xlsx');

        foreach ($syncs as $sync) {
            (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, Mockery::mock(TrainingExportService::class), $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));
        }

        $evidenceCalls = collect($calls)->filter(fn (array $call): bool => in_array($call[0], $names, true))->values();
        $this->assertCount(10, $evidenceCalls);
        $this->assertSame(['drive-1', 'drive-2', 'drive-3', null, null], $evidenceCalls->take(5)->pluck(1)->all());
        $this->assertSame(['drive-Week2-Activity2-1.png', 'drive-Week2-Activity2-2.png', 'drive-Week2-Activity2-3.png', 'drive-Week2-Activity2-4.png', 'drive-Week2-Activity2-5.png'], $evidenceCalls->slice(5)->pluck(1)->all());
        $this->assertDatabaseCount('google_drive_evidence_exports', 5);
        $this->assertSame(5, GoogleDriveEvidenceExport::query()->where('status', 'completed')->count());
    }

    public function test_repeated_full_sync_updates_tracked_workbooks_in_place(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'drive_folder_id' => 'root',
            'destination_source' => 'picker',
            'connected_at' => now(),
        ]);
        $first = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
        $second = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
        $third = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
        $calls = [];
        $folderCalls = [];
        $ids = [];

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->times(3)->andReturn('root');
        $drive->shouldReceive('ensureFolder')->times(15)->andReturnUsing(function ($received, string $name, string $parent, ?string $existing = null) use (&$folderCalls): string {
            $folderCalls[] = [$name, $parent, $existing];

            return 'folder-'.md5($name.'|'.$parent);
        });
        $drive->shouldReceive('uploadFile')->times(12)->andReturnUsing(function ($received, string $name, string $content, string $parent, string $mime, ?string $existing = null) use (&$calls, &$ids): string {
            $calls[] = [$name, $parent, $existing];
            $ids[$name] ??= 'drive-'.count($ids);

            return $ids[$name];
        });
        $exports = Mockery::mock(TrainingExportService::class);
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->times(12)->andReturn('xlsx');

        (new SyncTrainingDataJob($first->id))->handle(app(GoogleDriveConnectionService::class), $drive, $exports, $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));
        (new SyncTrainingDataJob($second->id))->handle(app(GoogleDriveConnectionService::class), $drive, $exports, $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));
        (new SyncTrainingDataJob($third->id))->handle(app(GoogleDriveConnectionService::class), $drive, $exports, $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        $this->assertCount(12, $calls);
        $this->assertSame(3, collect($calls)->where('0', 'Week 1.xlsx')->count());
        $this->assertNotContains('Activity Scores.xlsx', array_column($calls, 0));
        $this->assertNotContains('Training Progress.xlsx', array_column($calls, 0));
        $this->assertNotContains('Gate Progress.xlsx', array_column($calls, 0));
        $this->assertNotContains('Daily Logs.xlsx', array_column($calls, 0));
        foreach (array_slice($calls, 0, 4) as [$name, $parent, $existing]) {
            $this->assertNull($existing, $name.' should be created on the first sync.');
        }
        foreach (array_slice($calls, 4) as [$name, $parent, $existing]) {
            $this->assertSame($ids[$name], $existing, $name.' should be updated using its tracked Drive ID.');
        }
        $weekFolders = collect($folderCalls)->filter(fn (array $call): bool => in_array($call[0], ['Week 2', 'Week 3', 'Week 4'], true));
        $evidenceFolders = collect($folderCalls)->filter(fn (array $call): bool => $call[0] === 'Evidences');
        $this->assertCount(9, $weekFolders);
        $this->assertCount(3, $evidenceFolders);
        $this->assertNotContains('Week 2 - Evidences', array_column($folderCalls, 0));
        $this->assertNotContains('Week 3 - Evidences', array_column($folderCalls, 0));
        $this->assertNotContains('Week 4 - Evidences', array_column($folderCalls, 0));
        foreach ($weekFolders as [$name, $parent]) {
            $this->assertSame('root', $parent, $name.' should be rooted at the Picker folder.');
        }
        $weekFolderIds = $weekFolders->mapWithKeys(fn (array $call): array => [$call[0] => 'folder-'.md5($call[0].'|root')]);
        $this->assertCount(3, $weekFolderIds);
        foreach (['Week 1', 'Evidences', 'Week 2', 'Week 3', 'Week 4'] as $folderName) {
            $callsForFolder = collect($folderCalls)->filter(fn (array $call): bool => $call[0] === $folderName)->values();
            $this->assertCount(3, $callsForFolder);
            $expectedParent = $folderName === 'Evidences' ? 'folder-'.md5('Week 1|root') : 'root';
            foreach ($callsForFolder as $index => [$name, $parent, $existing]) {
                $this->assertSame($expectedParent, $parent, $name.' should be rooted correctly.');
                $this->assertSame($index === 0 ? null : 'folder-'.md5($name.'|'.$parent), $existing, $name.' should reuse its tracked Drive ID.');
            }
        }
        $this->assertSame('folder-'.md5('Week 2|root'), $calls[1][1]);
        $this->assertSame('folder-'.md5('Week 3|root'), $calls[2][1]);
        $this->assertSame('folder-'.md5('Week 4|root'), $calls[3][1]);
        $this->assertSame(9, GoogleDriveExport::query()->where('user_id', $participant->id)->count());
        $this->assertSame(9, GoogleDriveExport::query()->where('user_id', $participant->id)->where('status', GoogleDriveExportStatus::Completed)->count());
        $this->assertSame(4, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'weekly_workbook_nested')->count());
        $this->assertSame(4, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'week_folder')->count());
        $this->assertSame(1, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'week_evidences_folder')->count());
    }

    public function test_ready_participant_sees_header_sync_action_using_canonical_route(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'drive_folder_id' => 'folder',
            'destination_source' => 'picker',
            'connected_at' => now(),
        ]);

        $response = $this->actingAs($participant)->get(route('profile.show'));

        $response->assertOk()
            ->assertSee('SYNC TO GOOGLE DRIVE')
            ->assertSee('action="'.route('google-drive.sync').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('method="POST"', false);
    }

    public function test_header_guides_participant_without_connection_to_profile_setup(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('SET UP GOOGLE DRIVE')
            ->assertDontSee('SYNC TO GOOGLE DRIVE');
    }

    public function test_header_requires_picker_destination_and_does_not_treat_legacy_link_as_ready(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant', 'drive_link' => 'https://drive.google.com/drive/folders/legacy']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'destination_source' => 'legacy',
            'connected_at' => now(),
        ]);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('CHOOSE DRIVE FOLDER')
            ->assertDontSee('SYNC TO GOOGLE DRIVE');
    }

    public function test_active_sync_is_non_clickable_in_header(): void
    {
        $this->configureDrive();
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'drive_folder_id' => 'folder',
            'destination_source' => 'picker',
            'connected_at' => now(),
        ]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'folder', 'status' => GoogleDriveSyncStatus::InProgress]);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('SYNCING...')
            ->assertSee('disabled', false)
            ->assertDontSee('SYNC TO GOOGLE DRIVE');
    }

    public function test_coach_does_not_see_participant_sync_action(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('SYNC TO GOOGLE DRIVE')
            ->assertDontSee('SET UP GOOGLE DRIVE');
    }

    public function test_drive_setup_is_required_before_starting_an_official_activity(): void
    {
        $this->configureDrive();
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        AttendanceSession::create(['user_id' => $participant->id, 'check_in_at' => now()]);
        $week = TrainingWeek::query()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 2)->where('is_active', true)->firstOrFail();

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('google_drive_setup');
    }

    public function test_legacy_drive_link_does_not_satisfy_activity_readiness(): void
    {
        $this->configureDrive();
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant', 'drive_link' => 'https://drive.google.com/drive/folders/legacy']);
        $week = TrainingWeek::query()->where('week_number', 1)->firstOrFail();
        $activity = $week->activities()->where('position', 2)->where('is_active', true)->firstOrFail();

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertRedirect(route('dashboard'));
    }
}
