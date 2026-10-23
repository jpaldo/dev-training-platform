<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveExportStatus;
use App\Enums\GoogleDriveSyncStatus;
use App\Jobs\SyncTrainingDataJob;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\GoogleDriveSync;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
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

    /**
     * A participant whose Google Drive connection has the Picker folder `root` as its destination.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function connectedParticipant(array $attributes = []): User
    {
        $participant = User::factory()->create(['role' => 'participant', ...$attributes]);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);

        return $participant;
    }

    private function queuedSync(User $participant): GoogleDriveSync
    {
        return GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
    }

    /**
     * A Drive double that records every folder and upload call as [name, parent, existing ID] and each uploaded
     * file's content by its ID. Folder and file IDs are their path, "{parent}/{name}", so a call that reuses its
     * tracked ID passes that path as the existing ID.
     *
     * @param  list<array{0: string, 1: string, 2: ?string}>  $folders
     * @param  list<array{0: string, 1: string, 2: ?string}>  $uploads
     * @param  array<string, string>  $contents
     */
    private function recordingDrive(array &$folders, array &$uploads, array &$contents): GoogleDriveService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->andReturn('root');
        $drive->shouldReceive('ensureFolder')->andReturnUsing(function (GoogleDriveConnection $connection, string $name, string $parent, ?string $existing = null) use (&$folders): string {
            $folders[] = [$name, $parent, $existing];

            return "{$parent}/{$name}";
        });
        $drive->shouldReceive('uploadFile')->andReturnUsing(function (GoogleDriveConnection $connection, string $name, string $content, string $parent, string $mime, ?string $existing = null) use (&$uploads, &$contents): string {
            $uploads[] = [$name, $parent, $existing];
            $contents["{$parent}/{$name}"] = $content;

            return "{$parent}/{$name}";
        });

        return $drive;
    }

    private function runSync(GoogleDriveSync $sync, GoogleDriveService $drive): void
    {
        (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, Mockery::mock(TrainingExportService::class), app(TrainingWorkbookService::class), app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));
    }

    public function test_week_workbook_contains_one_sheet_per_activity_and_no_daily_logs(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        foreach (['AI Output Error Detection', 'Validation Checklist', 'Pre-acceptance Check'] as $position => $title) {
            Activity::factory()->create(['training_week_id' => $week->id, 'title' => $title, 'position' => $position + 1]);
        }
        $otherProgramWeek = TrainingWeek::factory()->create(['week_number' => 2]);
        Activity::factory()->create(['training_week_id' => $otherProgramWeek->id, 'title' => 'Another program activity']);
        $session = TrainingSession::factory()->forWeek($week)->create();

        $content = app(TrainingWorkbookService::class)->forWeek($participant, $session, $week);
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
        $this->assertStringNotContainsString('Another program activity', $workbook);
        $this->assertStringNotContainsString('Daily Logs', $workbook);
    }

    public function test_week_workbook_without_activities_has_one_header_only_activities_sheet(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $week = TrainingWeek::factory()->create(['week_number' => 1]);
        $session = TrainingSession::factory()->forWeek($week)->create();

        $content = app(TrainingWorkbookService::class)->forWeek($participant, $session, $week);
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
        $this->assertStringContainsString('>Employee ID</t>', $sheet);
        $this->assertStringNotContainsString('QA Path', $sheet);
    }

    public function test_participant_week_workbook_contains_no_internal_scoring_or_path_columns(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 1))->firstOrFail();
        $session = TrainingSession::factory()->forWeek($activity->week)->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session, 'trainingSession')->create();
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => []]);
        $content = app(TrainingWorkbookService::class)->forWeek($participant, $session, $activity->week);
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
        $this->assertStringNotContainsString('QA Path', $xml);
        $this->assertStringNotContainsString('>Manual</t>', $xml);
    }

    public function test_week_workbook_prefers_the_latest_submitted_version_over_a_newer_revision_draft(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $activity = Activity::query()->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 1))->firstOrFail();
        $session = TrainingSession::factory()->forWeek($activity->week)->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session, 'trainingSession')->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 1, 'status' => 'submitted', 'participant_note' => 'Submitted note']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => 2, 'status' => 'draft', 'participant_note' => 'Revision draft note']);

        $xml = $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, $session, $activity->week));

        $this->assertStringContainsString('Submitted note', $xml);
        $this->assertStringNotContainsString('Revision draft note', $xml);
    }

    public function test_week_workbooks_print_the_submission_status_and_configured_answers_of_the_activity(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $participant = User::factory()->create();
        $weeks = TrainingWeek::query()->whereIn('week_number', [1, 2])->orderBy('week_number')->get()->keyBy('week_number');
        $session = TrainingSession::factory()->forWeek($weeks[1])->create();
        foreach ([1 => 'under_review', 2 => 'needs_revision'] as $weekNumber => $status) {
            $activity = Activity::query()->where('training_week_id', $weeks[$weekNumber]->id)->where('is_active', true)->orderBy('position')->firstOrFail();
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session, 'trainingSession')->create(['status' => $status]);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => $weekNumber === 2 ? ['deliverable_url' => 'https://github.com/example/checkout'] : []]);
        }

        $this->assertStringContainsString('<t xml:space="preserve">under_review</t>', $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, $session, $weeks[1])));
        $weekTwoWorkbook = $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, $session, $weeks[2]));
        $this->assertStringContainsString('<t xml:space="preserve">needs_revision</t>', $weekTwoWorkbook);
        $this->assertStringContainsString('<t xml:space="preserve">Deliverable link</t>', $weekTwoWorkbook);
        $this->assertStringContainsString('<t xml:space="preserve">https://github.com/example/checkout</t>', $weekTwoWorkbook);
    }

    public function test_week_workbook_shows_the_employee_id_and_only_the_latest_attempt_in_its_session(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'employee_id' => 'EMP-004242']);
        $week = TrainingWeek::factory()->create(['week_number' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Build the API']);
        [$sessionA, $sessionB] = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->count(2)->create();
        foreach ([[$sessionA, 1, 'First attempt in A'], [$sessionA, 2, 'Second attempt in A'], [$sessionB, 1, 'Attempt in B']] as [$session, $attemptNumber, $note]) {
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session, 'trainingSession')->create(['attempt_number' => $attemptNumber, 'status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'participant_note' => $note]);
        }

        $workbookA = $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, $sessionA, $week));
        $workbookB = $this->workbookText(app(TrainingWorkbookService::class)->forWeek($participant, $sessionB, $week));

        $this->assertStringContainsString('<c r="B1" t="inlineStr"><is><t xml:space="preserve">Employee ID</t></is></c>', $workbookA);
        $this->assertStringContainsString('<c r="B2" t="inlineStr"><is><t xml:space="preserve">EMP-004242</t></is></c>', $workbookA);
        $this->assertStringContainsString('>Second attempt in A</t>', $workbookA);
        $this->assertStringNotContainsString('First attempt in A', $workbookA);
        $this->assertStringNotContainsString('Attempt in B', $workbookA);
        $this->assertStringContainsString('<c r="B2" t="inlineStr"><is><t xml:space="preserve">EMP-004242</t></is></c>', $workbookB);
        $this->assertStringContainsString('>Attempt in B</t>', $workbookB);
        $this->assertStringNotContainsString('attempt in A', $workbookB);
    }

    public function test_week_workbook_query_count_does_not_grow_with_activities(): void
    {
        $this->assertSame($this->workbookQueryCount(1), $this->workbookQueryCount(3));
    }

    /**
     * Build a week with the given number of activities, each with an answer field and a submitted attempt in the
     * session, and count the queries of building its workbook.
     */
    private function workbookQueryCount(int $activityCount): int
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $week = TrainingWeek::factory()->create();
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        foreach (range(1, $activityCount) as $index) {
            $activity = Activity::factory()->for($week, 'week')->create();
            ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'configuration' => ['key' => "answer_{$index}", 'label' => "Answer {$index}"]]);
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session, 'trainingSession')->create(['status' => 'submitted']);
            SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => ["answer_{$index}" => 'Done']]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TrainingWorkbookService::class)->forWeek($participant, $session, $week);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
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

    public function test_sync_does_not_adopt_tracking_from_before_training_sessions(): void
    {
        $participant = $this->connectedParticipant();
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create(['title' => 'November cohort']);
        $oldTracking = collect([
            ['weekly_workbook', 'legacy-root-workbook'],
            ['week_folder', 'root/Week 2'],
            ['weekly_workbook_nested', 'root/Week 2/Week 2.xlsx'],
        ])->map(fn (array $row): GoogleDriveExport => GoogleDriveExport::create(['user_id' => $participant->id, 'export_type' => $row[0], 'scope_key' => '2', 'destination_folder_id' => 'root', 'drive_file_id' => $row[1], 'status' => GoogleDriveExportStatus::Completed]));
        $folders = [];
        $uploads = [];
        $contents = [];

        $this->runSync($this->queuedSync($participant), $this->recordingDrive($folders, $uploads, $contents));

        $this->assertSame([['November cohort', 'root', null], ['Week 2', 'root/November cohort', null]], $folders);
        $this->assertSame([['Week 2.xlsx', 'root/November cohort/Week 2', null]], $uploads);
        foreach ($oldTracking as $row) {
            $this->assertSame($row->drive_file_id, $row->fresh()->drive_file_id);
            $this->assertSame('2', $row->fresh()->scope_key);
        }
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'weekly_workbook_nested', 'scope_key' => "session:{$session->id}:week:{$week->id}", 'drive_file_id' => 'root/November cohort/Week 2/Week 2.xlsx']);
    }

    public function test_full_sync_exports_the_evidence_of_the_latest_submitted_version_and_of_a_newer_revision_draft(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        $activity = Activity::factory()->create(['training_week_id' => $week->id, 'position' => 1, 'title' => 'Validation Checklist']);
        TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
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
        TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => 'evidence/activity-proof.png', 'original_filename' => 'activity-proof.png', 'mime_type' => 'image/png']);
        Storage::disk('local')->put($evidence->path, 'activity evidence');
        $connection = GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->once()->andReturn('root');
        $drive->shouldReceive('ensureFolder')->times(4)->andReturnUsing(function ($received, string $name, string $parent, ?string $existing = null): string {
            return 'folder-'.md5($name.'|'.$parent);
        });
        $drive->shouldReceive('uploadFile')->times(2)->andReturnUsing(fn ($received, $name) => 'drive-'.$name);
        $exports = Mockery::mock(TrainingExportService::class);
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->once()->andReturn('xlsx');

        app()->instance(GoogleDriveService::class, $drive);
        (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, $exports, $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        $this->assertSame(GoogleDriveSyncStatus::Completed, $sync->fresh()->status);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $evidence->id, 'status' => 'completed', 'destination_folder_id' => 'root']);
        $this->assertSame('completed', $sync->fresh()->component_results['Evidences']['status']);
        $this->assertSame(1, $sync->fresh()->component_results['Evidences']['completed']);
        $this->assertSame(0, $sync->fresh()->component_results['Evidences']['failed']);
    }

    public function test_full_sync_leaves_a_chosen_weekly_video_in_the_participants_drive(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $video = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create(['name' => 'week-2-walkthrough.mp4']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'drive_folder_id' => 'root', 'destination_source' => 'picker', 'connected_at' => now()]);
        $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'root', 'status' => GoogleDriveSyncStatus::Enqueued]);
        $uploads = [];
        $folders = [];

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('validateDestination')->once()->andReturn('root');
        $drive->shouldReceive('ensureFolder')->andReturnUsing(function (GoogleDriveConnection $received, string $name, string $parent) use (&$folders): string {
            $folders[] = $name;

            return 'folder-'.md5($name.'|'.$parent);
        });
        $drive->shouldReceive('uploadFile')->andReturnUsing(function (GoogleDriveConnection $received, string $name) use (&$uploads): string {
            $uploads[] = $name;

            return 'drive-'.$name;
        });
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->once()->andReturn('xlsx');

        (new SyncTrainingDataJob($sync->id))->handle(app(GoogleDriveConnectionService::class), $drive, Mockery::mock(TrainingExportService::class), $workbooks, app(ExportLifecycleService::class), app(TrainingSyncCoordinator::class));

        $sync->refresh();
        $this->assertSame(['Week 2.xlsx'], $uploads);
        $this->assertNotContains('Video Recording', $folders);
        $this->assertSame(GoogleDriveSyncStatus::Completed, $sync->status);
        $this->assertSame(1, $sync->successful_components);
        $this->assertSame(0, $sync->failed_components);
        $this->assertArrayNotHasKey('Video Recording', $sync->component_results);
        $this->assertDatabaseMissing('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'weekly_video_folder']);
        $this->assertSame($video->drive_file_id, $video->fresh()->drive_file_id);
    }

    public function test_sync_reconciles_five_evidence_files_with_partial_drive_tracking_and_reuses_ids(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create(['role' => 'participant']);
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->create(['program_id' => $program->id, 'week_number' => 2]);
        $activity = Activity::factory()->create(['training_week_id' => $week->id, 'position' => 2, 'title' => 'AI Output Error Detection']);
        TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
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
        $drive->shouldReceive('ensureFolder')->times(8)->andReturnUsing(fn ($received, string $name, string $parent, ?string $existing = null): string => 'folder-'.md5($name.'|'.$parent));
        $drive->shouldReceive('uploadFile')->times(12)->andReturnUsing(function ($received, string $name, string $content, string $parent, string $mime, ?string $existing = null) use (&$calls): string {
            $calls[] = [$name, $existing];

            return 'drive-'.$name;
        });
        $workbooks = Mockery::mock(TrainingWorkbookService::class);
        $workbooks->shouldReceive('forWeek')->twice()->andReturn('xlsx');

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
        $participant = $this->connectedParticipant();
        $program = Program::factory()->create();
        $weeks = collect(range(1, 4))->map(fn (int $number): TrainingWeek => TrainingWeek::factory()->for($program)->create(['week_number' => $number]));
        TrainingSession::factory()->forWeek($weeks->first())->withParticipants($participant)->create(['title' => 'November cohort']);
        $folders = [];
        $uploads = [];
        $contents = [];
        $drive = $this->recordingDrive($folders, $uploads, $contents);

        foreach (range(1, 3) as $run) {
            $this->runSync($this->queuedSync($participant), $drive);
        }

        $expectedFolders = [['November cohort', 'root'], ['Week 1', 'root/November cohort'], ['Week 2', 'root/November cohort'], ['Week 3', 'root/November cohort'], ['Week 4', 'root/November cohort']];
        $expectedUploads = collect(range(1, 4))->map(fn (int $number): array => ["Week {$number}.xlsx", "root/November cohort/Week {$number}"])->all();
        $this->assertCount(15, $folders);
        $this->assertCount(12, $uploads);
        foreach (range(0, 2) as $run) {
            foreach (array_slice($folders, $run * 5, 5) as $index => [$name, $parent, $existing]) {
                $this->assertSame($expectedFolders[$index], [$name, $parent]);
                $this->assertSame($run === 0 ? null : "{$parent}/{$name}", $existing, "{$parent}/{$name} should reuse its tracked Drive ID.");
            }
            foreach (array_slice($uploads, $run * 4, 4) as $index => [$name, $parent, $existing]) {
                $this->assertSame($expectedUploads[$index], [$name, $parent]);
                $this->assertSame($run === 0 ? null : "{$parent}/{$name}", $existing, "{$name} should be updated using its tracked Drive ID.");
            }
        }
        $this->assertNotContains('Evidences', array_column($folders, 0));
        $this->assertSame(9, GoogleDriveExport::query()->where('user_id', $participant->id)->where('status', GoogleDriveExportStatus::Completed)->count());
        $this->assertSame(1, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'session_folder')->count());
        $this->assertSame(4, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'week_folder')->count());
        $this->assertSame(4, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'weekly_workbook_nested')->count());
    }

    public function test_a_participant_in_two_sessions_gets_a_folder_per_session_with_only_that_sessions_work(): void
    {
        Storage::fake('local');
        $participant = $this->connectedParticipant(['employee_id' => 'EMP-004242']);
        $program = Program::factory()->create();
        $weekOne = TrainingWeek::factory()->for($program)->create(['week_number' => 1]);
        TrainingWeek::factory()->for($program)->create(['week_number' => 2]);
        $activity = Activity::factory()->for($weekOne, 'week')->create(['title' => 'Build the API']);
        $november = TrainingSession::factory()->forWeek($weekOne)->withParticipants($participant)->create(['title' => 'November cohort']);
        $december = TrainingSession::factory()->forWeek($weekOne)->state(['weeks_count' => 1])->upcoming()->withParticipants($participant)->create(['title' => 'December cohort']);
        foreach ([[$november, 'Work in November'], [$december, 'Work in December']] as [$session, $note]) {
            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->for($session, 'trainingSession')->create(['status' => 'submitted']);
            $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'participant_note' => $note]);
        }
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'file', 'disk' => 'local', 'path' => 'evidence/december-proof.png', 'original_filename' => 'december-proof.png', 'mime_type' => 'image/png']);
        Storage::disk('local')->put($evidence->path, 'december evidence');
        $folders = [];
        $uploads = [];
        $contents = [];
        $drive = $this->recordingDrive($folders, $uploads, $contents);
        $firstSync = $this->queuedSync($participant);

        $this->runSync($firstSync, $drive);

        $this->assertSame([
            ['November cohort', 'root', null],
            ['Week 1', 'root/November cohort', null],
            ['Week 2', 'root/November cohort', null],
            ['December cohort', 'root', null],
            ['Week 1', 'root/December cohort', null],
            ['Evidences', 'root/December cohort/Week 1', null],
            ['Build the API', 'root/December cohort/Week 1/Evidences', null],
        ], $folders);
        $this->assertSame([
            ['Week 1.xlsx', 'root/November cohort/Week 1', null],
            ['Week 2.xlsx', 'root/November cohort/Week 2', null],
            ['Week 1.xlsx', 'root/December cohort/Week 1', null],
            ['december-proof.png', 'root/December cohort/Week 1/Evidences/Build the API', null],
        ], $uploads);
        $novemberWorkbook = $this->workbookText($contents['root/November cohort/Week 1/Week 1.xlsx']);
        $decemberWorkbook = $this->workbookText($contents['root/December cohort/Week 1/Week 1.xlsx']);
        $this->assertStringContainsString('>Work in November</t>', $novemberWorkbook);
        $this->assertStringNotContainsString('Work in December', $novemberWorkbook);
        $this->assertStringContainsString('>Work in December</t>', $decemberWorkbook);
        $this->assertStringNotContainsString('Work in November', $decemberWorkbook);
        $this->assertStringContainsString('>EMP-004242</t>', $decemberWorkbook);
        $firstSync->refresh();
        $this->assertSame(GoogleDriveSyncStatus::Completed, $firstSync->status);
        $this->assertSame(['November cohort / Week 1.xlsx', 'November cohort / Week 2.xlsx', 'December cohort / Week 1.xlsx', 'Evidences'], array_keys($firstSync->component_results));
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'session_folder', 'scope_key' => "session:{$november->id}", 'drive_file_id' => 'root/November cohort']);
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'weekly_workbook_nested', 'scope_key' => "session:{$december->id}:week:{$weekOne->id}", 'drive_file_id' => 'root/December cohort/Week 1/Week 1.xlsx']);
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'evidence_activity_folder', 'scope_key' => "session:{$december->id}:week:{$weekOne->id}:activity:{$activity->id}"]);
        $trackingRows = GoogleDriveExport::query()->count();

        $folders = [];
        $uploads = [];
        $this->runSync($this->queuedSync($participant), $drive);

        $this->assertCount(7, $folders);
        foreach ($folders as [$name, $parent, $existing]) {
            $this->assertSame("{$parent}/{$name}", $existing, "{$parent}/{$name} should reuse its tracked Drive ID.");
        }
        $this->assertCount(4, $uploads);
        foreach ($uploads as [$name, $parent, $existing]) {
            $this->assertSame("{$parent}/{$name}", $existing, "{$parent}/{$name} should be updated in place.");
        }
        $this->assertSame($trackingRows, GoogleDriveExport::query()->count());
        $this->assertDatabaseCount('google_drive_evidence_exports', 1);
    }

    public function test_sessions_with_the_same_title_get_folders_told_apart_by_dates_then_id(): void
    {
        $participant = $this->connectedParticipant();
        $week = TrainingWeek::factory()->create(['week_number' => 1]);
        $sessions = TrainingSession::factory()->forWeek($week)->withParticipants($participant);
        $november = $sessions->create(['title' => 'L4 Accelerator']);
        $solo = $sessions->create(['title' => 'Solo run']);
        [$decemberA, $decemberB] = $sessions->upcoming()->count(2)->create(['title' => 'L4 Accelerator']);
        $folders = [];
        $uploads = [];
        $contents = [];

        $this->runSync($this->queuedSync($participant), $this->recordingDrive($folders, $uploads, $contents));

        $sessionFolders = collect($folders)->where(1, 'root')->pluck(0)->all();
        $this->assertSame([
            "L4 Accelerator ({$november->dateRangeLabel()})",
            'Solo run',
            "L4 Accelerator ({$decemberA->dateRangeLabel()}, #{$decemberA->id})",
            "L4 Accelerator ({$decemberB->dateRangeLabel()}, #{$decemberB->id})",
        ], $sessionFolders);
    }

    public function test_sync_of_a_participant_in_no_session_uploads_nothing(): void
    {
        $participant = $this->connectedParticipant();
        TrainingSession::factory()->forWeek(TrainingWeek::factory()->create(['week_number' => 1]))->create();
        $folders = [];
        $uploads = [];
        $contents = [];
        $sync = $this->queuedSync($participant);

        $this->runSync($sync, $this->recordingDrive($folders, $uploads, $contents));

        $this->assertSame([], $folders);
        $this->assertSame([], $uploads);
        $this->assertSame(GoogleDriveSyncStatus::Completed, $sync->fresh()->status);
        $this->assertSame(0, $sync->fresh()->total_components);
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
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))
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
        $session = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();

        $this->actingAs($participant)->get(route('training.activities.show', [$session, $week, $activity]))
            ->assertRedirect(route('dashboard'));
    }
}
