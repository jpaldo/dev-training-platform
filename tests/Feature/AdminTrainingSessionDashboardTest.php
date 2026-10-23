<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTrainingSessionDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_header_shows_the_session_facts_and_the_tab_labels_show_live_counts(): void
    {
        $this->travelTo('2026-11-12 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $firstWeek = $this->week($program, 1);
        Activity::factory()->for($firstWeek, 'week')->count(2)->create();
        Activity::factory()->for($firstWeek, 'week')->create(['is_active' => false]);
        Activity::factory()->for($this->week($program, 2), 'week')->create();
        Activity::factory()->for($this->week($program, 3), 'week')->create();
        $session = $this->twoWeekSession($program);
        $session->participants()->attach(User::factory()->count(3)->create(), ['joined_at' => now()]);
        TrainingSession::factory()->for($program)->create()->participants()->attach(User::factory()->create(), ['joined_at' => now()]);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['November Run', 'L4 Accelerator Training', '9 Nov – 22 Nov 2026', 'Active'])
            ->assertSee('<a href="'.route('admin.sessions.edit', $session).'"', false)
            ->assertSeeInOrder(['Overview', 'Participants (3)', 'Mentors (0)', 'Activities (3)', 'Resources (0)'])
            ->assertSee('2 weeks')
            ->assertSee('No mentors in this session yet.')
            ->assertSee('No resources in this session yet.');
    }

    public function test_each_tab_opens_from_its_hash_and_the_overview_is_selected_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = $this->twoWeekSession(Program::factory()->create());

        $response = $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSee('data-page-tabs', false)
            ->assertSee('aria-controls="session-panel-overview" aria-selected="true" tabindex="0" data-tab-key="overview"', false);
        $this->assertDoesNotMatchRegularExpression('/id="session-panel-overview"[^>]*\shidden[\s>]/', $response->getContent());

        foreach (['participants', 'mentors', 'activities', 'resources'] as $tab) {
            $response->assertSee('aria-controls="session-panel-'.$tab.'" aria-selected="false" tabindex="-1" data-tab-key="'.$tab.'"', false);
            $this->assertMatchesRegularExpression('/id="session-panel-'.$tab.'"[^>]*\shidden[\s>]/', $response->getContent());
        }
    }

    public function test_participant_rows_show_name_employee_id_job_role_and_joined_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $session = $this->twoWeekSession(Program::factory()->create());
        $session->participants()->attach([
            User::factory()->create(['name' => 'Ben Cruz', 'employee_id' => 'EMP-0002', 'job_role' => JobRole::ProjectManager])->id => ['joined_at' => '2026-11-10 14:00:00'],
            User::factory()->create(['name' => 'Ava Santos', 'employee_id' => 'EMP-0001', 'job_role' => JobRole::Developer])->id => ['joined_at' => '2026-11-09 10:00:00'],
            User::factory()->create(['name' => 'Cara Reyes', 'employee_id' => null, 'job_role' => null])->id => ['joined_at' => '2026-11-11 08:00:00'],
        ]);
        User::factory()->create(['name' => 'Olive Outsider']);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder([
                'Ava Santos', 'EMP-0001', 'Developer', '9 Nov 2026',
                'Ben Cruz', 'EMP-0002', 'Project Manager (PM)', '10 Nov 2026',
                'Cara Reyes', '—', '—', '11 Nov 2026',
            ])
            ->assertDontSee('Olive Outsider');
    }

    public function test_the_activities_tab_lists_the_active_activities_of_the_session_weeks_in_week_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $secondWeek = $this->week($program, 2);
        $firstWeek = $this->week($program, 1);
        Activity::factory()->for($secondWeek, 'week')->create(['title' => 'Ship the API', 'position' => 1]);
        Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Write the tests', 'position' => 2]);
        Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Set up the repo', 'position' => 1]);
        Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Retired activity', 'is_active' => false]);
        Activity::factory()->for($this->week($program, 3), 'week')->create(['title' => 'Week three activity']);
        Activity::factory()->create(['title' => 'Other program activity']);
        $session = $this->twoWeekSession($program);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
            ->assertOk()
            ->assertSeeInOrder(['Week 1', 'Set up the repo', 'Week 1', 'Write the tests', 'Week 2', 'Ship the API'])
            ->assertDontSee('Retired activity')
            ->assertDontSee('Week three activity')
            ->assertDontSee('Other program activity');
    }

    public function test_an_unknown_session_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.sessions.show', 999))->assertNotFound();
    }

    public function test_query_count_does_not_grow_with_participants_and_activities(): void
    {
        $this->assertSame($this->dashboardQueryCount(1), $this->dashboardQueryCount(4));
    }

    private function week(Program $program, int $weekNumber): TrainingWeek
    {
        return TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
    }

    /**
     * A 2-week session of the program titled "November Run", from Mon 9 Nov to Sun 22 Nov 2026.
     */
    private function twoWeekSession(Program $program): TrainingSession
    {
        return TrainingSession::factory()->for($program)->create([
            'title' => 'November Run',
            'weeks_count' => 2,
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-22',
        ]);
    }

    /**
     * Create a 2-week session with the given number of participants and of activities in each week, and count the
     * queries of an admin's dashboard request after a warm-up request.
     */
    private function dashboardQueryCount(int $count): int
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        Activity::factory()->for($this->week($program, 1), 'week')->count($count)->create();
        Activity::factory()->for($this->week($program, 2), 'week')->count($count)->create();
        $session = $this->twoWeekSession($program);
        $session->participants()->attach(User::factory()->count($count)->create(), ['joined_at' => now()]);

        $this->actingAs($admin)->get(route('admin.sessions.show', $session))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.sessions.show', $session))->assertOk()->assertSee("Participants ({$count})");
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
