<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCurriculumDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_header_shows_the_curriculum_facts_and_the_seven_tabs_show_live_counts(): void
    {
        $this->travelTo('2026-11-12 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create(['name' => 'L4 Accelerator Training']);
        $firstWeek = $this->week($program, 1);
        Activity::factory()->for($firstWeek, 'week')->count(2)->create();
        Activity::factory()->for($firstWeek, 'week')->create(['is_active' => false]);
        Activity::factory()->for($this->week($program, 2), 'week')->create();
        Activity::factory()->for($this->week($program, 3, isActive: false), 'week')->create();
        $program->participants()->attach(User::factory()->count(3)->create(), ['joined_at' => now()]);
        Program::factory()->withParticipants(User::factory()->create())->create();

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['L4 Accelerator Training', 'data-program-schedule-summary', '>Active</span>', '9 – 22 Nov 2026'], false)
            ->assertSee('role="tablist" aria-label="Program sections"', false)
            ->assertSeeInOrder([
                'data-tab-key="overview"', 'Overview',
                'data-tab-key="weeks"', 'Weeks (3)',
                'data-tab-key="participants"', 'Participants (3)',
                'data-tab-key="mentors"', 'Mentors (0)',
                'data-tab-key="resources"', 'Resources (0)',
                'data-tab-key="activities"', 'Activities (3)',
                'data-tab-key="schedule"', 'Schedule',
                '</div>',
            ], false)
            ->assertSee('2 weeks')
            ->assertSee('No mentors in this curriculum yet.')
            ->assertSee('No resources in this program yet.')
            ->assertViewHas('tabs', fn (array $tabs): bool => array_column($tabs, 'key') === ['overview', 'weeks', 'participants', 'mentors', 'resources', 'activities', 'schedule']);
    }

    public function test_each_tab_opens_from_its_hash_and_the_overview_is_selected_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->twoWeekProgram();

        $response = $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('data-page-tabs', false)
            ->assertSee('aria-controls="program-panel-overview" aria-selected="true" tabindex="0" data-tab-key="overview"', false);
        $this->assertDoesNotMatchRegularExpression('/id="program-panel-overview"[^>]*\shidden[\s>]/', $response->getContent());

        foreach (['weeks', 'participants', 'mentors', 'resources', 'activities', 'schedule'] as $tab) {
            $response->assertSee('aria-controls="program-panel-'.$tab.'" aria-selected="false" tabindex="-1" data-tab-key="'.$tab.'"', false);
            $this->assertMatchesRegularExpression('/id="program-panel-'.$tab.'"[^>]*\shidden[\s>]/', $response->getContent());
        }
    }

    public function test_participant_rows_show_name_employee_id_job_role_and_joined_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->twoWeekProgram();
        $program->participants()->attach([
            User::factory()->create(['name' => 'Ben Cruz', 'employee_id' => 'EMP-0002', 'job_role' => JobRole::ProjectManager])->id => ['joined_at' => '2026-11-10 14:00:00'],
            User::factory()->create(['name' => 'Ava Santos', 'employee_id' => 'EMP-0001', 'job_role' => JobRole::Developer])->id => ['joined_at' => '2026-11-09 10:00:00'],
            User::factory()->create(['name' => 'Cara Reyes', 'employee_id' => null, 'job_role' => null])->id => ['joined_at' => '2026-11-11 08:00:00'],
        ]);
        User::factory()->create(['name' => 'Olive Outsider']);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder([
                'Ava Santos', 'EMP-0001', 'Developer', '9 Nov 2026',
                'Ben Cruz', 'EMP-0002', 'Project Manager (PM)', '10 Nov 2026',
                'Cara Reyes', '—', '—', '11 Nov 2026',
            ])
            ->assertDontSee('>Olive Outsider</th>', false);
    }

    public function test_the_activities_tab_lists_the_active_activities_of_the_active_weeks_in_week_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $secondWeek = $this->week($program, 2);
        $firstWeek = $this->week($program, 1);
        Activity::factory()->for($secondWeek, 'week')->create(['title' => 'Ship the API', 'position' => 1]);
        Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Write the tests', 'position' => 2]);
        Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Set up the repo', 'position' => 1]);
        Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Retired activity', 'is_active' => false]);
        Activity::factory()->for($this->week($program, 3, isActive: false), 'week')->create(['title' => 'Inactive week activity']);
        Activity::factory()->create(['title' => 'Other program activity']);

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['id="program-panel-activities"', 'Week 1', 'Set up the repo', 'Week 1', 'Write the tests', 'Week 2', 'Ship the API'], false)
            ->assertDontSee('Retired activity')
            ->assertDontSee('Inactive week activity')
            ->assertDontSee('Other program activity');
    }

    public function test_the_activities_tab_points_to_the_weeks_tab_to_edit_an_activity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = $this->twoWeekProgram();

        $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder([
                'id="program-panel-activities"',
                'data-weeks-link',
                '<a href="#weeks"',
                'Weeks tab</a>',
                'This program&#039;s active weeks have no activities yet.',
            ], false);
    }

    public function test_the_activities_tab_counts_each_current_participants_latest_attempt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $firstWeek = $this->week($program, 1);
        $setUpRepo = Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Set up the repo', 'position' => 1]);
        $writeTests = Activity::factory()->for($firstWeek, 'week')->create(['title' => 'Write the tests', 'position' => 2]);
        $shipApi = Activity::factory()->for($this->week($program, 2), 'week')->create(['title' => 'Ship the API']);
        [$ava, $ben, $cara, $dan, $eve, $fay] = User::factory()->count(6)->create()->all();
        $program->participants()->attach([$ava->id, $ben->id, $cara->id, $dan->id, $eve->id], ['joined_at' => now()]);
        $this->attempt($setUpRepo, $ava, 'failed');
        $this->attempt($setUpRepo, $ava, 'approved', attemptNumber: 2);
        $this->attempt($setUpRepo, $ben, 'failed');
        $this->attempt($setUpRepo, $ben, 'draft', attemptNumber: 2);
        $this->attempt($setUpRepo, $cara, 'failed');
        $this->attempt($setUpRepo, $dan, 'submitted');
        $this->attempt($writeTests, $ava, 'under_review');
        $this->attempt($writeTests, $ben, 'needs_revision');
        ActivitySubmission::factory()->for($writeTests)->for($cara, 'participant')->create(['status' => 'draft', 'submitted_at' => now()->subDays(3)]);
        $this->attempt($writeTests, $dan, 'completed');
        $this->attempt($setUpRepo, $fay, 'submitted');
        $this->attempt($writeTests, $fay, 'approved');
        $this->attempt($shipApi, $fay, 'needs_revision');

        $response = $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSee('Activities (3)')
            ->assertSeeInOrder(['Not started', 'In progress', 'Awaiting review', 'Needs revision', 'Approved', 'Failed', 'Reviews'])
            ->assertSee('<a href="'.e(route('coach.reviews', ['program' => $program->id, 'activity' => $setUpRepo->id])).'" aria-label="Open the review queue for Set up the repo"', false);

        $this->assertSame([
            'Set up the repo' => ['not_started' => 1, 'in_progress' => 1, 'awaiting_review' => 1, 'needs_revision' => 0, 'approved' => 1, 'failed' => 1],
            'Write the tests' => ['not_started' => 1, 'in_progress' => 1, 'awaiting_review' => 1, 'needs_revision' => 1, 'approved' => 1, 'failed' => 0],
            'Ship the API' => ['not_started' => 5, 'in_progress' => 0, 'awaiting_review' => 0, 'needs_revision' => 0, 'approved' => 0, 'failed' => 0],
        ], array_column($response->viewData('activities'), 'counts', 'title'));
        $this->assertSame(
            [
                route('coach.reviews', ['program' => $program->id, 'activity' => $setUpRepo->id]),
                route('coach.reviews', ['program' => $program->id, 'activity' => $writeTests->id]),
                route('coach.reviews', ['program' => $program->id, 'activity' => $shipApi->id]),
            ],
            array_column($response->viewData('activities'), 'review_queue_url'),
        );
    }

    public function test_the_overview_counts_only_the_current_participants_and_their_records(): void
    {
        $this->travelTo('2026-11-17 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $firstWeek = $this->week($program, 1);
        $secondWeek = $this->week($program, 2);
        [$setUpRepo, $writeTests] = Activity::factory()->for($firstWeek, 'week')->count(2)->create()->all();
        $shipApi = Activity::factory()->for($secondWeek, 'week')->create();
        $firstGate = TrainingGate::factory()->for($firstWeek, 'week')->create();
        $secondGate = TrainingGate::factory()->for($secondWeek, 'week')->create();
        [$ava, $ben, $cara, $dan, $eve, $fay] = User::factory()->count(6)->create()->all();
        [$mia, $max] = User::factory()->count(2)->create(['role' => 'coach'])->all();
        $program->participants()->attach([$ava->id, $ben->id, $cara->id, $dan->id], ['joined_at' => now()]);
        $program->mentors()->attach([$mia->id, $max->id]);
        Program::factory()->withParticipants($dan, $eve)->create();
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $ava->id, 'mentor_id' => $mia->id]);
        MentorAssignment::factory()->ended()->create(['program_id' => $program->id, 'participant_id' => $ben->id, 'mentor_id' => $max->id]);
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $dan->id, 'mentor_id' => $max->id]);
        $this->attempt($setUpRepo, $ava, 'submitted');
        $this->attempt($shipApi, $ava, 'needs_revision');
        $this->attempt($setUpRepo, $ben, 'under_review');
        $this->attempt($writeTests, $ben, 'approved');
        $this->attempt($shipApi, $ben, 'draft');
        $this->attempt($setUpRepo, $cara, 'draft');
        $this->attempt($setUpRepo, $dan, 'resubmitted');
        $this->attempt($writeTests, $dan, 'needs_revision');
        $this->review($firstGate, $ava, 'needs_revision');
        $this->review($firstGate, $ben, 'passed');
        $this->review($firstGate, $cara, 'failed');
        $this->review($secondGate, $dan, 'needs_revision');
        $this->attempt($setUpRepo, $fay, 'submitted');
        $this->attempt($shipApi, $fay, 'needs_revision');
        $this->review($firstGate, $fay, 'needs_revision');
        $this->attempt($shipApi, $eve, 'submitted');

        $response = $this->actingAs($admin)
            ->get(route('admin.programs.show', $program))
            ->assertOk()
            ->assertSeeInOrder(['Participants and reviews', 'Awaiting review', '3', 'Activity attempts waiting for a mentor'])
            ->assertSeeInOrder(['Participants who handed in work', 'Week 1', '3', 'Week 2', '1']);

        $this->assertSame([
            'Participants' => 4,
            'Mentors' => 2,
            'Unassigned' => 2,
            'Awaiting review' => 3,
            'Needs revision' => 2,
            'Pending gates' => 3,
            'Participants with locked weeks' => 2,
        ], array_column($response->viewData('overviewStats'), 'value', 'label'));
        $this->assertSame(['Week 1' => 3, 'Week 2' => 1], array_column($response->viewData('weekWorkStats'), 'value', 'label'));
    }

    public function test_the_overview_of_a_curriculum_without_participants_counts_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $activity = Activity::factory()->for($this->week($program, 1), 'week')->create();
        $this->week($program, 2);
        $program->mentors()->attach(User::factory()->create(['role' => 'coach']));
        $this->attempt($activity, User::factory()->create(), 'submitted');

        $response = $this->actingAs($admin)->get(route('admin.programs.show', $program))->assertOk();

        $this->assertSame([
            'Participants' => 0,
            'Mentors' => 1,
            'Unassigned' => 0,
            'Awaiting review' => 0,
            'Needs revision' => 0,
            'Pending gates' => 0,
            'Participants with locked weeks' => 0,
        ], array_column($response->viewData('overviewStats'), 'value', 'label'));
        $this->assertSame(['Week 1' => 0, 'Week 2' => 0], array_column($response->viewData('weekWorkStats'), 'value', 'label'));
        $this->assertSame(
            [['not_started' => 0, 'in_progress' => 0, 'awaiting_review' => 0, 'needs_revision' => 0, 'approved' => 0, 'failed' => 0]],
            array_column($response->viewData('activities'), 'counts'),
        );
    }

    public function test_query_count_does_not_grow_with_participants_mentors_assignments_activities_and_their_work(): void
    {
        $this->assertSame($this->dashboardQueryCount(1), $this->dashboardQueryCount(4));
    }

    /**
     * Week N of the curriculum, active unless told otherwise, dated from Mon 9 Nov 2026 + 7 × (N − 1) days.
     */
    private function week(Program $program, int $weekNumber, bool $isActive = true): TrainingWeek
    {
        return TrainingWeek::factory()
            ->for($program)
            ->opensOn(Carbon::parse('2026-11-09')->addWeeks($weekNumber - 1))
            ->create(['week_number' => $weekNumber, 'position' => $weekNumber, 'is_active' => $isActive]);
    }

    /**
     * A curriculum with weeks 1 and 2, from Mon 9 Nov to Sun 22 Nov 2026.
     */
    private function twoWeekProgram(): Program
    {
        $program = Program::factory()->create();
        $this->week($program, 1);
        $this->week($program, 2);

        return $program;
    }

    /**
     * The participant's attempt (the first unless given) at the activity with the status, handed in a day ago unless
     * it is a draft.
     */
    private function attempt(Activity $activity, User $participant, string $status, int $attemptNumber = 1): void
    {
        ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create([
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'submitted_at' => $status === 'draft' ? null : now()->subDay(),
        ]);
    }

    /**
     * A mentor's review of the participant's checklist (Gate) with the status, saved two days ago.
     */
    private function review(TrainingGate $gate, User $participant, string $status): void
    {
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create([
            'status' => $status,
            'evaluated_at' => now()->subDays(2),
        ]);
    }

    /**
     * Create a 2-week curriculum with the given number of participants, of mentors and of activities in each week, each
     * participant with an ended and a current mentor assignment, an attempt awaiting review in Week 1, one needing
     * revision in Week 2, a failed attempt and its retry draft at the last Week 2 activity and an incomplete Week 1
     * review that locks Week 2, plus an attempt by someone outside the curriculum, and count the queries of an admin's
     * curriculum page request after a warm-up request.
     */
    private function dashboardQueryCount(int $count): int
    {
        $this->travelTo('2026-11-17 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $firstWeek = $this->week($program, 1);
        $secondWeek = $this->week($program, 2);
        $firstWeekActivities = Activity::factory()->for($firstWeek, 'week')->count($count)->create();
        $secondWeekActivities = Activity::factory()->for($secondWeek, 'week')->count($count)->create();
        $firstGate = TrainingGate::factory()->for($firstWeek, 'week')->create();
        $participants = User::factory()->count($count)->create();
        $mentors = User::factory()->count($count)->create(['role' => 'coach']);
        $program->participants()->attach($participants, ['joined_at' => now()]);
        $program->mentors()->attach($mentors);
        foreach ($participants as $index => $participant) {
            MentorAssignment::factory()->ended()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $mentors[$index]->id]);
            MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $mentors[$index]->id]);
            $this->attempt($firstWeekActivities[$index], $participant, 'submitted');
            $this->attempt($secondWeekActivities[$index], $participant, 'needs_revision');
            $this->attempt($secondWeekActivities->last(), $participant, 'failed');
            $this->attempt($secondWeekActivities->last(), $participant, 'draft', attemptNumber: 2);
            $this->review($firstGate, $participant, 'needs_revision');
            $this->attempt($firstWeekActivities[$index], User::factory()->create(), 'submitted');
        }

        $this->actingAs($admin)->get(route('admin.programs.show', $program))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.programs.show', $program))->assertOk()->assertSee("Participants ({$count})")
            ->assertViewHas('weekWorkStats', [['label' => 'Week 1', 'value' => $count], ['label' => 'Week 2', 'value' => $count]])
            ->assertViewHas('overviewStats', fn (array $stats): bool => array_column($stats, 'value', 'label')['Participants with locked weeks'] === $count)
            ->assertViewHas('activities', fn (array $activities): bool => array_sum(array_map(fn (array $activity): int => $activity['counts']['in_progress'], $activities)) === $count
                && array_sum(array_map(fn (array $activity): int => $activity['counts']['awaiting_review'], $activities)) === $count);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
