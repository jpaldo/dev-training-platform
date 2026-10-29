<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Services\ExpectedFileCollector;
use App\Support\ExpectedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpectedFileCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_week_lists_its_activities_files_in_position_order_then_the_session_logs(): void
    {
        $week = TrainingWeek::factory()->create();
        $second = $this->activityWithFile($week, 2, 'docs/plans/F1-plan.md');
        $first = $this->activityWithFile($week, 1, 'CLAUDE.md');
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        $sessionLogs = $this->sessionLogsItem($checklist);

        $expectedFiles = app(ExpectedFileCollector::class)->forWeek($week);

        $this->assertSame(
            ["activity-{$first->id}-0", "activity-{$second->id}-0", "session_logs-{$sessionLogs->id}-0"],
            array_map(fn (ExpectedFile $file): string => $file->key, $expectedFiles),
        );
        $this->assertSame([false, false, true], array_map(fn (ExpectedFile $file): bool => $file->toArray()['within_week'], $expectedFiles));
    }

    public function test_inactive_activities_are_skipped(): void
    {
        $week = TrainingWeek::factory()->create();
        $active = $this->activityWithFile($week, 1, 'CLAUDE.md');
        $this->activityWithFile($week, 2, 'docs/old.md')->update(['is_active' => false]);

        $expectedFiles = app(ExpectedFileCollector::class)->forWeek($week);

        $this->assertSame(["activity-{$active->id}-0"], array_map(fn (ExpectedFile $file): string => $file->key, $expectedFiles));
    }

    public function test_a_retired_session_logs_item_is_skipped(): void
    {
        $week = TrainingWeek::factory()->create();
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        $this->sessionLogsItem($checklist)->update(['is_required' => false]);

        $this->assertSame([], app(ExpectedFileCollector::class)->forWeek($week));
    }

    public function test_a_week_without_a_checklist_lists_only_its_activities_files(): void
    {
        $week = TrainingWeek::factory()->create();
        $activity = $this->activityWithFile($week, 1, 'CLAUDE.md');
        $inactiveGate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => false]);
        $this->sessionLogsItem($inactiveGate);

        $expectedFiles = app(ExpectedFileCollector::class)->forWeek($week);

        $this->assertSame(["activity-{$activity->id}-0"], array_map(fn (ExpectedFile $file): string => $file->key, $expectedFiles));
    }

    public function test_the_query_count_does_not_grow_with_the_activities(): void
    {
        $week = TrainingWeek::factory()->create();
        foreach (range(1, 5) as $position) {
            $this->activityWithFile($week, $position, "docs/file-{$position}.md");
        }
        $this->sessionLogsItem(TrainingGate::factory()->for($week, 'week')->create());

        DB::enableQueryLog();
        $expectedFiles = app(ExpectedFileCollector::class)->forWeek($week);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(6, $expectedFiles);
        $this->assertCount(3, $queries);
    }

    private function activityWithFile(TrainingWeek $week, int $position, string $path): Activity
    {
        return Activity::factory()->for($week, 'week')->create([
            'position' => $position,
            'configuration' => ['expected_files' => [['path' => $path, 'description' => 'A deliverable']]],
        ]);
    }

    private function sessionLogsItem(TrainingGate $gate): GateCriterion
    {
        return GateCriterion::factory()->for($gate, 'gate')->create([
            'criterion_type' => GateCriterion::TYPE_SESSION_LOGS,
            'configuration' => ['expected_files' => [['path' => 'activity-outputs/session-logs/*', 'description' => 'This week\'s session logs']]],
        ]);
    }
}
