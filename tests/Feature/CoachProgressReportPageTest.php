<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoachProgressReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_mentor_sees_the_report_navigation_summary_at_risk_rows_and_participant_progress_of_their_mentees(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $activity = Activity::factory()->create();
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Avery Session']);

        $risky = User::factory()->create(['name' => 'Riley Risk', 'email' => 'riley@example.test']);
        $this->mentor($session, $coach, $risky);
        $revision = ActivitySubmission::factory()->for($activity)->for($risky, 'participant')->create(['training_session_id' => $session->id, 'status' => 'needs_revision']);
        GateEvaluation::factory()->for($gate, 'gate')->for($risky, 'participant')->for($session)->create(['status' => 'failed']);
        $secondGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        GateEvaluation::factory()->for($secondGate, 'gate')->for($risky, 'participant')->for($session)->create(['status' => 'passed']);

        $steady = User::factory()->create(['name' => 'Sam Steady', 'email' => 'sam@example.test', 'is_active' => false]);
        $this->mentor($session, $coach, $steady);
        ActivitySubmission::factory()->for($activity)->for($steady, 'participant')->create(['training_session_id' => $session->id, 'status' => 'approved']);

        $unassigned = User::factory()->create(['name' => 'Una Unassigned']);
        $session->participants()->attach($unassigned->id, ['joined_at' => now()]);
        ActivitySubmission::factory()->for($activity)->for($unassigned, 'participant')->create(['training_session_id' => $session->id, 'status' => 'failed']);

        $response = $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR / REPORTS',
                'Progress report',
                'href="'.e(route('coach.reports.export', ['type' => 'progress'])).'"', '>Export Progress CSV</a>',
                'aria-label="Reports"',
                'href="'.route('coach.reports').'"', 'aria-current="page"', '>Progress</a>',
                'href="'.route('coach.reports.missing-submissions').'"', '>Missing Submissions</a>',
                '<label for="report-session"', 'Training session</label>',
                '<option value="">All training sessions</option>', '<option value="'.$session->id.'" >'.e($session->titleWithDates()).'</option>',
                'Total Participants', '>2</dd>',
                'Active Participants', '>1</dd>',
                'Submissions', '>2</dd>',
                'Gates Passed', '>1</dd>',
                'At Risk', '>1</dd>',
                'At-risk participants',
                '>Training session</th>',
                '>Riley Risk</th>', '>Avery Session</span>', '>'.$session->dateRangeLabel().'</span>',
                '>'.e($revision->activity->title).': Needs revision · '.e($gate->title).': Failed</td>',
                'Participant progress',
                '2 participant(s)',
                '>Training session</th>', '>Mentor</th>',
                '>Riley Risk</strong>', '>riley@example.test</span>', '>Avery Session</span>',
                '>Coach Avery</td>',
                '>1</td>', '>1</td>',
                '>Sam Steady</strong>', '>sam@example.test</span>', '>Avery Session</span>',
                '>Coach Avery</td>',
                '>1</td>', '>0</td>',
            ], false)
            ->assertDontSee('id="report-mentor"', false)
            ->assertDontSee('Una Unassigned')
            ->assertDontSee('No current at-risk participants.')
            ->assertDontSee('No participants match the selected filters.')
            ->assertDontSee('Training Path')
            ->assertDontSee('Automation / Manual');

        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $response->getContent(), $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertStringNotContainsString('Daily Log', $reportNav[1]);
    }

    public function test_each_mentor_sees_only_their_current_mentees_work_in_the_session_where_they_mentor_them(): void
    {
        ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy] = $this->mentorScopeFixture();

        $benResponse = $this->actingAs($ben)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'Total Participants', '>1</dd>', 'Submissions', '>1</dd>', 'Gates Passed', '>0</dd>', 'At Risk', '>1</dd>',
                '>Pat Participant</th>', '>Session X</span>', ': Needs revision</td>',
                '1 participant(s)', '>Pat Participant</strong>', '>Session X</span>',
            ], false)
            ->assertDontSee('Quinn Other')
            ->assertDontSee('Session Y</span>', false)
            ->assertDontSee(': Failed</td>', false);
        $this->assertSame(1, substr_count($benResponse->getContent(), 'data-participant-row'));

        $this->actingAs($cy)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'Total Participants', '>1</dd>', 'Submissions', '>0</dd>', 'Gates Passed', '>0</dd>', 'At Risk', '>1</dd>',
                '>Pat Participant</th>', '>Session Y</span>', ': Failed</td>',
                '1 participant(s)', '>Pat Participant</strong>', '>Session Y</span>',
            ], false)
            ->assertDontSee('Session X</span>', false)
            ->assertDontSee(': Needs revision</td>', false);

        $this->actingAs($ana)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Total Participants', '>0</dd>', 'At Risk', '>0</dd>'], false)
            ->assertSee('No current at-risk participants.')
            ->assertSee('No participants match the selected filters.')
            ->assertDontSee('Pat Participant');

        $this->assertSame([$sessionX->id], $this->sessionOptionIds($this->actingAs($ben)->get(route('coach.reports'))));
        $this->assertSame([$sessionY->id], $this->sessionOptionIds($this->actingAs($cy)->get(route('coach.reports'))));
    }

    public function test_an_admin_sees_every_membership_and_filters_by_session_and_mentor(): void
    {
        ['sessionY' => $sessionY, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan] = $this->mentorScopeFixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $everyRow = $this->actingAs($admin)->get(route('coach.reports'))
            ->assertOk()
            ->assertSee('3 participant(s)')
            ->assertSee('<option value="">All mentors</option>', false)
            ->assertSee('<option value="'.$ben->id.'" >', false)
            ->assertSee('<option value="'.$cy->id.'" >', false);
        $this->assertSame(3, substr_count($everyRow->getContent(), 'data-participant-row'));
        $this->assertSame(2, substr_count($everyRow->getContent(), 'data-at-risk-row'));

        $sessionYRows = $this->actingAs($admin)->get(route('coach.reports', ['session' => $sessionY->id]))
            ->assertOk()
            ->assertSee('<option value="'.$sessionY->id.'" selected>', false)
            ->assertSee('1 participant(s)')
            ->assertSee('>Session Y</span>', false)
            ->assertDontSee('>Session X</span>', false);
        $this->assertSame(1, substr_count($sessionYRows->getContent(), 'data-participant-row'));

        $danRows = $this->actingAs($admin)->get(route('coach.reports', ['mentor' => $dan->id]))
            ->assertOk()
            ->assertSee('<option value="'.$dan->id.'" selected>', false)
            ->assertSeeInOrder(['Submissions', '>1</dd>', 'At Risk', '>0</dd>'], false)
            ->assertSee('No current at-risk participants.')
            ->assertSee('>Quinn Other</strong>', false)
            ->assertDontSee('>Pat Participant</strong>', false);
        $this->assertSame(1, substr_count($danRows->getContent(), 'data-participant-row'));
    }

    public function test_an_unknown_session_or_a_mentor_who_is_not_a_coach_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.reports', ['session' => 999, 'mentor' => $admin->id]))
            ->assertSessionHasErrors(['session', 'mentor']);
        $this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'progress', 'session' => 999, 'mentor' => $admin->id]))
            ->assertSessionHasErrors(['session', 'mentor']);
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $activity = Activity::factory()->create();
        $session = TrainingSession::factory()->forWeek($activity->week)->create();
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create();
        $this->mentor($session, $mentor, ...$participants->all());
        $participants->each(fn (User $participant): ActivitySubmission => ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['training_session_id' => $session->id, 'status' => 'failed']));
        $this->mentor($session, $mentor, User::factory()->create(['name' => 'Other Mentee']));
        $session->participants()->attach(User::factory()->create(['name' => 'Page Unassigned'])->id, ['joined_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $filters = ['search' => 'Page', 'session' => $session->id, 'mentor' => $mentor->id];

        $firstPage = $this->actingAs($admin)->get(route('coach.reports', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertDontSee('name="path"', false)
            ->assertSee('<option value="'.$session->id.'" selected>', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('href="'.e(route('coach.reports.export', [...$filters, 'type' => 'progress'])).'"', false)
            ->assertSee('26 participant(s)')
            ->assertSee(e(route('coach.reports', [...$filters, 'risk_page' => 2])), false)
            ->assertSee(e(route('coach.reports', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Other Mentee')
            ->assertDontSee('Page Unassigned');

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-at-risk-row'));
        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-participant-row'));

        $secondRiskPage = $this->actingAs($admin)->get(route('coach.reports', [...$filters, 'risk_page' => 2]))
            ->assertOk()
            ->assertSee('>Page Person 26</th>', false)
            ->assertSee(e(route('coach.reports', [...$filters, 'risk_page' => 2, 'page' => 2])), false);

        $this->assertSame(1, substr_count($secondRiskPage->getContent(), 'data-at-risk-row'));
        $this->assertSame(25, substr_count($secondRiskPage->getContent(), 'data-participant-row'));
    }

    public function test_an_old_path_filter_is_ignored_by_the_report_and_its_exports(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $session = TrainingSession::factory()->create();
        $this->mentor($session, $coach, User::factory()->create(['name' => 'Mia Manual']), User::factory()->create(['name' => 'Abe Automation']));

        $this->actingAs($coach)->get(route('coach.reports', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('>Mia Manual</strong>', false)
            ->assertSee('>Abe Automation</strong>', false);

        $csv = $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'progress', 'path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->streamedContent();
        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));

        $this->assertSame(['Name', 'Employee ID', 'Training session', 'Email', 'Submissions', 'Gate evaluations'], $rows[0]);
        $this->assertEqualsCanonicalizing(['Mia Manual', 'Abe Automation'], array_column(array_slice($rows, 1), 0));

        foreach (['reviews', 'gates', 'risk'] as $type) {
            $this->actingAs($coach)->get(route('coach.reports.export', ['type' => $type, 'path' => 'automation']))
                ->assertOk()
                ->assertSessionHasNoErrors();
        }
    }

    public function test_a_coach_with_no_sessions_sees_both_empty_notices(): void
    {
        $this->mentorScopeFixture();

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Total Participants', '>0</dd>', 'At Risk', '>0</dd>'], false)
            ->assertSee('No current at-risk participants.')
            ->assertSee('0 participant(s)')
            ->assertSee('No participants match the selected filters.');

        $this->assertStringNotContainsString('<table', $response->getContent());
    }

    public function test_report_loads_only_the_at_risk_work_and_counts_the_rest(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $secondGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($activity->week)->create();
        $risky = User::factory()->create();
        $steady = User::factory()->create();
        $this->mentor($session, $coach, $risky, $steady);
        ActivitySubmission::factory()->for($activity)->for($risky, 'participant')->create(['training_session_id' => $session->id, 'status' => 'needs_revision']);
        ActivitySubmission::factory()->count(2)->for($activity)->for($risky, 'participant')->sequence(['attempt_number' => 2], ['attempt_number' => 3])->create(['training_session_id' => $session->id, 'status' => 'approved']);
        GateEvaluation::factory()->for($gate, 'gate')->for($risky, 'participant')->for($session)->create(['status' => 'failed']);
        GateEvaluation::factory()->for($secondGate, 'gate')->for($risky, 'participant')->for($session)->create(['status' => 'passed']);
        ActivitySubmission::factory()->count(2)->for($activity)->for($steady, 'participant')->sequence(['attempt_number' => 1], ['attempt_number' => 2])->create(['training_session_id' => $session->id, 'status' => 'approved']);
        GateEvaluation::factory()->for($gate, 'gate')->for($steady, 'participant')->for($session)->create(['status' => 'passed']);

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Submissions', '>5</dd>', 'Gates Passed', '>2</dd>', 'At Risk', '>1</dd>'], false));

        $this->assertSame(1, $retrievedModels[ActivitySubmission::class]);
        $this->assertSame(1, $retrievedModels[GateEvaluation::class]);
        $this->assertArrayNotHasKey(SubmissionVersion::class, $retrievedModels);
        $this->assertArrayNotHasKey(AttendanceSession::class, $retrievedModels);
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->reportQueryCount(1), $this->reportQueryCount(3));
    }

    /**
     * An activity and a Gate of one week, and two sessions of its program. In Session X an admin assigned Ana and then
     * Ben as Pat's mentor, and Dan mentors Quinn; in Session Y Cy mentors Pat. Pat needs revision on the activity in X
     * and failed the Gate in Y; Quinn's attempt in X is approved, and his failed attempt in Y stays there although he is
     * no longer in Y.
     *
     * @return array{sessionX: TrainingSession, sessionY: TrainingSession, ana: User, ben: User, cy: User, dan: User}
     */
    private function mentorScopeFixture(): array
    {
        $activity = Activity::factory()->create();
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $sessionX = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session Y']);
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        [$ana, $ben, $cy, $dan] = User::factory()->count(4)->create(['role' => 'coach'])->all();
        $this->mentor($sessionX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($sessionX, $ben, $pat);
        $this->mentor($sessionX, $dan, $quinn);
        $this->mentor($sessionY, $cy, $pat);
        ActivitySubmission::factory()->for($activity)->for($pat, 'participant')->create(['training_session_id' => $sessionX->id, 'status' => 'needs_revision']);
        ActivitySubmission::factory()->for($activity)->for($quinn, 'participant')->create(['training_session_id' => $sessionX->id, 'status' => 'approved']);
        ActivitySubmission::factory()->for($activity)->for($quinn, 'participant')->create(['training_session_id' => $sessionY->id, 'status' => 'failed']);
        GateEvaluation::factory()->for($gate, 'gate')->for($pat, 'participant')->for($sessionY)->create(['status' => 'failed']);

        return ['sessionX' => $sessionX, 'sessionY' => $sessionY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan];
    }

    /**
     * Add the coach to the session's mentors and the participants to the session, with the coach as their current
     * mentor there.
     */
    private function mentor(TrainingSession $session, User $coach, User ...$participants): void
    {
        $session->mentors()->syncWithoutDetaching([$coach->id]);
        foreach ($participants as $participant) {
            $session->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
            MentorAssignment::factory()->create(['training_session_id' => $session->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * The training session ids the report's session filter offers, in order.
     *
     * @return list<int>
     */
    private function sessionOptionIds(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('/<select id="report-session".*?<\/select>/s', $response->getContent(), $select));
        preg_match_all('/<option value="(\d+)"/', $select[0], $ids);

        return array_map(intval(...), $ids[1]);
    }

    /**
     * Create the given number of participants in a new session, each mentored there by a new coach with a submission
     * needing revision and a failed and a passed Gate evaluation, and count the queries of that coach's report request
     * after a warm-up request.
     */
    private function reportQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();
        $failedGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $passedGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $session = TrainingSession::factory()->forWeek($activity->week)->create();
        $participants = User::factory()->count($participantCount)->create();
        $this->mentor($session, $coach, ...$participants->all());

        foreach ($participants as $participant) {
            ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['training_session_id' => $session->id, 'status' => 'needs_revision']);
            GateEvaluation::factory()->for($failedGate, 'gate')->for($participant, 'participant')->for($session)->create(['status' => 'failed']);
            GateEvaluation::factory()->for($passedGate, 'gate')->for($participant, 'participant')->for($session)->create(['status' => 'passed']);
        }

        $this->actingAs($coach)->get(route('coach.reports'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($coach)->get(route('coach.reports'))->assertOk()->assertSee('Needs revision');
        DB::disableQueryLog();
        $this->assertSame($participantCount, substr_count($response->getContent(), 'data-participant-row'));

        return count(DB::getQueryLog());
    }

    /**
     * Count the models of each class retrieved from the database while the callback runs.
     *
     * @return array<class-string, int>
     */
    private function retrievedModelCounts(Closure $callback): array
    {
        $counts = [];
        Event::listen('eloquent.retrieved: *', function (string $event) use (&$counts): void {
            $model = Str::after($event, 'eloquent.retrieved: ');
            $counts[$model] = ($counts[$model] ?? 0) + 1;
        });

        $callback();
        Event::forget('eloquent.retrieved: *');

        return $counts;
    }
}
