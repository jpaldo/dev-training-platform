<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Services\ExpectedFileCollector;
use App\Support\ChecklistItemCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpectedFileCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_week_lists_its_checked_items_in_position_order_and_reads_no_activities(): void
    {
        $week = TrainingWeek::factory()->create();
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        $sessionLogs = $this->item($checklist, GateCriterion::TYPE_SESSION_LOGS, 3);
        $second = $this->item($checklist, GateCriterion::TYPE_DELIVERABLE, 2);
        $first = $this->item($checklist, GateCriterion::TYPE_DELIVERABLE, 1);
        Activity::factory()->for($week, 'week')->create([
            'configuration' => ['expected_files' => [['path' => 'CLAUDE.md', 'description' => 'Not read from activities']]],
        ]);

        $items = app(ExpectedFileCollector::class)->forWeek($week);

        $this->assertSame([$first->id, $second->id, $sessionLogs->id], $this->criterionIds($items));
        $this->assertSame(
            [false, false, true],
            array_map(fn (ChecklistItemCheck $item): bool => $item->toArray()['expected_files'][0]['within_week'], $items),
        );
    }

    public function test_weekly_video_items_and_items_with_nothing_configured_are_skipped(): void
    {
        $week = TrainingWeek::factory()->create();
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        $this->item($checklist, GateCriterion::TYPE_WEEKLY_VIDEO, 1);
        GateCriterion::factory()->for($checklist, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE, 'position' => 2]);
        $checked = $this->item($checklist, GateCriterion::TYPE_DELIVERABLE, 3);

        $items = app(ExpectedFileCollector::class)->forWeek($week);

        $this->assertSame([$checked->id], $this->criterionIds($items));
    }

    public function test_a_retired_item_is_skipped(): void
    {
        $week = TrainingWeek::factory()->create();
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        $this->item($checklist, GateCriterion::TYPE_SESSION_LOGS, 1)->update(['is_required' => false]);
        $this->item($checklist, GateCriterion::TYPE_DELIVERABLE, 2)->update(['is_required' => false]);

        $this->assertSame([], app(ExpectedFileCollector::class)->forWeek($week));
    }

    public function test_a_week_without_an_active_checklist_has_nothing_to_check(): void
    {
        $week = TrainingWeek::factory()->create();
        $inactiveGate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => false]);
        $this->item($inactiveGate, GateCriterion::TYPE_DELIVERABLE, 1);

        $this->assertSame([], app(ExpectedFileCollector::class)->forWeek($week));
    }

    public function test_the_query_count_does_not_grow_with_the_items(): void
    {
        $week = TrainingWeek::factory()->create();
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        foreach (range(1, 5) as $position) {
            $this->item($checklist, GateCriterion::TYPE_DELIVERABLE, $position);
        }
        $this->item($checklist, GateCriterion::TYPE_SESSION_LOGS, 6);

        DB::enableQueryLog();
        $items = app(ExpectedFileCollector::class)->forWeek($week);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(6, $items);
        $this->assertCount(2, $queries);
    }

    private function item(TrainingGate $gate, string $type, int $position): GateCriterion
    {
        return GateCriterion::factory()->for($gate, 'gate')->create([
            'criterion_type' => $type,
            'position' => $position,
            'configuration' => ['expected_files' => [['path' => "docs/file-{$position}.md", 'description' => 'A deliverable']]],
        ]);
    }

    /**
     * @param  list<ChecklistItemCheck>  $items
     * @return list<int>
     */
    private function criterionIds(array $items): array
    {
        return array_map(fn (ChecklistItemCheck $item): int => $item->criterionId, $items);
    }
}
