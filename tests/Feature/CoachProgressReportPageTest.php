<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklySessionAttendance;
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
        $program = Program::factory()->create(['name' => 'Avery Curriculum']);
        $activity = $this->activityIn($program);
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();

        $risky = User::factory()->create(['name' => 'Riley Risk', 'email' => 'riley@example.test']);
        $this->mentor($program, $coach, $risky);
        $revision = ActivitySubmission::factory()->for($activity)->for($risky, 'participant')->create(['status' => 'needs_revision']);
        GateEvaluation::factory()->for($gate, 'gate')->for($risky, 'participant')->create(['status' => 'failed']);
        $secondGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        GateEvaluation::factory()->for($secondGate, 'gate')->for($risky, 'participant')->create(['status' => 'passed']);

        $steady = User::factory()->create(['name' => 'Sam Steady', 'email' => 'sam@example.test', 'is_active' => false]);
        $this->mentor($program, $coach, $steady);
        ActivitySubmission::factory()->for($activity)->for($steady, 'participant')->create(['status' => 'approved']);

        $unassigned = User::factory()->create(['name' => 'Una Unassigned']);
        $program->participants()->attach($unassigned->id, ['joined_at' => now()]);
        ActivitySubmission::factory()->for($activity)->for($unassigned, 'participant')->create(['status' => 'failed']);

        $response = $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR / REPORTS',
                'Progress report',
                'href="'.e(route('coach.reports.export', ['type' => 'progress'])).'"', '>Export Progress CSV</a>',
                'aria-label="Reports"',
                'href="'.route('coach.reports').'"', 'aria-current="page"', '>Progress</a>',
                'href="'.route('coach.reports.missing-submissions').'"', '>Missing Submissions</a>',
                '<label for="report-program"', 'Program</label>',
                '<option value="">All programs</option>', '<option value="'.$program->id.'" >'.e($program->name.' ('.$program->dateRangeLabel().')').'</option>',
                'Total Participants', '>2</dd>',
                'Active Participants', '>1</dd>',
                'Submissions', '>2</dd>',
                'Gates Passed', '>1</dd>',
                'At Risk', '>1</dd>',
                'At-risk participants',
                '>Program</th>',
                '>Riley Risk</th>', '>Avery Curriculum</span>', '>'.$program->dateRangeLabel().'</span>',
                '>'.e($revision->activity->title).': Needs revision · '.e($gate->title).': Failed</td>',
                'Participant progress',
                '2 participant(s)',
                '>Program</th>', '>Mentor</th>',
                '>Riley Risk</strong>', '>riley@example.test</span>', '>Avery Curriculum</span>',
                '>Coach Avery</td>',
                '>1</td>', '>1</td>',
                '>Sam Steady</strong>', '>sam@example.test</span>', '>Avery Curriculum</span>',
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

    public function test_each_mentor_sees_only_their_current_mentees_work_in_the_curriculum_where_they_mentor_them(): void
    {
        ['programX' => $programX, 'programY' => $programY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy] = $this->mentorScopeFixture();

        $benResponse = $this->actingAs($ben)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'Total Participants', '>1</dd>', 'Submissions', '>1</dd>', 'Gates Passed', '>0</dd>', 'At Risk', '>1</dd>',
                '>Pat Participant</th>', '>Curriculum X</span>', ': Needs revision</td>',
                '1 participant(s)', '>Pat Participant</strong>', '>Curriculum X</span>',
            ], false)
            ->assertDontSee('Quinn Other')
            ->assertDontSee('Curriculum Y</span>', false)
            ->assertDontSee(': Failed</td>', false);
        $this->assertSame(1, substr_count($benResponse->getContent(), 'data-participant-row'));

        $this->actingAs($cy)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder([
                'Total Participants', '>1</dd>', 'Submissions', '>0</dd>', 'Gates Passed', '>0</dd>', 'At Risk', '>1</dd>',
                '>Pat Participant</th>', '>Curriculum Y</span>', ': Failed</td>',
                '1 participant(s)', '>Pat Participant</strong>', '>Curriculum Y</span>',
            ], false)
            ->assertDontSee('Curriculum X</span>', false)
            ->assertDontSee(': Needs revision</td>', false);

        $this->actingAs($ana)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Total Participants', '>0</dd>', 'At Risk', '>0</dd>'], false)
            ->assertSee('No current at-risk participants.')
            ->assertSee('No participants match the selected filters.')
            ->assertDontSee('Pat Participant');

        $this->assertSame([$programX->id], $this->programOptionIds($this->actingAs($ben)->get(route('coach.reports'))));
        $this->assertSame([$programY->id], $this->programOptionIds($this->actingAs($cy)->get(route('coach.reports'))));
    }

    public function test_an_admin_sees_every_membership_and_filters_by_curriculum_and_mentor(): void
    {
        ['programY' => $programY, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan] = $this->mentorScopeFixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $everyRow = $this->actingAs($admin)->get(route('coach.reports'))
            ->assertOk()
            ->assertSee('3 participant(s)')
            ->assertSee('<option value="">All mentors</option>', false)
            ->assertSee('<option value="'.$ben->id.'" >', false)
            ->assertSee('<option value="'.$cy->id.'" >', false);
        $this->assertSame(3, substr_count($everyRow->getContent(), 'data-participant-row'));
        $this->assertSame(2, substr_count($everyRow->getContent(), 'data-at-risk-row'));

        $programYRows = $this->actingAs($admin)->get(route('coach.reports', ['program' => $programY->id]))
            ->assertOk()
            ->assertSee('<option value="'.$programY->id.'" selected>', false)
            ->assertSee('1 participant(s)')
            ->assertSee('>Curriculum Y</span>', false)
            ->assertDontSee('>Curriculum X</span>', false);
        $this->assertSame(1, substr_count($programYRows->getContent(), 'data-participant-row'));

        $danRows = $this->actingAs($admin)->get(route('coach.reports', ['mentor' => $dan->id]))
            ->assertOk()
            ->assertSee('<option value="'.$dan->id.'" selected>', false)
            ->assertSeeInOrder(['Submissions', '>1</dd>', 'At Risk', '>0</dd>'], false)
            ->assertSee('No current at-risk participants.')
            ->assertSee('>Quinn Other</strong>', false)
            ->assertDontSee('>Pat Participant</strong>', false);
        $this->assertSame(1, substr_count($danRows->getContent(), 'data-participant-row'));
    }

    public function test_an_unknown_curriculum_or_a_mentor_who_is_not_a_coach_fails_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.reports', ['program' => 999, 'mentor' => $admin->id]))
            ->assertSessionHasErrors(['program', 'mentor']);
        $this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'progress', 'program' => 999, 'mentor' => $admin->id]))
            ->assertSessionHasErrors(['program', 'mentor']);
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $activity = Activity::factory()->create();
        $program = $activity->week->program;
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create();
        $this->mentor($program, $mentor, ...$participants->all());
        $participants->each(fn (User $participant): ActivitySubmission => ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'failed']));
        $this->mentor($program, $mentor, User::factory()->create(['name' => 'Other Mentee']));
        $program->participants()->attach(User::factory()->create(['name' => 'Page Unassigned'])->id, ['joined_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $filters = ['search' => 'Page', 'program' => $program->id, 'mentor' => $mentor->id];

        $firstPage = $this->actingAs($admin)->get(route('coach.reports', $filters))
            ->assertOk()
            ->assertSee('value="Page"', false)
            ->assertDontSee('name="path"', false)
            ->assertSee('<option value="'.$program->id.'" selected>', false)
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
        $program = Program::factory()->create();
        $this->mentor($program, $coach, User::factory()->create(['name' => 'Mia Manual']), User::factory()->create(['name' => 'Abe Automation']));

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

        $this->assertSame(['Name', 'Employee ID', 'Program', 'Email', 'Submissions', 'Gate evaluations', 'Attendance'], $rows[0]);
        $this->assertEqualsCanonicalizing(['Mia Manual', 'Abe Automation'], array_column(array_slice($rows, 1), 0));

        foreach (['reviews', 'gates', 'risk'] as $type) {
            $this->actingAs($coach)->get(route('coach.reports.export', ['type' => $type, 'path' => 'automation']))
                ->assertOk()
                ->assertSessionHasNoErrors();
        }
    }

    public function test_a_coach_with_no_curricula_sees_both_empty_notices(): void
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
        $risky = User::factory()->create();
        $steady = User::factory()->create();
        $this->mentor($activity->week->program, $coach, $risky, $steady);
        ActivitySubmission::factory()->for($activity)->for($risky, 'participant')->create(['status' => 'needs_revision']);
        ActivitySubmission::factory()->count(2)->for($activity)->for($risky, 'participant')->sequence(['attempt_number' => 2], ['attempt_number' => 3])->create(['status' => 'approved']);
        GateEvaluation::factory()->for($gate, 'gate')->for($risky, 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for($secondGate, 'gate')->for($risky, 'participant')->create(['status' => 'passed']);
        ActivitySubmission::factory()->count(2)->for($activity)->for($steady, 'participant')->sequence(['attempt_number' => 1], ['attempt_number' => 2])->create(['status' => 'approved']);
        GateEvaluation::factory()->for($gate, 'gate')->for($steady, 'participant')->create(['status' => 'passed']);

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($coach)->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Submissions', '>5</dd>', 'Gates Passed', '>2</dd>', 'At Risk', '>1</dd>'], false));

        $this->assertSame(1, $retrievedModels[ActivitySubmission::class]);
        $this->assertSame(1, $retrievedModels[GateEvaluation::class]);
        $this->assertArrayNotHasKey(SubmissionVersion::class, $retrievedModels);
    }

    public function test_participant_progress_shows_the_sessions_attended_of_the_curriculums_active_weeks(): void
    {
        $this->sessionAttendanceFixture();

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports'))
            ->assertOk()
            ->assertSeeInOrder(['>Submissions</th>', '>Gates</th>', '>Attendance</th>'], false);

        $this->assertEqualsCanonicalizing([
            ['Ada Attender', 'Curriculum X', '3/4'],
            ['Ada Attender', 'Curriculum Y', '1/1'],
            ['Bo Absent', 'Curriculum X', '0/4'],
            ['Bo Absent', 'Curriculum Z', '0/0'],
        ], $this->participantAttendanceCells($response));
    }

    public function test_progress_export_prints_the_sessions_attended_of_the_curriculums_active_weeks_after_the_gate_evaluations(): void
    {
        $this->sessionAttendanceFixture();

        $csv = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports.export', ['type' => 'progress']))
            ->assertOk()
            ->streamedContent();
        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));

        $this->assertSame(['Name', 'Employee ID', 'Program', 'Email', 'Submissions', 'Gate evaluations', 'Attendance'], $rows[0]);
        $this->assertEqualsCanonicalizing([
            ['Ada Attender', 'Curriculum X', '3/4'],
            ['Ada Attender', 'Curriculum Y', '1/1'],
            ['Bo Absent', 'Curriculum X', '0/4'],
            ['Bo Absent', 'Curriculum Z', '0/0'],
        ], array_map(fn (array $row): array => [$row[0], $row[2], $row[6]], array_slice($rows, 1)));
    }

    public function test_participants_cannot_open_the_progress_report_or_its_export(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get(route('coach.reports'))->assertForbidden();
        $this->actingAs($participant)->get(route('coach.reports.export', ['type' => 'progress']))->assertForbidden();
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->reportQueryCount(1), $this->reportQueryCount(3));
    }

    public function test_progress_export_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->progressExportQueryCount(1), $this->progressExportQueryCount(3));
    }

    /**
     * Two curricula, each with an activity and a Gate in one week. In Curriculum X an admin assigned Ana and then Ben
     * as Pat's mentor, and Dan mentors Quinn; in Curriculum Y Cy mentors Pat. Pat needs revision on X's activity and
     * failed Y's Gate; Quinn's attempt at X's activity is approved, and his failed attempt at Y's activity counts
     * nowhere because he is not in Y.
     *
     * @return array{programX: Program, programY: Program, ana: User, ben: User, cy: User, dan: User}
     */
    private function mentorScopeFixture(): array
    {
        $programX = Program::factory()->create(['name' => 'Curriculum X']);
        $programY = Program::factory()->create(['name' => 'Curriculum Y']);
        $activityX = $this->activityIn($programX);
        $activityY = $this->activityIn($programY);
        $gateY = TrainingGate::factory()->for($activityY->week, 'week')->create();
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        [$ana, $ben, $cy, $dan] = User::factory()->count(4)->create(['role' => 'coach'])->all();
        $this->mentor($programX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($programX, $ben, $pat);
        $this->mentor($programX, $dan, $quinn);
        $this->mentor($programY, $cy, $pat);
        ActivitySubmission::factory()->for($activityX)->for($pat, 'participant')->create(['status' => 'needs_revision']);
        ActivitySubmission::factory()->for($activityX)->for($quinn, 'participant')->create(['status' => 'approved']);
        ActivitySubmission::factory()->for($activityY)->for($quinn, 'participant')->create(['status' => 'failed']);
        GateEvaluation::factory()->for($gateY, 'gate')->for($pat, 'participant')->create(['status' => 'failed']);

        return ['programX' => $programX, 'programY' => $programY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan];
    }

    /**
     * Three curricula and two participants. Curriculum X has four active weeks and an inactive one, Curriculum Y one
     * active week and Curriculum Z only an inactive week. Ada (in X and Y) attended three of X's active weeks, X's
     * inactive week and Y's week; Bo (in X and Z) attended nothing in X, Z's inactive week, and Y's week although he is
     * not in Y.
     */
    private function sessionAttendanceFixture(): void
    {
        $programX = Program::factory()->create(['name' => 'Curriculum X']);
        $programY = Program::factory()->create(['name' => 'Curriculum Y']);
        $programZ = Program::factory()->create(['name' => 'Curriculum Z']);
        $activeWeeksX = TrainingWeek::factory()->count(4)->for($programX)->create();
        $inactiveWeekX = TrainingWeek::factory()->for($programX)->create(['is_active' => false]);
        $weekY = TrainingWeek::factory()->for($programY)->create();
        $inactiveWeekZ = TrainingWeek::factory()->for($programZ)->create(['is_active' => false]);
        $ada = User::factory()->create(['name' => 'Ada Attender']);
        $bo = User::factory()->create(['name' => 'Bo Absent']);
        $programX->participants()->attach([$ada->id => ['joined_at' => now()], $bo->id => ['joined_at' => now()]]);
        $programY->participants()->attach($ada->id, ['joined_at' => now()]);
        $programZ->participants()->attach($bo->id, ['joined_at' => now()]);

        foreach ([...$activeWeeksX->take(3)->all(), $inactiveWeekX, $weekY] as $week) {
            WeeklySessionAttendance::factory()->create(['training_week_id' => $week->id, 'user_id' => $ada->id]);
        }

        foreach ([$inactiveWeekZ, $weekY] as $week) {
            WeeklySessionAttendance::factory()->create(['training_week_id' => $week->id, 'user_id' => $bo->id]);
        }
    }

    /**
     * The participant name, curriculum name and Attendance cell of each participant progress row.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function participantAttendanceCells(TestResponse $response): array
    {
        preg_match_all('/<tr data-participant-row>(.*?)<\/tr>/s', $response->getContent(), $rows);

        return array_map(function (string $row): array {
            $this->assertSame(1, preg_match('/<strong[^>]*>(.*?)<\/strong>/s', $row, $name));
            $this->assertSame(1, preg_match('/<td[^>]*>\s*<span class="grid gap-0.5">\s*<span[^>]*>(.*?)<\/span>/s', $row, $program));
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return [$name[1], $program[1], trim(end($cells[1]))];
        }, $rows[1]);
    }

    /**
     * Add the coach to the curriculum's mentors and the participants to the curriculum, with the coach as their current
     * mentor there.
     */
    private function mentor(Program $program, User $coach, User ...$participants): void
    {
        $program->mentors()->syncWithoutDetaching([$coach->id]);
        foreach ($participants as $participant) {
            $program->participants()->syncWithoutDetaching([$participant->id => ['joined_at' => now()]]);
            MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);
        }
    }

    /**
     * A new activity in a new week of the curriculum.
     */
    private function activityIn(Program $program): Activity
    {
        return Activity::factory()->for(TrainingWeek::factory()->for($program), 'week')->create();
    }

    /**
     * The curriculum ids the report's curriculum filter offers, in order.
     *
     * @return list<int>
     */
    private function programOptionIds(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('/<select id="report-program".*?<\/select>/s', $response->getContent(), $select));
        preg_match_all('/<option value="(\d+)"/', $select[0], $ids);

        return array_map(intval(...), $ids[1]);
    }

    /**
     * Create the given number of participants in a new curriculum, each mentored there by a new coach with a submission
     * needing revision and a failed and a passed Gate evaluation, and count the queries of that coach's report request
     * after a warm-up request.
     */
    private function reportQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();
        $failedGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $passedGate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $participants = User::factory()->count($participantCount)->create();
        $this->mentor($activity->week->program, $coach, ...$participants->all());

        foreach ($participants as $participant) {
            ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'needs_revision']);
            GateEvaluation::factory()->for($failedGate, 'gate')->for($participant, 'participant')->create(['status' => 'failed']);
            GateEvaluation::factory()->for($passedGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
            WeeklySessionAttendance::factory()->create(['training_week_id' => $activity->week->id, 'user_id' => $participant->id]);
        }

        $this->actingAs($coach)->get(route('coach.reports'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($coach)->get(route('coach.reports'))->assertOk()->assertSee('Needs revision');
        DB::disableQueryLog();
        $this->assertSame($participantCount, substr_count($response->getContent(), 'data-participant-row'));
        $this->assertSame($participantCount, substr_count($response->getContent(), '>1/1</td>'));

        return count(DB::getQueryLog());
    }

    /**
     * Create the given number of participants in a new curriculum with one active week, each mentored there by a new
     * coach with a submission, a Gate evaluation and an attended session, and count the queries of that coach's progress
     * export after a warm-up request.
     */
    private function progressExportQueryCount(int $participantCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Activity::factory()->create();
        $gate = TrainingGate::factory()->for($activity->week, 'week')->create();
        $participants = User::factory()->count($participantCount)->create();
        $this->mentor($activity->week->program, $coach, ...$participants->all());

        foreach ($participants as $participant) {
            ActivitySubmission::factory()->for($activity)->for($participant, 'participant')->create(['status' => 'approved']);
            GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
            WeeklySessionAttendance::factory()->create(['training_week_id' => $activity->week->id, 'user_id' => $participant->id]);
        }

        $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'progress']))->assertOk()->streamedContent();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $csv = $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'progress']))->assertOk()->streamedContent();
        DB::disableQueryLog();
        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));
        $this->assertSame(array_fill(0, $participantCount, '1/1'), array_column(array_slice($rows, 1), 6));

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
