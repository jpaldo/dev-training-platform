<?php

namespace Tests\Feature;

use App\Enums\TrainingSessionStatus;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TrainingSessionModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_status_follows_the_dates_with_the_start_and_end_days_counted_as_active(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $startsTomorrow = TrainingSession::factory()->create(['starts_on' => '2026-11-10', 'ends_on' => '2026-12-07']);
        $startsToday = TrainingSession::factory()->create(['starts_on' => '2026-11-09', 'ends_on' => '2026-12-06']);
        $endsToday = TrainingSession::factory()->create(['starts_on' => '2026-10-13', 'ends_on' => '2026-11-09']);
        $endedYesterday = TrainingSession::factory()->create(['starts_on' => '2026-10-12', 'ends_on' => '2026-11-08']);

        $this->assertSame(TrainingSessionStatus::Upcoming, $startsTomorrow->status());
        $this->assertSame(TrainingSessionStatus::Active, $startsToday->status());
        $this->assertSame(TrainingSessionStatus::Active, $endsToday->status());
        $this->assertSame(TrainingSessionStatus::Completed, $endedYesterday->status());
        $this->assertFalse($endsToday->isCompleted());
        $this->assertTrue($endedYesterday->isCompleted());

        $this->assertEqualsCanonicalizing(
            [$startsTomorrow->id, $startsToday->id, $endsToday->id],
            TrainingSession::query()->notCompleted()->pluck('id')->all(),
        );
        $this->assertSame([$endedYesterday->id], TrainingSession::query()->completed()->pluck('id')->all());
    }

    public function test_factory_states_produce_their_status(): void
    {
        $this->travelTo('2026-11-09 08:00:00');

        $this->assertSame(TrainingSessionStatus::Upcoming, TrainingSession::factory()->upcoming()->create()->status());
        $this->assertSame(TrainingSessionStatus::Active, TrainingSession::factory()->active()->create()->status());

        $completed = TrainingSession::factory()->state(['weeks_count' => 2])->completed()->create();
        $this->assertSame(TrainingSessionStatus::Completed, $completed->status());
        $this->assertSame('2026-10-26', $completed->starts_on->toDateString());
        $this->assertSame('2026-11-08', $completed->ends_on->toDateString());
    }

    public function test_weeks_are_the_programs_active_weeks_up_to_the_week_count(): void
    {
        $program = Program::factory()->create();
        foreach ([1, 2, 3, 4] as $weekNumber) {
            TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
        }
        TrainingWeek::factory()->for($program)->create(['week_number' => 5, 'position' => 5, 'is_active' => false]);
        TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);

        $session = TrainingSession::factory()->for($program)->create(['weeks_count' => 3]);
        $fullSession = TrainingSession::factory()->for($program)->create(['weeks_count' => 5]);

        $this->assertSame([1, 2, 3], $session->weeks()->pluck('week_number')->all());
        $this->assertSame([1, 2, 3, 4], $fullSession->weeks()->pluck('week_number')->all());
    }

    public function test_includes_only_its_own_programs_active_weeks_up_to_the_week_count(): void
    {
        $program = Program::factory()->create();
        $weekTwo = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 2]);
        $weekFour = TrainingWeek::factory()->for($program)->create(['week_number' => 4, 'position' => 4]);
        $inactiveWeekThree = TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 3, 'is_active' => false]);
        $otherProgramWeekTwo = TrainingWeek::factory()->create(['week_number' => 2, 'position' => 2]);

        $session = TrainingSession::factory()->for($program)->create(['weeks_count' => 3]);

        $this->assertTrue($session->includesWeek($weekTwo));
        $this->assertFalse($session->includesWeek($weekFour));
        $this->assertFalse($session->includesWeek($inactiveWeekThree));
        $this->assertFalse($session->includesWeek($otherProgramWeekTwo));
    }

    public function test_week_n_opens_n_minus_one_weeks_after_the_start_date(): void
    {
        $session = TrainingSession::factory()->create(['starts_on' => '2026-11-09', 'ends_on' => '2026-12-06']);

        $this->assertSame('2026-11-09', $session->weekOpensOn(1)->toDateString());
        $this->assertSame('2026-11-16', $session->weekOpensOn(2)->toDateString());
        $this->assertSame('2026-11-30', $session->weekOpensOn(4)->toDateString());
        $this->assertSame('2026-11-09', $session->starts_on->toDateString());
    }

    public function test_participants_and_their_sessions_share_the_membership_with_its_join_details(): void
    {
        $this->travelTo('2026-11-09 08:00:00');
        $admin = User::factory()->create();
        $participant = User::factory()->create();
        $session = TrainingSession::factory()->create();

        $session->participants()->attach($participant, ['joined_at' => now(), 'added_by' => $admin->id]);

        $membership = $participant->trainingSessions()->sole()->pivot;
        $this->assertTrue($membership->joined_at->equalTo(now()));
        $this->assertTrue($membership->addedBy->is($admin));
        $this->assertTrue($session->participants()->sole()->is($participant));
        $this->assertTrue($session->program->trainingSessions()->sole()->is($session));
    }
}
