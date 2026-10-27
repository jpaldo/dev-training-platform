<?php

namespace Tests\Feature;

use App\Models\TrainingWeek;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrainingWeekScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_week_opens_on_its_start_date_and_never_without_one(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $opensToday = TrainingWeek::factory()->opensOn(Carbon::parse('2026-11-09'))->create();
        $opensTomorrow = TrainingWeek::factory()->opensOn(Carbon::parse('2026-11-10'))->create();
        $unscheduled = TrainingWeek::factory()->unscheduled()->create();

        $this->assertTrue($opensToday->isOpen());
        $this->assertFalse($opensTomorrow->isOpen());
        $this->assertFalse($unscheduled->isOpen());
        $this->assertTrue($opensTomorrow->isScheduled());
        $this->assertFalse($unscheduled->isScheduled());
    }

    public function test_a_week_has_ended_only_after_its_last_day(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $endsToday = TrainingWeek::factory()->opensOn(Carbon::parse('2026-11-03'))->create();
        $endedYesterday = TrainingWeek::factory()->opensOn(Carbon::parse('2026-11-02'))->create();
        $unscheduled = TrainingWeek::factory()->unscheduled()->create();

        $this->assertFalse($endsToday->hasEnded());
        $this->assertTrue($endedYesterday->hasEnded());
        $this->assertFalse($unscheduled->hasEnded());
    }

    public function test_factory_weeks_open_today_whatever_their_number(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $week = TrainingWeek::factory()->create(['week_number' => 7]);
        $upcoming = TrainingWeek::factory()->upcoming()->create();
        $ended = TrainingWeek::factory()->ended()->create();

        $this->assertSame(['2026-11-09', '2026-11-15'], [$week->starts_on->toDateString(), $week->ends_on->toDateString()]);
        $this->assertTrue($week->isOpen());
        $this->assertFalse($upcoming->isOpen());
        $this->assertTrue($ended->isOpen());
        $this->assertTrue($ended->hasEnded());
        $this->assertSame('2026-11-08', $ended->ends_on->toDateString());
    }

    public function test_date_range_label_names_the_month_and_year_once_when_shared(): void
    {
        $sameMonth = new TrainingWeek(['starts_on' => '2026-11-09', 'ends_on' => '2026-11-15']);
        $acrossMonths = new TrainingWeek(['starts_on' => '2026-11-30', 'ends_on' => '2026-12-06']);
        $acrossYears = new TrainingWeek(['starts_on' => '2026-12-28', 'ends_on' => '2027-01-03']);
        $unscheduled = new TrainingWeek(['starts_on' => null, 'ends_on' => null]);

        $this->assertSame('9 – 15 Nov 2026', $sameMonth->dateRangeLabel());
        $this->assertSame('30 Nov – 6 Dec 2026', $acrossMonths->dateRangeLabel());
        $this->assertSame('28 Dec 2026 – 3 Jan 2027', $acrossYears->dateRangeLabel());
        $this->assertSame('Not scheduled', $unscheduled->dateRangeLabel());
    }
}
