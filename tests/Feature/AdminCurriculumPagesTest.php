<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCurriculumPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_the_programs_by_name_with_whether_they_are_offered_and_their_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()
            ->withParticipants(...User::factory()->count(3)->create()->all())
            ->create(['name' => 'L4 Accelerator Training']);
        $this->week($program, 1);
        $this->week($program, 2);
        $retired = Program::factory()->create(['name' => 'Archived Bootcamp', 'is_active' => false]);
        $this->week($retired, 1);

        $this->actingAs($admin)
            ->get(route('admin.programs.index'))
            ->assertOk()
            ->assertSee('All curricula')
            ->assertSeeInOrder(['>Curriculum<', '>Offered<', '>Schedule<', '>Dates<', '>Weeks<', '>Participants<', '>Actions<'], false)
            ->assertSeeInOrder(['Archived Bootcamp', '>No<', 'data-program-participants>0<', 'L4 Accelerator Training', '>Yes<', 'data-program-participants>3<'], false)
            ->assertSee('<a href="'.route('admin.programs.show', $program).'"', false)
            ->assertSee('aria-label="Open Archived Bootcamp"', false)
            ->assertViewHas('programs', fn (array $programs): bool => array_column($programs, 'name') === ['Archived Bootcamp', 'L4 Accelerator Training']
                && $programs[1]['weeks_count'] === 2
                && $programs[1]['participants_count'] === 3
                && $programs[0]['offered_tone'] === 'neutral'
                && $programs[1]['offered_tone'] === 'success');
    }

    public function test_the_list_shows_each_programs_schedule_status_and_dates(): void
    {
        $this->travelTo('2026-11-12 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        Program::factory()->withWeeks(4, Carbon::parse('2026-11-09'))->create(['name' => 'Autumn Curriculum']);
        Program::factory()->withWeeks(2)->unscheduled()->create(['name' => 'Draft Curriculum']);

        $this->actingAs($admin)
            ->get(route('admin.programs.index'))
            ->assertOk()
            ->assertSeeInOrder(['>Schedule<', '>Dates<'], false)
            ->assertSeeInOrder(['Autumn Curriculum', 'Yes', 'Active', '9 Nov – 6 Dec 2026', 'Draft Curriculum', 'Yes', 'Unscheduled', 'Not scheduled'])
            ->assertViewHas('programs', fn (array $programs): bool => $programs[0]['schedule_status_tone'] === 'success'
                && $programs[1]['schedule_status_label'] === 'Unscheduled'
                && $programs[1]['schedule_status_tone'] === 'warning');
    }

    public function test_the_list_without_programs_shows_the_empty_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.programs.index'))
            ->assertOk()
            ->assertSee('No curricula yet.');
    }

    public function test_the_program_page_lists_the_weeks_in_number_order_with_their_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training', 'description' => 'Four weeks of accelerated training.']);
        $secondWeek = $this->week($program, 2, 'Testing');
        $firstWeek = $this->week($program, 1, 'Foundations');
        Activity::factory()->for($firstWeek, 'week')->count(2)->create();
        Activity::factory()->for($firstWeek, 'week')->create(['is_active' => false]);
        Activity::factory()->for($secondWeek, 'week')->create();
        $firstChecklist = TrainingGate::factory()->for($firstWeek, 'week')->create();
        GateCriterion::factory()->for($firstChecklist, 'gate')->count(3)->create();
        GateCriterion::factory()->for($firstChecklist, 'gate')->create(['is_required' => false]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($firstWeek, 'week')->create(['is_active' => false]), 'gate')->create();
        $this->week(Program::factory()->create(), 1, 'Other program week');

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['L4 Accelerator Training', 'Four weeks of accelerated training.', 'All Curricula'])
            ->assertSee('<a href="'.route('admin.programs.index').'"', false)
            ->assertSeeInOrder(['id="program-panel-weeks"', 'Week 1', 'Foundations', 'Week 2', 'Testing'], false)
            ->assertSee('<a href="'.route('admin.programs.weeks.show', [$program, $firstWeek]).'"', false)
            ->assertSeeInOrder(['aria-label="Edit Week 2"', '>Edit Week<'], false)
            ->assertDontSee('Other program week')
            ->assertViewHas('weeks', fn (array $weeks): bool => array_column($weeks, 'number') === [1, 2]
                && $weeks[0]['active_activity_count'] === 2
                && $weeks[0]['checklist_item_count'] === 3
                && $weeks[1]['active_activity_count'] === 1
                && $weeks[1]['checklist_item_count'] === 0);
    }

    #[DataProvider('scheduleNotices')]
    public function test_the_schedule_notice_follows_the_curriculum_status_and_warns_only_while_it_is_active(string $today, string $status, string $tone, string $message): void
    {
        $this->travelTo($today);
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(4, Carbon::parse('2026-11-09'))->create();
        $week = $program->weeks()->where('week_number', 2)->sole();

        foreach ([route('admin.programs.show', $program), route('admin.programs.weeks.show', [$program, $week])] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee('data-schedule-notice="'.$status.'"', false)
                ->assertSee($message)
                ->assertViewHas('notice', ['status' => $status, 'tone' => $tone, 'message' => $message]);
        }
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function scheduleNotices(): array
    {
        return [
            'upcoming' => ['2026-11-08 09:00:00', 'upcoming', 'info', 'Upcoming 9 Nov – 6 Dec 2026: participants can open the first week on 9 Nov 2026.'],
            'active' => ['2026-11-12 09:00:00', 'active', 'warning', 'Active 9 Nov – 6 Dec 2026: changes show to its participants and mentors right away.'],
            'completed' => ['2026-12-07 09:00:00', 'completed', 'info', 'Completed 9 Nov – 6 Dec 2026: changes still show to its participants and mentors when they look back.'],
        ];
    }

    public function test_the_notice_of_a_program_without_dated_weeks_says_participants_cannot_open_any_week(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('data-schedule-notice="unscheduled"', false)
            ->assertSee('Not scheduled: participants cannot open any week yet.')
            ->assertViewHas('notice', fn (array $notice): bool => $notice['tone'] === 'info')
            ->assertSee('This curriculum has no weeks yet.');
    }

    #[DataProvider('scheduleStatusDays')]
    public function test_the_program_header_shows_the_schedule_status_and_dates(string $today, string $statusLabel): void
    {
        $this->travelTo($today);
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(4, Carbon::parse('2026-11-09'))->create(['name' => 'Autumn Curriculum']);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['Autumn Curriculum', 'data-program-schedule-summary', '>'.$statusLabel.'</span>', '9 Nov – 6 Dec 2026'], false);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function scheduleStatusDays(): array
    {
        return [
            'before the first week' => ['2026-11-08 09:00:00', 'Upcoming'],
            'on the first day' => ['2026-11-09 09:00:00', 'Active'],
            'on the last day' => ['2026-12-06 09:00:00', 'Active'],
            'after the last week' => ['2026-12-07 09:00:00', 'Completed'],
        ];
    }

    public function test_an_unscheduled_program_header_says_so(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(2)->unscheduled()->create();

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['data-program-schedule-summary', '>Unscheduled</span>', 'Not scheduled'], false)
            ->assertViewHas('schedule', fn (array $schedule): bool => $schedule['starts_on'] === null);
    }

    public function test_the_program_page_shows_each_weeks_dates_and_the_schedule_form_with_the_current_first_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(3, Carbon::parse('2026-11-09'))->create();
        $program->weeks()->where('week_number', 1)->update(['starts_on' => null, 'ends_on' => null]);
        $program->weeks()->where('week_number', 2)->update(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder([
                'id="program-panel-weeks"',
                '>Dates<',
                'Week 1', 'data-week-dates>Not scheduled<',
                'Week 2', 'data-week-dates>16 – 22 Nov 2026<',
                'Week 3', 'data-week-dates>23 – 29 Nov 2026<',
                'id="program-panel-schedule"',
                'action="'.route('admin.programs.schedule.update', $program).'"',
                'name="_method" value="PUT"',
                'id="schedule-starts-on"', 'value="2026-11-09"',
                '>Save Schedule<',
                'data-schedule-week', 'Week 1', 'Not scheduled',
                'data-schedule-week', 'Week 2', '16 – 22 Nov 2026', 'Inactive',
                'data-schedule-week', 'Week 3', '23 – 29 Nov 2026',
            ], false)
            ->assertViewHas('schedule', fn (array $schedule): bool => $schedule['starts_on'] === '2026-11-09'
                && array_column($schedule['weeks'], 'is_active') === [true, false, true]);
    }

    public function test_the_program_page_confirms_a_saved_schedule(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->withWeeks(1)->unscheduled()->create();

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.programs.schedule.update', $program), ['starts_on' => '2026-11-09'])
            ->assertOk()
            ->assertSeeInOrder(['role="status"', 'Scheduled the weeks from 9 Nov 2026.', 'value="2026-11-09"'], false);
    }

    public function test_an_inactive_week_shows_inactive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1, 'title' => 'Retired week', 'is_active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['Week 1', 'Retired week', 'Inactive'])
            ->assertViewHas('weeks', fn (array $weeks): bool => $weeks[0]['status_tone'] === 'neutral');
    }

    public function test_an_unknown_program_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.programs.show', 999))->assertNotFound();
    }

    public function test_participants_and_mentors_of_the_curriculum_are_forbidden_on_its_admin_pages(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $coach = User::factory()->create(['role' => 'coach']);
        $program = Program::factory()->withParticipants($participant)->withMentors($coach)->create();
        $week = $this->week($program, 1);

        foreach ([$participant, $coach] as $user) {
            $this->actingAs($user)->get(route('admin.programs.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.programs.show', $program))->assertForbidden();
            $this->actingAs($user)->get(route('admin.programs.weeks.show', [$program, $week]))->assertForbidden();
            $this->actingAs($user)->put(route('admin.programs.schedule.update', $program), ['starts_on' => '2026-11-09'])->assertForbidden();
        }

        $this->assertSame(today()->toDateString(), $week->fresh()->starts_on->toDateString());
        $this->assertDatabaseEmpty('admin_audit_logs');
    }

    public function test_guests_are_sent_to_login_from_the_curriculum_pages(): void
    {
        $program = Program::factory()->create();
        $week = $this->week($program, 1);

        $this->get(route('admin.programs.index'))->assertRedirect(route('login'));
        $this->get(route('admin.programs.show', $program))->assertRedirect(route('login'));
        $this->get(route('admin.programs.weeks.show', [$program, $week]))->assertRedirect(route('login'));
        $this->put(route('admin.programs.schedule.update', $program), ['starts_on' => '2026-11-09'])->assertRedirect(route('login'));

        $this->assertSame(today()->toDateString(), $week->fresh()->starts_on->toDateString());
    }

    public function test_the_program_page_query_count_does_not_grow_with_weeks_activities_checklists_and_participants(): void
    {
        $this->assertSame($this->programPageQueryCount(1), $this->programPageQueryCount(4));
    }

    public function test_the_week_page_shows_the_weeks_details_activities_in_position_order_and_checklist_with_type_labels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $week = $this->week($program, 2, 'Testing');
        $week->update(['description' => 'Write tests before the code.']);
        Activity::factory()->for($week, 'week')->create(['title' => 'Refactor kata', 'position' => 3]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Unit testing basics', 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Retired mocking drill', 'position' => 2, 'is_active' => false]);
        Activity::factory()->for($this->week($program, 1, 'Foundations'), 'week')->create(['title' => 'Other week activity']);
        TrainingGate::factory()->for($week, 'week')->create(['title' => 'Old checklist', 'position' => 1, 'is_active' => false]);
        $checklist = TrainingGate::factory()->for($week, 'week')->create([
            'title' => 'Week 2 checklist',
            'description' => 'Everything a mentor checks before week 3.',
            'position' => 2,
        ]);
        GateCriterion::factory()->for($checklist, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO, 'title' => 'Weekly video recorded', 'position' => 3]);
        GateCriterion::factory()->for($checklist, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Test suite passes', 'description' => 'Every test is green.', 'position' => 1]);
        GateCriterion::factory()->for($checklist, 'gate')->create(['criterion_type' => GateCriterion::TYPE_SESSION_LOGS, 'title' => 'Session logs committed', 'position' => 2]);

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSee('<title>Week 2: Testing</title>', false)
            ->assertSeeInOrder(['ADMIN · CURRICULUM', 'Week 2: Testing', 'L4 Accelerator Training', 'Active'])
            ->assertSee('<a href="'.route('admin.programs.show', $program).'"', false)
            ->assertSee('data-schedule-notice="active"', false)
            ->assertSeeInOrder(['Details', 'Activities', 'Checklist'])
            ->assertSeeInOrder(['Testing', 'Write tests before the code.'])
            ->assertSeeInOrder(['Unit testing basics', 'Active', 'Retired mocking drill', 'Inactive', 'Refactor kata', 'Active'])
            ->assertSeeInOrder([
                'Week 2 checklist',
                'Everything a mentor checks before week 3.',
                'Test suite passes', 'Deliverable', 'Every test is green.',
                'Session logs committed', 'Session logs',
                'Weekly video recorded', 'Weekly video',
            ])
            ->assertDontSee('Other week activity')
            ->assertDontSee('Old checklist')
            ->assertDontSee('This week has no checklist.')
            ->assertDontSee('Removed items')
            ->assertViewHas('activities', fn (array $activities): bool => array_map(
                fn (array $activity): array => [$activity['title'], $activity['status_label'], $activity['status_tone']],
                $activities,
            ) === [
                ['Unit testing basics', 'Active', 'success'],
                ['Retired mocking drill', 'Inactive', 'neutral'],
                ['Refactor kata', 'Active', 'success'],
            ])
            ->assertViewHas('checklist', fn (array $checklist): bool => $checklist['title'] === 'Week 2 checklist'
                && array_column($checklist['items'], 'type_label') === ['Deliverable', 'Session logs', 'Weekly video']
                && $checklist['removed_items'] === []);
    }

    public function test_the_week_page_lists_retired_checklist_items_only_under_removed_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $week = $this->week($program, 1);
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($checklist, 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE, 'title' => 'Feature shipped']);
        GateCriterion::factory()->for($checklist, 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO, 'title' => 'Retired video item', 'is_required' => false]);

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder(['Feature shipped', 'Removed items', 'Retired video item', 'Weekly video'])
            ->assertSee('data-removed-checklist-item', false)
            ->assertViewHas('checklist', fn (array $checklist): bool => array_column($checklist['items'], 'title') === ['Feature shipped']
                && array_column($checklist['removed_items'], 'title') === ['Retired video item']);
    }

    public function test_a_week_without_an_active_checklist_says_so(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $week = $this->week($program, 1);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week')->create(['title' => 'Inactive checklist', 'is_active' => false]), 'gate')->create();

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSee('This week has no checklist.')
            ->assertSee('This week has no activities yet.')
            ->assertDontSee('Inactive checklist')
            ->assertViewHas('checklist', null);
    }

    public function test_a_week_of_another_program_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $foreignWeek = $this->week(Program::factory()->create(), 1);

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $foreignWeek]))
            ->assertNotFound();
    }

    public function test_the_week_page_query_count_does_not_grow_with_activities_and_checklist_items(): void
    {
        $this->assertSame($this->weekPageQueryCount(1), $this->weekPageQueryCount(4));
    }

    private function week(Program $program, int $weekNumber, ?string $title = null): TrainingWeek
    {
        return TrainingWeek::factory()->for($program)->create([
            'week_number' => $weekNumber,
            'position' => $weekNumber,
            ...($title === null ? [] : ['title' => $title]),
        ]);
    }

    /**
     * Create a program with the given number of weeks, each with that many activities and a checklist with that many
     * items, and that many participants, and count the queries of an admin's program page request after a warm-up request.
     */
    private function programPageQueryCount(int $count): int
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        foreach (range(1, $count) as $weekNumber) {
            $week = $this->week($program, $weekNumber);
            Activity::factory()->for($week, 'week')->count($count)->create();
            GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week')->create(), 'gate')->count($count)->create();
        }
        $program->participants()->attach(User::factory()->count($count)->create(), ['joined_at' => now()]);

        $this->actingAs($admin)->get(route('admin.programs.show', $program))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertViewHas('weeks', fn (array $weeks): bool => count($weeks) === $count
                && array_sum(array_column($weeks, 'active_activity_count')) === $count * $count
                && array_sum(array_column($weeks, 'checklist_item_count')) === $count * $count)
            ->assertSee("Participants ({$count})");
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Create a week with that many activities and a checklist with that many items on it and that many retired, and count the queries of an admin's week page request after a warm-up request.
     */
    private function weekPageQueryCount(int $count): int
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $week = $this->week($program, 1);
        Activity::factory()->for($week, 'week')->count($count)->create();
        $checklist = TrainingGate::factory()->for($week, 'week')->create();
        GateCriterion::factory()->for($checklist, 'gate')->count($count)->create();
        GateCriterion::factory()->for($checklist, 'gate')->count($count)->create(['is_required' => false]);

        $this->actingAs($admin)->get(route('admin.programs.weeks.show', [$program, $week]))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertViewHas('activities', fn (array $activities): bool => count($activities) === $count)
            ->assertViewHas('checklist', fn (array $checklist): bool => count($checklist['items']) === $count
                && count($checklist['removed_items']) === $count);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
