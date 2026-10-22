<?php

namespace Tests\Feature;

use App\Enums\GateEvaluationStatus;
use App\Enums\GoogleDriveExportStatus;
use App\Enums\ReviewDecision;
use App\Jobs\ExportActivityJob;
use App\Jobs\ExportDailyLogsJob;
use App\Jobs\ExportSummaryReportsJob;
use App\Jobs\SyncTrainingDataJob;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\ApplicationSetting;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveExport;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\GoogleDriveConnectionService;
use App\Services\GoogleDriveService;
use App\Services\TrainingExportService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class GoogleDriveExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_log_csv_uses_participant_owned_week_data(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 2))->firstOrFail();
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => '2026-09-12', 'content' => 'Validated OTP flow']);
        $csv = app(TrainingExportService::class)->dailyLogs($participant, 2);
        $this->assertStringContainsString('Daily Log ID', $csv);
        $this->assertStringContainsString('Training Week', $csv);
        $this->assertStringContainsString('Validated OTP flow', $csv);
        $this->assertStringNotContainsString('another participant', $csv);
    }

    public function test_gate_progress_csv_prints_the_participant_status_of_each_gate_or_not_evaluated(): void
    {
        $participant = User::factory()->create(['name' => 'Gina']);
        $program = Program::factory()->create();
        $gates = collect([2, 3, 4])->map(fn (int $weekNumber): TrainingGate => TrainingGate::factory()
            ->for(TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]), 'week')
            ->create(['title' => "Gate{$weekNumber}"]));
        GateEvaluation::factory()->for($gates[0], 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::Passed]);
        GateEvaluation::factory()->for($gates[1], 'gate')->for($participant, 'participant')->create(['status' => GateEvaluationStatus::NeedsRevision]);
        GateEvaluation::factory()->for($gates[2], 'gate')->for(User::factory(), 'participant')->create(['status' => GateEvaluationStatus::Failed]);

        $csv = app(TrainingExportService::class)->gateProgress($participant);

        $this->assertSame("Participant,Week,Gate,Status\nGina,2,Gate2,passed\nGina,3,Gate3,needs_revision\nGina,4,Gate4,\"not evaluated\"\n", $csv);
    }

    public function test_activity_report_csv_prints_the_reviewer_and_decision_of_the_latest_review(): void
    {
        $participant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'failed', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        SubmissionReview::factory()->for($version, 'submissionVersion')->create(['decision' => ReviewDecision::NeedsRevision, 'reviewed_at' => now()->subDay()]);
        SubmissionReview::factory()->for($version, 'submissionVersion')->for(User::factory()->create(['role' => 'coach', 'name' => 'Rae Reviewer']), 'reviewer')->create(['decision' => ReviewDecision::Rejected, 'reviewed_at' => now()]);

        $csv = app(TrainingExportService::class)->activity($participant, $submission->activity_id);

        $this->assertStringContainsString("Metadata,Reviewer,\"Rae Reviewer\"\nMetadata,\"Review Decision\",rejected\n", $csv);
    }

    public function test_manual_daily_log_export_requires_owned_connection(): void
    {
        Queue::fake();
        config(['services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/callback']);
        ApplicationSetting::create(['key' => 'google_drive.enabled', 'value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_id', 'value' => 'client', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.redirect_uri', 'value' => 'https://example.test/callback', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_secret', 'value' => Crypt::encryptString('secret'), 'is_encrypted' => true]);
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/folder']);
        $this->actingAs($participant)->post(route('daily-logs.export'), ['week' => 2])->assertRedirect()->assertSessionHasErrors('google_drive');
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'a', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'folder']);
        Http::fake(['https://www.googleapis.com/drive/v3/files/folder*' => Http::response(['id' => 'folder', 'mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => true]], 200)]);
        $this->assertTrue(app(GoogleDriveConnectionService::class)->enabled());
        $this->actingAs($participant)->post(route('daily-logs.export'), ['week' => 2])->assertRedirect()->assertSessionHas('status');
        Queue::assertPushed(SyncTrainingDataJob::class);
    }

    public function test_deleted_drive_csv_is_recreated_instead_of_failing(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'google_account_id' => 'account',
            'google_email' => 'p@example.com',
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'folder',
        ]);

        Http::fakeSequence()
            ->push(['files' => [['id' => 'deleted-file']]])
            ->pushStatus(404)
            ->push(['id' => 'recreated-file']);

        $id = app(GoogleDriveService::class)->upsertCsv($connection, 'Activity Report.csv', "header\nvalue\n", 'activity-folder');

        $this->assertSame('recreated-file', $id);
        Http::assertSentCount(3);
    }

    public function test_successful_workbook_update_without_projected_id_preserves_tracked_identity(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'folder',
        ]);

        Http::fakeSequence()
            ->push(['id' => 'managed-file', 'parents' => ['folder'], 'trashed' => false])
            ->push([]);

        $id = app(GoogleDriveService::class)->uploadFile($connection, 'Week 2.xlsx', 'updated', 'folder', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'managed-file');

        $this->assertSame('managed-file', $id);
        Http::assertSentCount(2);
    }

    public function test_tracked_file_with_legacy_parent_is_not_reused_for_nested_hierarchy(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
        ]);

        Http::fakeSequence()
            ->push(['id' => 'legacy-file', 'parents' => ['root'], 'trashed' => false])
            ->push(['files' => []])
            ->push(['id' => 'new-file']);

        $id = app(GoogleDriveService::class)->uploadFile($connection, 'Week 2.xlsx', 'updated', 'week-2-folder', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'legacy-file');

        $this->assertSame('new-file', $id);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_upload_file_recovers_existing_canonical_workbook_when_tracking_is_missing(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
        ]);
        Http::fakeSequence()
            ->push(['files' => [['id' => 'canonical-week1', 'name' => 'Week 1.xlsx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']]])
            ->push(['id' => 'canonical-week1']);

        $id = app(GoogleDriveService::class)->uploadFile($connection, 'Week 1.xlsx', 'updated', 'week-1-folder', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame('canonical-week1', $id);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), '/upload/drive/v3/files'));
    }

    public function test_upload_file_recovers_canonical_workbook_after_stale_tracking_id(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
        ]);
        Http::fakeSequence()
            ->pushStatus(404)
            ->push(['files' => [['id' => 'canonical-week1', 'name' => 'Week 1.xlsx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']]])
            ->push(['id' => 'canonical-week1']);

        $id = app(GoogleDriveService::class)->uploadFile($connection, 'Week 1.xlsx', 'updated', 'week-1-folder', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'stale-id');

        $this->assertSame('canonical-week1', $id);
        Http::assertSentCount(3);
    }

    public function test_upload_file_creates_one_workbook_when_drive_folder_has_no_canonical_file(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
        ]);
        Http::fakeSequence()->push(['files' => []])->push(['id' => 'new-week1']);

        $id = app(GoogleDriveService::class)->uploadFile($connection, 'Week 1.xlsx', 'initial', 'week-1-folder', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame('new-week1', $id);
        Http::assertSentCount(2);
    }

    public function test_canonical_workbook_discovery_is_scoped_to_the_expected_week_folder(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'participant-root',
        ]);
        Http::fakeSequence()->push(['files' => [['id' => 'week-a-file', 'name' => 'Week 1.xlsx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']]])->push(['id' => 'week-a-file']);

        $this->assertSame('week-a-file', app(GoogleDriveService::class)->uploadFile($connection, 'Week 1.xlsx', 'updated', 'participant-week-1', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'participant-week-1'));
    }

    public function test_tracked_legacy_evidence_folder_with_wrong_parent_is_not_reused(): void
    {
        $participant = User::factory()->create();
        $connection = GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'root']);
        Http::fakeSequence()
            ->push(['id' => 'legacy-evidence', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['root'], 'trashed' => false])
            ->push(['files' => [['id' => 'nested-evidence']]]);

        $id = app(GoogleDriveService::class)->ensureFolder($connection, 'Evidences', 'week-2-folder', 'legacy-evidence');

        $this->assertSame('nested-evidence', $id);
        Http::assertSentCount(2);
    }

    public function test_legacy_week_evidence_folder_names_are_rejected_before_drive_creation(): void
    {
        $connection = GoogleDriveConnection::create([
            'user_id' => User::factory()->create()->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
        ]);

        Http::fake();
        try {
            app(GoogleDriveService::class)->ensureFolder($connection, 'Week 2 - Evidences', 'root');
            $this->fail('Legacy week evidence folder names must be rejected.');
        } catch (\InvalidArgumentException) {
            // Expected: no legacy folder creation request is allowed.
        }
        Http::assertNothingSent();
    }

    public function test_participant_activity_and_summary_exports_use_authenticated_owner(): void
    {
        Queue::fake();
        $this->seed(QaTrainingProgramSeeder::class);
        config(['services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/callback']);
        ApplicationSetting::create(['key' => 'google_drive.enabled', 'value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_id', 'value' => 'client', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.redirect_uri', 'value' => 'https://example.test/callback', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_secret', 'value' => Crypt::encryptString('secret'), 'is_encrypted' => true]);
        $participant = User::factory()->create(['training_path' => 'manual', 'drive_link' => 'https://drive.google.com/drive/folders/folder']);
        $other = User::factory()->create(['training_path' => 'manual']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'a', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'folder']);
        Http::fake(['https://www.googleapis.com/drive/v3/files/folder*' => Http::response(['id' => 'folder', 'mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => true]], 200)]);
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 2))->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);

        $this->actingAs($participant)->post(route('training.activities.export', [$activity->week, $activity]))->assertRedirect()->assertSessionHas('status');
        Queue::assertPushed(SyncTrainingDataJob::class);
        $this->actingAs($other)->post(route('training.activities.export', [$activity->week, $activity]))->assertRedirect()->assertSessionHasErrors('google_drive');
        $this->actingAs($participant)->post(route('reports.export'))->assertRedirect()->assertSessionHas('status');
        Queue::assertPushed(SyncTrainingDataJob::class);
    }

    public function test_summary_retry_enqueues_even_when_profile_destination_is_not_accessible(): void
    {
        Queue::fake();
        config(['services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/callback']);
        ApplicationSetting::create(['key' => 'google_drive.enabled', 'value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_id', 'value' => 'client', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.redirect_uri', 'value' => 'https://example.test/callback', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_secret', 'value' => Crypt::encryptString('secret'), 'is_encrypted' => true]);
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/inaccessible']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'old-folder']);
        foreach (['training_progress', 'activity_scores', 'gate_progress'] as $scope) {
            GoogleDriveExport::create(['user_id' => $participant->id, 'export_type' => 'summary', 'scope_key' => $scope, 'status' => GoogleDriveExportStatus::Failed]);
        }
        Http::fake(['https://www.googleapis.com/*' => Http::response(['error' => ['code' => 403]], 403)]);

        $this->actingAs($participant)->post(route('reports.export'))->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(SyncTrainingDataJob::class);
        Http::assertNothingSent();
    }

    public function test_activity_job_tracks_each_evidence_file_independently_and_preserves_private_source(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual', 'drive_link' => 'https://drive.google.com/drive/folders/root']);
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 2))->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create();
        $files = collect(['one.png', 'two.png', 'three.png'])->map(fn (string $name) => SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['disk' => 'local', 'path' => "evidence/{$name}", 'original_filename' => $name, 'mime_type' => 'image/png']));
        foreach ($files as $file) {
            Storage::disk('local')->put($file->path, 'private-'.$file->original_filename);
        }
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'root']);
        GoogleDriveExport::create(['user_id' => $participant->id, 'export_type' => 'activity', 'scope_key' => (string) $activity->id, 'drive_file_id' => 'old-csv', 'destination_folder_id' => 'old-root', 'status' => GoogleDriveExportStatus::Completed]);
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('participantTrainingRoot')->once()->andReturn(['root', 'training-root']);
        $drive->shouldReceive('ensureFolder')->times(3)->andReturn('week-folder', 'activity-folder', 'evidence-folder');
        $drive->shouldReceive('upsertCsv')->once()->andReturn('csv-id');
        $drive->shouldReceive('uploadFile')->times(3)->andReturnUsing(function (GoogleDriveConnection $received, string $name): string {
            return $name === 'two.png' ? throw new \RuntimeException('temporary Drive failure') : 'drive-'.$name;
        });
        $exports = Mockery::mock(TrainingExportService::class);
        $exports->shouldReceive('activity')->once()->andReturn("Participant,Activity\nMaricel,Test\n");

        try {
            (new ExportActivityJob($version->id))->handle($exports, $drive);
        } catch (\Throwable) {
            // Evidence failures are retriable and are recorded independently.
        }

        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'activity', 'status' => 'completed', 'drive_file_id' => 'csv-id', 'destination_folder_id' => 'root']);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $files[0]->id, 'status' => 'completed', 'drive_file_id' => 'drive-one.png']);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $files[1]->id, 'status' => 'failed']);
        $this->assertDatabaseHas('google_drive_evidence_exports', ['submission_evidence_id' => $files[2]->id, 'status' => 'completed', 'drive_file_id' => 'drive-three.png']);
        Storage::disk('local')->assertExists($files[0]->path);
        Storage::disk('local')->assertExists($files[1]->path);
    }

    public function test_summary_exports_have_independent_tracking_and_do_not_duplicate_rows(): void
    {
        Queue::fake();
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/root']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'root']);
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('participantTrainingRoot')->once()->andReturn(['root', 'training-root']);
        $drive->shouldReceive('ensureFolder')->once()->andReturn('summary-folder');
        $drive->shouldReceive('upsertCsv')->times(3)->andReturnUsing(function (GoogleDriveConnection $connection, string $name): string {
            if ($name === 'Gate Progress.csv') {
                throw new \RuntimeException('Gate report unavailable');
            }

            return str_replace(' ', '-', strtolower($name));
        });
        $exports = Mockery::mock(TrainingExportService::class);
        $exports->shouldReceive('trainingProgress')->once()->andReturn('progress');
        $exports->shouldReceive('activityScores')->once()->andReturn('scores');
        $exports->shouldReceive('gateProgress')->once()->andReturn('gates');

        try {
            (new ExportSummaryReportsJob($participant->id))->handle($exports, $drive);
        } catch (\Throwable) {
            // The job rethrows so Laravel can retry transient API failures.
        }

        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'summary', 'scope_key' => 'training_progress', 'status' => 'completed']);
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'summary', 'scope_key' => 'activity_scores', 'status' => 'completed']);
        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'export_type' => 'summary', 'scope_key' => 'gate_progress', 'status' => 'failed']);
        $this->assertSame(3, GoogleDriveExport::query()->where('user_id', $participant->id)->where('export_type', 'summary')->count());
    }

    public function test_summary_job_persists_safe_destination_error_without_retrying(): void
    {
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/inaccessible']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'old-folder']);
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('participantTrainingRoot')->once()->andThrow(new \RuntimeException('The Google Drive folder in your Profile could not be accessed.'));
        $exports = Mockery::mock(TrainingExportService::class);

        (new ExportSummaryReportsJob($participant->id))->handle($exports, $drive);

        $this->assertDatabaseHas('google_drive_exports', ['user_id' => $participant->id, 'scope_key' => 'training_progress', 'status' => 'failed', 'error' => 'The Google Drive folder in your Profile could not be accessed. Check that your connected Google account has Editor/write access.']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_daily_log_export_enqueues_before_remote_destination_validation(): void
    {
        Queue::fake();
        config(['services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/callback']);
        ApplicationSetting::create(['key' => 'google_drive.enabled', 'value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_id', 'value' => 'client', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.redirect_uri', 'value' => 'https://example.test/callback', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_secret', 'value' => Crypt::encryptString('secret'), 'is_encrypted' => true]);
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/inaccessible']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'old-folder']);

        $this->actingAs($participant)->post(route('daily-logs.export'), ['week' => 2])->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(SyncTrainingDataJob::class);
        Http::assertNothingSent();
    }

    public function test_activity_export_enqueues_before_remote_destination_validation(): void
    {
        Queue::fake();
        $this->seed(QaTrainingProgramSeeder::class);
        config(['services.google_drive.enabled' => true, 'services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/callback']);
        ApplicationSetting::create(['key' => 'google_drive.enabled', 'value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_id', 'value' => 'client', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.redirect_uri', 'value' => 'https://example.test/callback', 'is_encrypted' => false]);
        ApplicationSetting::create(['key' => 'google_drive.client_secret', 'value' => Crypt::encryptString('secret'), 'is_encrypted' => true]);
        $participant = User::factory()->create(['training_path' => 'manual', 'drive_link' => 'https://drive.google.com/drive/folders/inaccessible']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now(), 'drive_folder_id' => 'old-folder']);
        $activity = Activity::query()->whereHas('week', fn ($query) => $query->where('week_number', 2))->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create();

        $this->actingAs($participant)->post(route('training.activities.export', [$activity->week, $activity]))->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(SyncTrainingDataJob::class);
        Http::assertNothingSent();
    }

    public function test_activity_csv_uses_dynamic_configured_columns_and_authoritative_progress_fields(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 2))->firstOrFail();
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'participant_path' => 'manual', 'position' => 1, 'configuration' => ['key' => 'feature_area', 'label' => 'Feature/Area', 'type' => 'text']]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => ['feature_area' => 'Checkout']]);

        $activityCsv = app(TrainingExportService::class)->activity($participant, $activity->id);
        $progressCsv = app(TrainingExportService::class)->trainingProgress($participant);

        $this->assertStringContainsString('Feature/Area', $activityCsv);
        $this->assertStringContainsString('Checkout', $activityCsv);
        $this->assertStringNotContainsString('Configured Activity Fields', $activityCsv);
        $this->assertStringContainsString('Week Status', $progressCsv);
        $this->assertStringContainsString('Gate Status', $progressCsv);
        $this->assertStringContainsString('Next Action', $progressCsv);
    }

    public function test_export_jobs_define_transient_retry_and_backoff_policies(): void
    {
        $this->assertSame(3, (new ExportActivityJob(1))->tries);
        $this->assertSame([60, 300], (new ExportActivityJob(1))->backoff);
        $this->assertSame(3, (new ExportDailyLogsJob(1, 2))->tries);
        $this->assertSame([60, 300], (new ExportDailyLogsJob(1, 2))->backoff);
        $this->assertSame(3, (new ExportSummaryReportsJob(1))->tries);
        $this->assertSame([60, 300], (new ExportSummaryReportsJob(1))->backoff);
    }

    public function test_activity_export_contains_detailed_latest_version_inputs_validation_data_and_evidence_metadata(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['training_path' => 'manual']);
        $activity = Activity::query()->whereHas('week', fn ($q) => $q->where('week_number', 2))->firstOrFail();
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'participant_path' => 'manual', 'position' => 99, 'configuration' => ['key' => 'long_prompt', 'label' => 'Exact Prompt', 'type' => 'textarea']]);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        $submission->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => ['long_prompt' => 'stale draft']]);
        $validation = ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => $participant->name, 'artifact_code' => 'VAL-123', 'source_status' => 'pass', 'finding' => 'Found an issue']);
        $version = $submission->versions()->create(['version_number' => 2, 'status' => 'submitted', 'validation_submission_id' => $validation->id, 'field_values' => ['long_prompt' => "Full prompt, with commas\nand multiple lines"]]);
        $evidence = SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['evidence_type' => 'url', 'title' => 'Test report', 'url' => 'https://example.test/report']);
        $evidence->googleDriveExport()->create(['status' => 'completed', 'drive_file_id' => 'drive-evidence']);

        $csv = app(TrainingExportService::class)->activity($participant, $activity->id);

        $this->assertStringContainsString('Section,Field', $csv);
        $this->assertStringContainsString('Exact Prompt', $csv);
        $this->assertStringContainsString('Full prompt, with commas', $csv);
        $this->assertStringNotContainsString('stale draft', $csv);
        $this->assertStringContainsString('Artifact Code', $csv);
        $this->assertStringContainsString('VAL-123', $csv);
        $this->assertStringContainsString('"Validation Lab","Source Status",pass', $csv);
        $this->assertStringContainsString('https://example.test/report', $csv);
        $this->assertStringContainsString('drive-evidence', $csv);
        $this->assertStringContainsString('Evidence,"Export Status",completed', $csv);
    }

    public function test_profile_folder_is_parsed_and_inaccessible_folder_returns_safe_error(): void
    {
        $participant = User::factory()->create(['drive_link' => 'https://drive.google.com/drive/folders/profile-folder']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'p@example.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now()]);
        $this->assertSame('profile-folder', app(GoogleDriveConnectionService::class)->destinationFolderId($participant));
        Http::fake(['https://www.googleapis.com/drive/v3/files/profile-folder*' => Http::response(['error' => ['code' => 403]], 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Google Drive folder in your Profile could not be accessed.');
        app(GoogleDriveConnectionService::class)->validateExportDestination($participant);
    }
}
