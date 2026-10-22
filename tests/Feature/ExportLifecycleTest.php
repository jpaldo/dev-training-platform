<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveExportStatus;
use App\Enums\GoogleDriveSyncStatus;
use App\Jobs\GenerateParticipantNotificationsJob;
use App\Jobs\SyncTrainingDataJob;
use App\Models\GoogleDriveExport;
use App\Models\GoogleDriveSync;
use App\Models\User;
use App\Services\ExportLifecycleService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function userId(): int
    {
        return User::factory()->create(['role' => 'participant'])->id;
    }

    public function test_profile_folder_access_failure_is_terminal_with_a_write_access_hint(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturn(1);
        $exception = new RuntimeException('The Google Drive folder in your Profile could not be accessed.');
        $service = app(ExportLifecycleService::class);

        $this->assertFalse($service->shouldRetry($job, 3, $exception));
        $this->assertStringContainsString('Editor/write access', $service->failureMessage('Google Drive sync failed.', $exception));
    }

    public function test_stale_active_exports_are_reconciled_when_queue_is_empty(): void
    {
        $userId = $this->userId();
        $record = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'activity_scores', 'status' => GoogleDriveExportStatus::Retrying, 'last_attempt_at' => Carbon::now()->subMinutes(15)]);

        $count = app(ExportLifecycleService::class)->reconcileStale($userId);

        $this->assertSame(1, $count);
        $this->assertSame(GoogleDriveExportStatus::Failed, $record->fresh()->status);
        $this->assertSame('Export stopped before completion. Please retry the export.', $record->fresh()->error);
    }

    public function test_stale_enqueued_and_in_progress_exports_are_reconciled_but_recent_and_terminal_states_are_untouched(): void
    {
        $userId = $this->userId();
        $old = Carbon::now()->subMinutes(15);
        $recent = Carbon::now()->subMinutes(2);
        $enqueued = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'training_progress', 'status' => GoogleDriveExportStatus::Enqueued, 'last_attempt_at' => $old]);
        $inProgress = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'activity_scores', 'status' => GoogleDriveExportStatus::InProgress, 'last_attempt_at' => $old]);
        $recentRetry = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'gate_progress', 'status' => GoogleDriveExportStatus::Retrying, 'last_attempt_at' => $recent]);
        $completed = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'daily_logs', 'scope_key' => '2', 'status' => GoogleDriveExportStatus::Completed, 'last_attempt_at' => $old, 'last_synced_at' => $old]);
        $failed = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'activity', 'scope_key' => '1', 'status' => GoogleDriveExportStatus::Failed, 'last_attempt_at' => $old]);

        $count = app(ExportLifecycleService::class)->reconcileStale($userId);

        $this->assertSame(2, $count);
        $this->assertSame(GoogleDriveExportStatus::Failed, $enqueued->fresh()->status);
        $this->assertSame(GoogleDriveExportStatus::Failed, $inProgress->fresh()->status);
        $this->assertSame(GoogleDriveExportStatus::Retrying, $recentRetry->fresh()->status);
        $this->assertSame(GoogleDriveExportStatus::Completed, $completed->fresh()->status);
        $this->assertSame($old->toDateTimeString(), $completed->fresh()->last_synced_at->toDateTimeString());
        $this->assertSame(GoogleDriveExportStatus::Failed, $failed->fresh()->status);
        $this->assertSame('Export stopped before completion. Please retry the export.', $enqueued->fresh()->error);
    }

    public function test_stale_running_and_retrying_syncs_are_failed_but_recent_and_finished_syncs_are_untouched(): void
    {
        $userId = $this->userId();
        $syncs = collect([
            'enqueued' => GoogleDriveSyncStatus::Enqueued,
            'inProgress' => GoogleDriveSyncStatus::InProgress,
            'recentInProgress' => GoogleDriveSyncStatus::InProgress,
            'retrying' => GoogleDriveSyncStatus::Retrying,
            'recentRetrying' => GoogleDriveSyncStatus::Retrying,
            'completed' => GoogleDriveSyncStatus::Completed,
        ])->map(fn (GoogleDriveSyncStatus $status): GoogleDriveSync => GoogleDriveSync::create(['user_id' => $userId, 'destination_folder_id' => 'root', 'status' => $status]));
        DB::table('google_drive_syncs')->whereNotIn('id', [$syncs['recentInProgress']->id, $syncs['recentRetrying']->id])->update(['updated_at' => Carbon::now()->subMinutes(15)]);

        $count = app(ExportLifecycleService::class)->reconcileStale($userId);

        $this->assertSame(3, $count);
        $this->assertSame(GoogleDriveSyncStatus::Failed, $syncs['enqueued']->fresh()->status);
        $this->assertSame(GoogleDriveSyncStatus::Failed, $syncs['inProgress']->fresh()->status);
        $this->assertSame('Synchronization stopped before completion. Please retry the sync.', $syncs['inProgress']->fresh()->error);
        $this->assertSame(GoogleDriveSyncStatus::Failed, $syncs['retrying']->fresh()->status);
        $this->assertSame(GoogleDriveSyncStatus::InProgress, $syncs['recentInProgress']->fresh()->status);
        $this->assertSame(GoogleDriveSyncStatus::Retrying, $syncs['recentRetrying']->fresh()->status);
        $this->assertSame(GoogleDriveSyncStatus::Completed, $syncs['completed']->fresh()->status);
    }

    public function test_queued_jobs_other_than_exports_do_not_hold_back_the_stale_repair(): void
    {
        config(['queue.default' => 'database']);
        $userId = $this->userId();
        $record = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'training_progress', 'status' => GoogleDriveExportStatus::InProgress, 'last_attempt_at' => Carbon::now()->subMinutes(15)]);
        GenerateParticipantNotificationsJob::dispatch($userId);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertSame(1, app(ExportLifecycleService::class)->reconcileStale($userId));
        $this->assertSame(GoogleDriveExportStatus::Failed, $record->fresh()->status);
    }

    public function test_a_queued_export_or_sync_job_holds_back_the_stale_repair(): void
    {
        config(['queue.default' => 'database']);
        $userId = $this->userId();
        $record = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'training_progress', 'status' => GoogleDriveExportStatus::InProgress, 'last_attempt_at' => Carbon::now()->subMinutes(15)]);
        SyncTrainingDataJob::dispatch(GoogleDriveSync::create(['user_id' => $userId, 'destination_folder_id' => 'root'])->id);
        GenerateParticipantNotificationsJob::dispatch($userId);
        $this->assertSame(2, DB::table('jobs')->count());

        $this->assertSame(0, app(ExportLifecycleService::class)->reconcileStale($userId));
        $this->assertSame(GoogleDriveExportStatus::InProgress, $record->fresh()->status);
    }
}
