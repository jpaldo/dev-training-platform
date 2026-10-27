<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_dates_every_week_from_the_first_day_inactive_weeks_included_and_it_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(4)->unscheduled()->create();
        $this->week($program, 3)->update(['is_active' => false]);

        $this->actingAs($admin)
            ->put(route('admin.programs.schedule.update', $program), ['starts_on' => '2026-11-09'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $program).'#schedule')
            ->assertSessionHas('status', 'Scheduled the weeks from 9 Nov 2026.');

        $this->assertSame([
            1 => ['2026-11-09', '2026-11-15'],
            2 => ['2026-11-16', '2026-11-22'],
            3 => ['2026-11-23', '2026-11-29'],
            4 => ['2026-11-30', '2026-12-06'],
        ], $this->weekDates($program));

        $audit = AdminAuditLog::query()->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->target_user_id);
        $this->assertSame('curriculum_scheduled', $audit->action);
        $this->assertSame([
            'program_id' => $program->id,
            'starts_on' => '2026-11-09',
            'changes' => [
                1 => ['starts_on' => ['from' => null, 'to' => '2026-11-09'], 'ends_on' => ['from' => null, 'to' => '2026-11-15']],
                2 => ['starts_on' => ['from' => null, 'to' => '2026-11-16'], 'ends_on' => ['from' => null, 'to' => '2026-11-22']],
                3 => ['starts_on' => ['from' => null, 'to' => '2026-11-23'], 'ends_on' => ['from' => null, 'to' => '2026-11-29']],
                4 => ['starts_on' => ['from' => null, 'to' => '2026-11-30'], 'ends_on' => ['from' => null, 'to' => '2026-12-06']],
            ],
        ], $audit->metadata);
    }

    public function test_rescheduling_overwrites_the_dates_and_audits_only_the_weeks_that_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(3, Carbon::parse('2026-11-09'))->create();
        $this->week($program, 2)->update(['starts_on' => '2026-11-17', 'ends_on' => '2026-11-21']);
        $this->week($program, 3)->update(['starts_on' => null, 'ends_on' => null]);
        $otherProgram = Program::factory()->withWeeks(1, Carbon::parse('2027-01-04'))->create();

        $this->actingAs($admin)
            ->put(route('admin.programs.schedule.update', $program), ['starts_on' => '2026-11-09'])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            1 => ['2026-11-09', '2026-11-15'],
            2 => ['2026-11-16', '2026-11-22'],
            3 => ['2026-11-23', '2026-11-29'],
        ], $this->weekDates($program));
        $this->assertSame([
            2 => ['starts_on' => ['from' => '2026-11-17', 'to' => '2026-11-16'], 'ends_on' => ['from' => '2026-11-21', 'to' => '2026-11-22']],
            3 => ['starts_on' => ['from' => null, 'to' => '2026-11-23'], 'ends_on' => ['from' => null, 'to' => '2026-11-29']],
        ], AdminAuditLog::query()->sole()->metadata['changes']);
        $this->assertSame([1 => ['2027-01-04', '2027-01-10']], $this->weekDates($otherProgram));
    }

    public function test_an_unchanged_schedule_saves_nothing_and_writes_no_audit_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(2, Carbon::parse('2026-11-09'))->create();
        $updatedAt = $this->week($program, 1)->updated_at;
        $this->travel(5)->minutes();

        $this->actingAs($admin)
            ->put(route('admin.programs.schedule.update', $program), ['starts_on' => '2026-11-09'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.show', $program).'#schedule');

        $this->assertEquals($updatedAt, $this->week($program, 1)->updated_at);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    #[DataProvider('invalidScheduleStarts')]
    public function test_an_invalid_first_day_fails_in_the_schedule_bag_and_saves_nothing(string $startsOn, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(2)->unscheduled()->create();

        $this->actingAs($admin)
            ->put(route('admin.programs.schedule.update', $program), ['starts_on' => $startsOn])
            ->assertRedirect(route('admin.programs.show', $program).'#schedule')
            ->assertSessionHasErrorsIn('schedule', ['starts_on' => $message]);

        $this->assertSame([1 => [null, null], 2 => [null, null]], $this->weekDates($program));
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidScheduleStarts(): array
    {
        return [
            'blank' => ['', 'The start date field is required.'],
            'not a date' => ['next monday-ish', 'The start date field must be a valid date.'],
        ];
    }

    public function test_the_program_page_shows_a_failed_first_day_beside_the_schedule_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(1)->unscheduled()->create();

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.programs.schedule.update', $program), ['starts_on' => ''])
            ->assertOk()
            ->assertSeeInOrder([
                'action="'.route('admin.programs.schedule.update', $program).'"',
                'id="schedule-starts-on"',
                'aria-invalid="true" aria-describedby="schedule-starts-on-error"',
                '<p id="schedule-starts-on-error" class="text-sm text-rose-200 sm:col-span-2" role="alert">The start date field is required.</p>',
            ], false)
            ->assertDontSee('role="status"', false);
    }

    public function test_admin_sets_one_weeks_dates_on_its_details_form_and_they_are_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(3, Carbon::parse('2026-11-09'))->create();
        $week = $this->week($program, 2);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$program, $week]), [
                'title' => $week->title,
                'starts_on' => '2026-11-17',
                'ends_on' => '2026-11-21',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.programs.weeks.show', [$program, $week]).'#details');

        $this->assertSame(['2026-11-17', '2026-11-21'], $this->weekDates($program)[2]);
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame('curriculum_week_updated', $audit->action);
        $this->assertSame([
            'program_id' => $program->id,
            'training_week_id' => $week->id,
            'changes' => [
                'starts_on' => ['from' => '2026-11-16', 'to' => '2026-11-17'],
                'ends_on' => ['from' => '2026-11-22', 'to' => '2026-11-21'],
            ],
        ], $audit->metadata);
    }

    public function test_clearing_both_dates_unschedules_the_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(2, Carbon::parse('2026-11-09'))->create();
        $week = $this->week($program, 2);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$program, $week]), ['title' => $week->title, 'starts_on' => '', 'ends_on' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->weekDates($program)[2]);
        $this->assertSame([
            'program_id' => $program->id,
            'training_week_id' => $week->id,
            'changes' => [
                'starts_on' => ['from' => '2026-11-16', 'to' => null],
                'ends_on' => ['from' => '2026-11-22', 'to' => null],
            ],
        ], AdminAuditLog::query()->sole()->metadata);
    }

    public function test_the_week_page_shows_the_weeks_dates_in_its_details_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(1, Carbon::parse('2026-11-09'))->create();
        $week = $this->week($program, 1);

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'data-week-details-form',
                'id="week-starts-on"', 'name="starts_on"', 'value="2026-11-09"',
                'id="week-ends-on"', 'name="ends_on"', 'value="2026-11-15"',
                '>Save Details<',
            ], false);
    }

    /**
     * @param  array<string, string>  $dates
     */
    #[DataProvider('invalidWeekDates')]
    public function test_invalid_week_dates_fail_in_the_week_bag_and_save_nothing(array $dates, string $field, string $message): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(3, Carbon::parse('2026-11-09'))->create();
        $week = $this->week($program, 2);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$program, $week]), ['title' => 'Renamed', ...$dates])
            ->assertRedirect(route('admin.programs.weeks.show', [$program, $week]).'#details')
            ->assertSessionHasErrorsIn('week', [$field => $message]);

        $week->refresh();
        $this->assertNotSame('Renamed', $week->title);
        $this->assertSame(['2026-11-16', '2026-11-22'], $this->weekDates($program)[2]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidWeekDates(): array
    {
        return [
            'start without an end' => [['starts_on' => '2026-11-16', 'ends_on' => ''], 'ends_on', 'The end date field is required when start date is present.'],
            'end without a start' => [['starts_on' => '', 'ends_on' => '2026-11-22'], 'starts_on', 'The start date field is required when end date is present.'],
            'end before the start' => [['starts_on' => '2026-11-18', 'ends_on' => '2026-11-17'], 'ends_on', 'The end date field must be a date after or equal to start date.'],
            'start on the last day of the week before' => [['starts_on' => '2026-11-15', 'ends_on' => '2026-11-21'], 'starts_on', 'Week 2 must start after Week 1 ends (15 Nov 2026).'],
            'end on the first day of the week after' => [['starts_on' => '2026-11-17', 'ends_on' => '2026-11-23'], 'ends_on', 'Week 2 must end before Week 3 starts (23 Nov 2026).'],
        ];
    }

    public function test_an_inactive_week_keeps_its_slot_against_overlapping_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(3, Carbon::parse('2026-11-09'))->create();
        $this->week($program, 2)->update(['is_active' => false]);
        $week = $this->week($program, 3);

        $this->actingAs($admin)
            ->put(route('admin.programs.weeks.update', [$program, $week]), ['title' => $week->title, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-26'])
            ->assertSessionHasErrorsIn('week', ['starts_on' => 'Week 3 must start after Week 2 ends (22 Nov 2026).']);

        $this->assertSame(['2026-11-23', '2026-11-29'], $this->weekDates($program)[3]);
    }

    public function test_the_week_page_shows_an_overlap_beside_the_start_date_and_keeps_the_typed_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(2, Carbon::parse('2026-11-09'))->create();
        $week = $this->week($program, 2);

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.programs.weeks.update', [$program, $week]), ['title' => $week->title, 'starts_on' => '2026-11-14', 'ends_on' => '2026-11-20'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="week-starts-on"',
                'value="2026-11-14"',
                'aria-invalid="true" aria-describedby="week-starts-on-error"',
                '<p id="week-starts-on-error" class="text-sm text-rose-200" role="alert">Week 2 must start after Week 1 ends (15 Nov 2026).</p>',
                'id="week-ends-on"',
                'value="2026-11-20"',
            ], false);
    }

    public function test_participants_and_coaches_are_forbidden_and_guests_are_sent_to_login(): void
    {
        $program = Program::factory()->withWeeks(1)->unscheduled()->create();
        $url = route('admin.programs.schedule.update', $program);

        $this->put($url, ['starts_on' => '2026-11-09'])->assertRedirect(route('login'));

        foreach (['participant', 'coach'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->put($url, ['starts_on' => '2026-11-09'])
                ->assertForbidden();
        }

        $this->assertSame([1 => [null, null]], $this->weekDates($program));
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    private function week(Program $program, int $weekNumber): TrainingWeek
    {
        return $program->weeks()->where('week_number', $weekNumber)->sole();
    }

    /**
     * Each week's start and end day by week number.
     *
     * @return array<int, array{?string, ?string}>
     */
    private function weekDates(Program $program): array
    {
        return $program->weeks()
            ->orderBy('week_number')
            ->get()
            ->mapWithKeys(fn (TrainingWeek $week): array => [
                $week->week_number => [$week->starts_on?->toDateString(), $week->ends_on?->toDateString()],
            ])
            ->all();
    }
}
