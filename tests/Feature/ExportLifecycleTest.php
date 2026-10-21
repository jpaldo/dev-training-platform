<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveExportStatus;
use App\Models\GoogleDriveExport;
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

    public function test_retryable_failure_is_retrying_only_when_laravel_has_another_attempt(): void
    {
        $record = GoogleDriveExport::create(['user_id' => $this->userId(), 'export_type' => 'summary', 'scope_key' => 'training_progress', 'status' => GoogleDriveExportStatus::InProgress]);
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturnValues([1, 3]);

        $service = app(ExportLifecycleService::class);
        $this->assertTrue($service->markFailure($record, $job, 3, new RuntimeException('temporary network timeout'), 'Google Drive summary export failed.'));
        $this->assertSame(GoogleDriveExportStatus::Retrying, $record->fresh()->status);

        $this->assertFalse($service->markFailure($record->fresh(), $job, 3, new RuntimeException('temporary network timeout'), 'Google Drive summary export failed.'));
        $this->assertSame(GoogleDriveExportStatus::Failed, $record->fresh()->status);
    }

    public function test_profile_folder_access_failure_is_terminal(): void
    {
        $record = GoogleDriveExport::create(['user_id' => $this->userId(), 'export_type' => 'summary', 'scope_key' => 'gate_progress', 'status' => GoogleDriveExportStatus::InProgress]);
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('attempts')->andReturn(1);

        $retry = app(ExportLifecycleService::class)->markFailure($record, $job, 3, new RuntimeException('The Google Drive folder in your Profile could not be accessed.'), 'Google Drive summary export failed.');

        $this->assertFalse($retry);
        $this->assertSame(GoogleDriveExportStatus::Failed, $record->fresh()->status);
        $this->assertStringContainsString('Editor/write access', $record->fresh()->error);
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

    public function test_failed_queue_reservation_reconciles_active_summary_immediately(): void
    {
        $userId = $this->userId();
        $record = GoogleDriveExport::create(['user_id' => $userId, 'export_type' => 'summary', 'scope_key' => 'training_progress', 'status' => GoogleDriveExportStatus::Enqueued, 'last_attempt_at' => Carbon::now()]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExportSummaryReportsJob', 'data' => ['command' => 'O:40:"App\\Jobs\\ExportSummaryReportsJob":1:{s:6:"userId";i:'.$userId.';}']]),
            'exception' => 'SQLSTATE[HY000]: General error: 5 database is locked',
            'failed_at' => now(),
        ]);

        $this->assertSame(1, app(ExportLifecycleService::class)->reconcileStale($userId));
        $this->assertSame(GoogleDriveExportStatus::Failed, $record->fresh()->status);
    }
}
