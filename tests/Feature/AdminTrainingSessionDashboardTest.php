<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingGate;
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
            ->assertDontSee('>Olive Outsider</th>', false);
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

    public function test_the_overview_counts_only_the_current_participants_and_their_records_in_this_session(): void
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
        $session = $this->twoWeekSession($program);
        $session->participants()->attach([$ava->id, $ben->id, $cara->id, $dan->id], ['joined_at' => now()]);
        $session->mentors()->attach([$mia->id, $max->id]);
        $otherSession = TrainingSession::factory()->for($program)->create(['weeks_count' => 2, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-22']);
        $otherSession->participants()->attach([$dan->id, $eve->id], ['joined_at' => now()]);
        MentorAssignment::factory()->for($session)->create(['participant_id' => $ava->id, 'mentor_id' => $mia->id]);
        MentorAssignment::factory()->for($session)->ended()->create(['participant_id' => $ben->id, 'mentor_id' => $max->id]);
        MentorAssignment::factory()->for($session)->create(['participant_id' => $dan->id, 'mentor_id' => $max->id]);
        $this->attempt($setUpRepo, $session, $ava, 'submitted');
        $this->attempt($shipApi, $session, $ava, 'needs_revision');
        $this->attempt($setUpRepo, $session, $ben, 'under_review');
        $this->attempt($writeTests, $session, $ben, 'approved');
        $this->attempt($shipApi, $session, $ben, 'draft');
        $this->attempt($setUpRepo, $session, $cara, 'draft');
        $this->attempt($setUpRepo, $session, $dan, 'resubmitted');
        $this->attempt($writeTests, $session, $dan, 'needs_revision');
        $this->review($firstGate, $session, $ava, 'needs_revision');
        $this->review($firstGate, $session, $ben, 'passed');
        $this->review($firstGate, $session, $cara, 'failed');
        $this->review($secondGate, $session, $dan, 'needs_revision');
        $this->attempt($setUpRepo, $session, $fay, 'submitted');
        $this->attempt($shipApi, $session, $fay, 'needs_revision');
        $this->review($firstGate, $session, $fay, 'needs_revision');
        $this->attempt($shipApi, $otherSession, $dan, 'submitted');
        $this->attempt($writeTests, $otherSession, $dan, 'needs_revision');
        $this->review($firstGate, $otherSession, $dan, 'needs_revision');
        $this->attempt($shipApi, $otherSession, $eve, 'submitted');

        $response = $this->actingAs($admin)
            ->get(route('admin.sessions.show', $session))
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

    public function test_the_overview_of_a_session_without_participants_counts_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $activity = Activity::factory()->for($this->week($program, 1), 'week')->create();
        $this->week($program, 2);
        $session = $this->twoWeekSession($program);
        $session->mentors()->attach(User::factory()->create(['role' => 'coach']));
        $otherSession = TrainingSession::factory()->for($program)->create();
        $participant = User::factory()->create();
        $otherSession->participants()->attach($participant, ['joined_at' => now()]);
        $this->attempt($activity, $otherSession, $participant, 'submitted');

        $response = $this->actingAs($admin)->get(route('admin.sessions.show', $session))->assertOk();

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
    }

    public function test_an_unknown_session_is_not_found(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.sessions.show', 999))->assertNotFound();
    }

    public function test_query_count_does_not_grow_with_participants_mentors_assignments_activities_and_their_work(): void
    {
        $this->assertSame($this->dashboardQueryCount(1), $this->dashboardQueryCount(4));
    }

    private function week(Program $program, int $weekNumber): TrainingWeek
    {
        return TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
    }

    /**
     * The participant's attempt at the activity in the session with the status, handed in a day ago unless it is a
     * draft.
     */
    private function attempt(Activity $activity, TrainingSession $session, User $participant, string $status): void
    {
        ActivitySubmission::factory()->for($activity)->for($session)->for($participant, 'participant')->create([
            'status' => $status,
            'submitted_at' => $status === 'draft' ? null : now()->subDay(),
        ]);
    }

    /**
     * A mentor's review of the participant's checklist (Gate) in the session with the status, saved two days ago.
     */
    private function review(TrainingGate $gate, TrainingSession $session, User $participant, string $status): void
    {
        GateEvaluation::factory()->for($gate, 'gate')->for($session)->for($participant, 'participant')->create([
            'status' => $status,
            'evaluated_at' => now()->subDays(2),
        ]);
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
     * Create a 2-week session with the given number of participants, of mentors and of activities in each week, each
     * participant with an ended and a current mentor assignment, an attempt awaiting review in Week 1, one needing
     * revision in Week 2, an incomplete Week 1 review that locks Week 2 and an attempt in another session, and count the
     * queries of an admin's dashboard request after a warm-up request.
     */
    private function dashboardQueryCount(int $count): int
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $program = Program::factory()->create();
        $firstWeek = $this->week($program, 1);
        $secondWeek = $this->week($program, 2);
        $firstWeekActivities = Activity::factory()->for($firstWeek, 'week')->count($count)->create();
        $secondWeekActivities = Activity::factory()->for($secondWeek, 'week')->count($count)->create();
        $firstGate = TrainingGate::factory()->for($firstWeek, 'week')->create();
        $session = $this->twoWeekSession($program);
        $otherSession = TrainingSession::factory()->for($program)->create();
        $participants = User::factory()->count($count)->create();
        $mentors = User::factory()->count($count)->create(['role' => 'coach']);
        $session->participants()->attach($participants, ['joined_at' => now()]);
        $session->mentors()->attach($mentors);
        foreach ($participants as $index => $participant) {
            MentorAssignment::factory()->for($session)->ended()->create(['participant_id' => $participant->id, 'mentor_id' => $mentors[$index]->id]);
            MentorAssignment::factory()->for($session)->create(['participant_id' => $participant->id, 'mentor_id' => $mentors[$index]->id]);
            $this->attempt($firstWeekActivities[$index], $session, $participant, 'submitted');
            $this->attempt($secondWeekActivities[$index], $session, $participant, 'needs_revision');
            $this->review($firstGate, $session, $participant, 'needs_revision');
            $this->attempt($firstWeekActivities[$index], $otherSession, $participant, 'submitted');
        }

        $this->actingAs($admin)->get(route('admin.sessions.show', $session))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.sessions.show', $session))->assertOk()->assertSee("Participants ({$count})")
            ->assertViewHas('weekWorkStats', [['label' => 'Week 1', 'value' => $count], ['label' => 'Week 2', 'value' => $count]]);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
