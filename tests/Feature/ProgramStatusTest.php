<?php

namespace Tests\Feature;

use App\Enums\ProgramStatus;
use App\Enums\SubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgramStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_status_follows_the_active_weeks_dates_with_the_first_and_last_days_counted_as_active(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $startsTomorrow = Program::factory()->withWeeks(2, Carbon::parse('2026-11-10'))->create();
        $startsToday = Program::factory()->withWeeks(2, Carbon::parse('2026-11-09'))->create();
        $endsToday = Program::factory()->withWeeks(2, Carbon::parse('2026-10-27'))->create();
        $endedYesterday = Program::factory()->withWeeks(2, Carbon::parse('2026-10-26'))->create();
        $withoutWeeks = Program::factory()->create();
        $withUndatedWeeks = Program::factory()->withWeeks(2)->unscheduled()->create();

        $this->assertSame(ProgramStatus::Upcoming, $startsTomorrow->status());
        $this->assertSame(ProgramStatus::Active, $startsToday->status());
        $this->assertSame(ProgramStatus::Active, $endsToday->status());
        $this->assertSame(ProgramStatus::Completed, $endedYesterday->status());
        $this->assertSame(ProgramStatus::Unscheduled, $withoutWeeks->status());
        $this->assertSame(ProgramStatus::Unscheduled, $withUndatedWeeks->status());
        $this->assertFalse($endsToday->isCompleted());
        $this->assertTrue($endedYesterday->isCompleted());

        $this->assertEqualsCanonicalizing(
            [$startsTomorrow->id, $startsToday->id, $endsToday->id, $withoutWeeks->id, $withUndatedWeeks->id],
            Program::query()->notCompleted()->pluck('id')->all(),
        );
        $this->assertSame([$endedYesterday->id], Program::query()->completed()->pluck('id')->all());
    }

    public function test_status_reads_only_the_active_weeks(): void
    {
        $this->travelTo('2026-11-09 08:00:00');
        $endedWithInactiveLaterWeek = Program::factory()->withWeeks(1, Carbon::parse('2026-10-26'))->create();
        TrainingWeek::factory()->for($endedWithInactiveLaterWeek)->opensOn(Carbon::parse('2026-11-09'))
            ->create(['week_number' => 2, 'position' => 2, 'is_active' => false]);
        $onlyInactiveDatedWeeks = Program::factory()->create();
        TrainingWeek::factory()->for($onlyInactiveDatedWeeks)->create(['week_number' => 1, 'position' => 1, 'is_active' => false]);

        $this->assertSame(ProgramStatus::Completed, $endedWithInactiveLaterWeek->status());
        $this->assertSame('2026-11-01', $endedWithInactiveLaterWeek->scheduleEndsOn()->toDateString());
        $this->assertSame([$endedWithInactiveLaterWeek->id], Program::query()->completed()->pluck('id')->all());
        $this->assertSame(ProgramStatus::Unscheduled, $onlyInactiveDatedWeeks->status());
        $this->assertNull($onlyInactiveDatedWeeks->scheduleStartsOn());
    }

    public function test_factory_states_produce_their_status(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $this->assertSame(ProgramStatus::Active, Program::factory()->withWeeks(4, today())->create()->status());
        $this->assertSame(ProgramStatus::Active, Program::factory()->withWeeks(4)->create()->status());
        $this->assertSame(ProgramStatus::Upcoming, Program::factory()->withWeeks(4, today()->addWeek())->create()->status());
        $this->assertSame(ProgramStatus::Upcoming, Program::factory()->withWeeks(4)->upcoming()->create()->status());
        $this->assertSame(ProgramStatus::Unscheduled, Program::factory()->withWeeks(4)->unscheduled()->create()->status());

        $completed = Program::factory()->withWeeks(2)->completed()->create();
        $this->assertSame(ProgramStatus::Completed, $completed->status());
        $this->assertSame('2026-10-26', $completed->scheduleStartsOn()->toDateString());
        $this->assertSame('2026-11-08', $completed->scheduleEndsOn()->toDateString());
    }

    public function test_with_weeks_dates_week_n_seven_days_apart_from_the_start(): void
    {
        $program = Program::factory()->withWeeks(3, Carbon::parse('2026-11-09'))->create();

        $this->assertSame(
            [[1, '2026-11-09', '2026-11-15'], [2, '2026-11-16', '2026-11-22'], [3, '2026-11-23', '2026-11-29']],
            $program->activeWeeks->map(fn (TrainingWeek $week): array => [
                $week->week_number,
                $week->starts_on->toDateString(),
                $week->ends_on->toDateString(),
            ])->all(),
        );
    }

    public function test_schedule_bounds_loaded_with_the_scope_match_the_queried_bounds_without_further_queries(): void
    {
        $this->travelTo('2026-11-09 08:00:00');
        $scheduled = Program::factory()->withWeeks(4, Carbon::parse('2026-11-09'))->create();
        $unscheduled = Program::factory()->create();

        $this->assertSame('2026-11-09', $scheduled->scheduleStartsOn()->toDateString());
        $this->assertSame('2026-12-06', $scheduled->scheduleEndsOn()->toDateString());

        $loaded = Program::query()->withScheduleBounds()->orderBy('id')->get();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $statuses = $loaded->map(fn (Program $program): ProgramStatus => $program->status())->all();
        $startsOn = $loaded->first()->scheduleStartsOn()->toDateString();
        $endsOn = $loaded->first()->scheduleEndsOn()->toDateString();
        $labels = $loaded->map(fn (Program $program): string => $program->dateRangeLabel())->all();
        DB::disableQueryLog();

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame([ProgramStatus::Active, ProgramStatus::Unscheduled], $statuses);
        $this->assertSame(['2026-11-09', '2026-12-06'], [$startsOn, $endsOn]);
        $this->assertSame(['9 Nov – 6 Dec 2026', 'Not scheduled'], $labels);
        $this->assertTrue($loaded->last()->is($unscheduled));
    }

    public function test_open_to_join_offers_curricula_that_are_offered_and_not_completed(): void
    {
        $this->travelTo('2026-11-09 08:00:00');
        $unscheduled = Program::factory()->create();
        $upcoming = Program::factory()->withWeeks(2)->upcoming()->create();
        $active = Program::factory()->withWeeks(2)->create();
        Program::factory()->withWeeks(2)->completed()->create();
        Program::factory()->withWeeks(2)->create(['is_active' => false]);

        $this->assertEqualsCanonicalizing(
            [$unscheduled->id, $upcoming->id, $active->id],
            Program::query()->openToJoin()->pluck('id')->all(),
        );
    }

    public function test_accepts_work_until_the_last_week_ends_and_then_only_on_a_reopened_attempt(): void
    {
        $this->travelTo('2026-11-09 08:00:00');
        $endsToday = Program::factory()->withWeeks(2, Carbon::parse('2026-10-27'))->create();
        $endedYesterday = Program::factory()->withWeeks(2, Carbon::parse('2026-10-26'))->create();
        $reopened = new ActivitySubmission(['status' => SubmissionStatus::NeedsRevision]);
        $handedIn = new ActivitySubmission(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);

        $this->assertTrue($endsToday->acceptsWork());
        $this->assertFalse($endedYesterday->acceptsWork());
        $this->assertFalse($endedYesterday->acceptsWork($handedIn));
        $this->assertTrue($endedYesterday->acceptsWork($reopened));
        $this->assertSame('This curriculum has ended.', Program::ENDED_MESSAGE);
    }
}
