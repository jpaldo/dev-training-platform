<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCurriculumPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_the_programs_by_name_with_their_status_and_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $this->week($program, 1);
        $this->week($program, 2);
        TrainingSession::factory()->for($program)->count(3)->create();
        $retired = Program::factory()->create(['name' => 'Archived Bootcamp', 'is_active' => false]);
        $this->week($retired, 1);

        $this->actingAs($admin)
            ->get(route('admin.programs.index'))
            ->assertOk()
            ->assertSeeInOrder(['Archived Bootcamp', 'Inactive', '1', '0', 'L4 Accelerator Training', 'Active', '2', '3'])
            ->assertSee('<a href="'.route('admin.programs.show', $program).'"', false)
            ->assertSee('aria-label="Open Archived Bootcamp"', false)
            ->assertViewHas('programs', fn (array $programs): bool => array_column($programs, 'name') === ['Archived Bootcamp', 'L4 Accelerator Training']
                && $programs[1]['weeks_count'] === 2
                && $programs[1]['sessions_count'] === 3
                && $programs[0]['status_tone'] === 'neutral');
    }

    public function test_the_list_without_programs_shows_the_empty_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.programs.index'))
            ->assertOk()
            ->assertSee('No programs yet.');
    }

    public function test_the_program_page_lists_the_weeks_in_number_order_with_their_counts_and_the_sessions_with_status_badges(): void
    {
        $this->travelTo('2026-11-12 09:00:00');
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
        $completed = TrainingSession::factory()->for($program)->create(['title' => 'Spring Run', 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-29']);
        $upcoming = TrainingSession::factory()->for($program)->create(['title' => 'Winter Run', 'starts_on' => '2026-12-07', 'ends_on' => '2027-01-03']);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['L4 Accelerator Training', 'Four weeks of accelerated training.'])
            ->assertSee('Used by 2 training sessions (0 active, 1 upcoming, 1 completed). Changes show to their participants and mentors right away, including in completed sessions.')
            ->assertSee('data-sessions-notice="info"', false)
            ->assertSeeInOrder(['Winter Run', '7 Dec 2026 – 3 Jan 2027', 'Upcoming', 'Spring Run', '2 Mar – 29 Mar 2026', 'Completed'])
            ->assertSee('<a href="'.route('admin.sessions.show', $upcoming).'"', false)
            ->assertSee('<a href="'.route('admin.sessions.show', $completed).'"', false)
            ->assertSeeInOrder(['Week 1', 'Foundations', 'Week 2', 'Testing'])
            ->assertSee('<a href="'.route('admin.programs.weeks.show', [$program, $firstWeek]).'"', false)
            ->assertSeeInOrder(['aria-label="Edit Week 2"', '>Edit Week<'], false)
            ->assertDontSee('Other program week')
            ->assertViewHas('weeks', fn (array $weeks): bool => array_column($weeks, 'number') === [1, 2]
                && $weeks[0]['active_activity_count'] === 2
                && $weeks[0]['checklist_item_count'] === 3
                && $weeks[1]['active_activity_count'] === 1
                && $weeks[1]['checklist_item_count'] === 0);
    }

    public function test_the_notice_warns_while_a_session_of_the_program_is_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        TrainingSession::factory()->for($program)->active()->create(['title' => 'Live Run']);
        TrainingSession::factory()->for($program)->completed()->create();
        TrainingSession::factory()->for(Program::factory())->active()->create(['title' => 'Other program run']);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('data-sessions-notice="warning"', false)
            ->assertSee('Used by 2 training sessions (1 active, 0 upcoming, 1 completed).')
            ->assertSeeInOrder(['Live Run', 'Active'])
            ->assertDontSee('Other program run');
    }

    public function test_the_notice_of_a_program_without_sessions_says_so(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('data-sessions-notice="info"', false)
            ->assertSee('No training session uses this program yet.')
            ->assertDontSee('data-notice-session', false)
            ->assertSee('This program has no weeks yet.');
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

    public function test_participants_and_coaches_are_forbidden_on_the_curriculum_pages(): void
    {
        $program = Program::factory()->create();
        $week = $this->week($program, 1);

        foreach (['participant', 'coach'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('admin.programs.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.programs.show', $program))->assertForbidden();
            $this->actingAs($user)->get(route('admin.programs.weeks.show', [$program, $week]))->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_login_from_the_curriculum_pages(): void
    {
        $program = Program::factory()->create();
        $week = $this->week($program, 1);

        $this->get(route('admin.programs.index'))->assertRedirect(route('login'));
        $this->get(route('admin.programs.show', $program))->assertRedirect(route('login'));
        $this->get(route('admin.programs.weeks.show', [$program, $week]))->assertRedirect(route('login'));
    }

    public function test_the_program_page_query_count_does_not_grow_with_weeks_activities_checklists_and_sessions(): void
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
        TrainingSession::factory()->for($program)->active()->create(['title' => 'Live Run']);

        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertSee('<title>Week 2: Testing</title>', false)
            ->assertSeeInOrder(['ADMIN · CURRICULUM', 'Week 2: Testing', 'L4 Accelerator Training', 'Active'])
            ->assertSee('<a href="'.route('admin.programs.show', $program).'"', false)
            ->assertSee('data-sessions-notice="warning"', false)
            ->assertSee('Live Run')
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

    public function test_the_week_page_query_count_does_not_grow_with_activities_checklist_items_and_sessions(): void
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
     * items, and that many sessions, and count the queries of an admin's program page request after a warm-up request.
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
        TrainingSession::factory()->for($program)->count($count)->create();

        $this->actingAs($admin)->get(route('admin.programs.show', $program))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertViewHas('weeks', fn (array $weeks): bool => count($weeks) === $count
                && array_sum(array_column($weeks, 'active_activity_count')) === $count * $count
                && array_sum(array_column($weeks, 'checklist_item_count')) === $count * $count)
            ->assertViewHas('notice', fn (array $notice): bool => count($notice['sessions']) === $count);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Create a week with that many activities, a checklist with that many items on it and that many retired, and a
     * program with that many sessions, and count the queries of an admin's week page request after a warm-up request.
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
        TrainingSession::factory()->for($program)->count($count)->create();

        $this->actingAs($admin)->get(route('admin.programs.weeks.show', [$program, $week]))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->get(route('admin.programs.weeks.show', [$program, $week]))
            ->assertOk()
            ->assertViewHas('activities', fn (array $activities): bool => count($activities) === $count)
            ->assertViewHas('checklist', fn (array $checklist): bool => count($checklist['items']) === $count
                && count($checklist['removed_items']) === $count)
            ->assertViewHas('notice', fn (array $notice): bool => count($notice['sessions']) === $count);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
