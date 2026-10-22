<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveExportStatus;
use App\Jobs\SyncTrainingDataJob;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\ApplicationSetting;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveExport;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\GoogleDriveConnectionService;
use App\Services\GoogleDriveService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleDriveExportTest extends TestCase
{
    use RefreshDatabase;

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
