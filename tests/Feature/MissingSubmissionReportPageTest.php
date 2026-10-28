<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MissingSubmissionReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_mentor_sees_their_mentees_missing_requirements_with_the_summary_counts(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        $ada = User::factory()->create(['name' => 'Ada Alpha', 'email' => 'ada@example.test']);
        User::factory()->create(['name' => 'Una Unassigned']);
        $program = $this->enrollParticipants($week->program);
        $this->mentor($program, $coach, $ada);

        $response = $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR / REPORTS',
                'href="'.e(route('coach.reports')).'"', 'Back to Reports',
                'Missing submissions',
                'href="'.e(route('coach.reports.missing-submissions.export')).'"', '>Export Missing Submissions CSV</a>',
                'aria-label="Reports"',
                '>Progress</a>', '>Missing Submissions</a>',
                '<option value="">All participants</option>', '<option value="'.$ada->id.'"', '>Ada Alpha</option>',
                '<option value="">All curricula</option>', '<option value="'.$program->id.'" >'.e($this->optionLabel($program)).'</option>',
                'Participants With Missing Requirements', '>1</dd>',
                'Missing Activities', '>1</dd>',
                'Missing Video Recordings', '>1</dd>',
                'Actionable missing requirements', '2 row(s)',
                '>Curriculum</th>', '>Mentor</th>',
                'href="'.e(route('coach.participants.show', [$program, $ada])).'"', '>Ada Alpha</a>', '>ada@example.test</span>',
                '>'.e($program->name).'</span>', '>'.$program->dateRangeLabel().'</span>',
                '>Coach Avery</td>', '>Video Recording</td>', '>Week 2</td>', '<strong>Weekly Video Recording</strong>',
                '>1</td>', '>0</td>', '>1</td>', '>0</td>', '>Not Submitted</span>',
                '>Ada Alpha</a>', '>Activities</td>', '>Week 2</td>', '<strong>Explore checkout</strong>',
                '>1</td>', '>0</td>', '>1</td>', '>0</td>', '>Not Submitted</span>',
            ], false)
            ->assertDontSee('id="missing-mentor"', false)
            ->assertDontSee('Una Unassigned')
            ->assertDontSee('No missing submissions found for the selected filters.')
            ->assertDontSee('QA Path')
            ->assertDontSee('Automation / Manual')
            ->assertDontSee('Daily Log')
            ->assertDontSee('General Training')
            ->assertDontSee('Log Date');

        $html = $response->getContent();
        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $html, $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Missing Submissions<\/a>/', $reportNav[1]);
        $this->assertSame(2, substr_count($html, 'data-missing-row'));
        $this->assertStringNotContainsString('Coach Avery', $this->selectMarkup($html, 'missing-participant'));
        $this->assertSame(2, preg_match_all('/border-danger[^"]*">Not Submitted<\/span>/', $html));
        $this->assertStringNotContainsString('Not Uploaded', $html);
    }

    public function test_each_mentor_sees_only_their_current_mentees_rows_checked_against_the_work_in_that_curriculum(): void
    {
        ['programX' => $programX, 'programY' => $programY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy] = $this->mentorScopeFixture();

        $this->assertSame(
            [['Pat Participant', 'Curriculum X', 'Kickoff deliverable'], ['Pat Participant', 'Curriculum X', 'Second deliverable']],
            $this->listedRows($this->actingAs($ben)->get(route('coach.reports.missing-submissions'))),
        );
        $this->assertSame(
            [['Pat Participant', 'Curriculum Y', 'Second deliverable']],
            $this->listedRows($this->actingAs($cy)->get(route('coach.reports.missing-submissions'))),
        );
        $this->actingAs($ana)->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSee('No missing submissions found for the selected filters.')
            ->assertDontSee('Pat Participant</option>', false);

        $this->assertSame([$programX->id], $this->programOptionIds($this->actingAs($ben)->get(route('coach.reports.missing-submissions'))));
        $this->assertSame([$programY->id], $this->programOptionIds($this->actingAs($cy)->get(route('coach.reports.missing-submissions'))));

        $rows = array_map(str_getcsv(...), explode("\n", trim($this->actingAs($ben)->get(route('coach.reports.missing-submissions.export'))->assertOk()->streamedContent())));
        $this->assertSame(['Participant', 'Employee ID', 'Curriculum', 'Email', 'Mentor'], array_slice($rows[0], 0, 5));
        $this->assertSame(
            [['Pat Participant', $programX->name, $ben->name, 'Kickoff deliverable'], ['Pat Participant', $programX->name, $ben->name, 'Second deliverable']],
            array_map(fn (array $row): array => [$row[0], $row[2], $row[4], $row[7]], array_slice($rows, 1)),
        );
    }

    public function test_an_admin_sees_every_membership_and_filters_by_curriculum_and_mentor(): void
    {
        ['programY' => $programY, 'dan' => $dan] = $this->mentorScopeFixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertSame(
            [
                ['Pat Participant', 'Curriculum X', 'Kickoff deliverable'],
                ['Pat Participant', 'Curriculum X', 'Second deliverable'],
                ['Pat Participant', 'Curriculum Y', 'Second deliverable'],
                ['Quinn Other', 'Curriculum X', 'Kickoff deliverable'],
                ['Quinn Other', 'Curriculum X', 'Second deliverable'],
            ],
            $this->listedRows($this->actingAs($admin)->get(route('coach.reports.missing-submissions'))),
        );
        $this->assertSame(
            [['Pat Participant', 'Curriculum Y', 'Second deliverable']],
            $this->listedRows($this->actingAs($admin)->get(route('coach.reports.missing-submissions', ['program' => $programY->id]))->assertSee('<option value="'.$programY->id.'" selected>', false)),
        );
        $this->assertSame(
            [['Quinn Other', 'Curriculum X', 'Kickoff deliverable'], ['Quinn Other', 'Curriculum X', 'Second deliverable']],
            $this->listedRows($this->actingAs($admin)->get(route('coach.reports.missing-submissions', ['mentor' => $dan->id]))->assertSee('<option value="'.$dan->id.'" selected>', false)),
        );

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions', ['program' => 999, 'mentor' => $admin->id]))
            ->assertSessionHasErrors(['program', 'mentor']);
    }

    public function test_a_missing_weekly_video_is_not_submitted_in_the_csv_and_the_status_filter_and_a_chosen_one_is_not_listed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        User::factory()->create(['name' => 'Ada Alpha', 'employee_id' => 'FS-0001', 'email' => 'ada@example.test']);
        $chooser = User::factory()->create(['name' => 'Ben Beta']);
        WeeklyVideoRecording::factory()->for($chooser, 'participant')->for($week, 'week')->create();
        $program = $this->enrollParticipants($week->program);

        $csv = $this->actingAs($admin)->get(route('coach.reports.missing-submissions.export', ['status' => 'not_submitted']))
            ->assertOk()
            ->streamedContent();
        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));

        $this->assertSame(['Participant', 'Employee ID', 'Curriculum', 'Email', 'Mentor', 'Category', 'Week', 'Requirement', 'Expected', 'Submitted', 'Missing', 'Evidences', 'Status'], $rows[0]);
        $this->assertSame([
            ['Ada Alpha', 'FS-0001', $program->name, 'ada@example.test', 'Not assigned', 'Video Recording', 'Week 2', 'Weekly Video Recording', '1', '0', '1', '0', 'Not Submitted'],
        ], array_slice($rows, 1));
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create();
        User::factory()->create(['name' => 'Page Unassigned']);
        $program = $this->enrollParticipants($week->program);
        $this->mentor($program, $mentor, ...$participants->all());
        $admin = User::factory()->create(['role' => 'admin']);
        $filters = [
            'program' => $program->id,
            'mentor' => $mentor->id,
            'category' => 'activities',
            'week' => 2,
            'status' => 'not_submitted',
            'requirement' => 'Explore',
        ];

        $firstPage = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', $filters))
            ->assertOk()
            ->assertDontSee('name="path"', false)
            ->assertSee('<option value="'.$program->id.'" selected>', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('<option value="activities" selected>Activities</option>', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="not_submitted" selected>Not Submitted</option>', false)
            ->assertSee('value="Explore"', false)
            ->assertSee('href="'.e(route('coach.reports.missing-submissions.export', $filters)).'"', false)
            ->assertSeeInOrder(['Participants With Missing Requirements', '>26</dd>', 'Missing Activities', '>26</dd>', 'Missing Video Recordings', '>0</dd>'], false)
            ->assertSee('26 row(s)')
            ->assertSee(e(route('coach.reports.missing-submissions', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Page Unassigned</a>', false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-missing-row'));

        $secondPage = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertSee('>Page Person 26</a>', false);

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-missing-row'));
    }

    public function test_an_old_path_filter_is_ignored_by_the_report_and_its_export(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        User::factory()->create(['name' => 'Mia Manual']);
        User::factory()->create(['name' => 'Abe Automation']);
        $this->enrollParticipants($week->program);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee('>Mia Manual</a>', false)
            ->assertSee('>Abe Automation</a>', false);

        $csv = $this->actingAs($admin)->get(route('coach.reports.missing-submissions.export', ['path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->streamedContent();
        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));

        $this->assertSame(['Participant', 'Employee ID', 'Curriculum', 'Email', 'Mentor', 'Category', 'Week', 'Requirement', 'Expected', 'Submitted', 'Missing', 'Evidences', 'Status'], $rows[0]);
        $this->assertEqualsCanonicalizing(['Mia Manual', 'Abe Automation'], array_column(array_slice($rows, 1), 0));
    }

    public function test_week_one_requirements_are_listed_and_the_week_filter_offers_every_week_of_the_program(): void
    {
        $program = Program::factory()->create();
        foreach (range(1, 4) as $weekNumber) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
            Activity::factory()->for($week, 'week')->create(['title' => "Week {$weekNumber} deliverable"]);
            GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        }
        User::factory()->create(['name' => 'Wendy WeekOne']);
        $this->enrollParticipants($program);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports.missing-submissions', ['week' => 1]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSeeInOrder([
                '<option value="1" selected>Week 1</option>', '<option value="2" >Week 2</option>', '<option value="3" >Week 3</option>', '<option value="4" >Week 4</option>',
                'Missing Activities', '>1</dd>', 'Missing Video Recordings', '>1</dd>', '2 row(s)',
                '>Wendy WeekOne</a>', '>Video Recording</td>', '>Week 1</td>', '<strong>Weekly Video Recording</strong>',
                '>Wendy WeekOne</a>', '>Activities</td>', '>Week 1</td>', '<strong>Week 1 deliverable</strong>',
            ], false);

        $this->assertSame(2, substr_count($response->getContent(), 'data-missing-row'));
    }

    public function test_a_week_without_an_active_weekly_video_criterion_has_no_missing_video_row(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week')->state(['is_active' => false]), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        User::factory()->create();
        $this->enrollParticipants($week->program);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder(['Missing Activities', '>1</dd>', 'Missing Video Recordings', '>0</dd>', '1 row(s)'], false)
            ->assertSee('<strong>Explore checkout</strong>', false)
            ->assertDontSee('<strong>Weekly Video Recording</strong>', false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-missing-row'));
    }

    public function test_participants_with_missing_requirements_are_counted_by_id_not_by_name(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create();
        User::factory()->count(2)->create(['name' => 'Sam Same']);
        $this->enrollParticipants($week->program);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder(['Participants With Missing Requirements', '>2</dd>'], false);
    }

    public function test_the_removed_daily_log_filter_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports.missing-submissions', ['category' => 'daily_logs', 'week' => 'general', 'status' => 'partial']))
            ->assertSessionHasErrors(['category', 'week', 'status']);
    }

    public function test_every_activity_is_missing_for_a_participant_without_submissions(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Shared activity', 'position' => 1]);
        $manualActivity = Activity::factory()->for($week, 'week')->create(['title' => 'Manual activity', 'position' => 2]);
        ActivityRequirement::factory()->for($manualActivity)->create();
        $automationActivity = Activity::factory()->for($week, 'week')->create(['title' => 'Automation activity', 'position' => 3]);
        ActivityRequirement::factory()->for($automationActivity)->create();
        $participant = User::factory()->create();
        $this->enrollParticipants($week->program);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.reports.missing-submissions', ['participant' => $participant->id, 'category' => 'activities']))
            ->assertOk()
            ->getContent();

        preg_match_all('/<strong>([^<]+ activity)<\/strong>/', $html, $titles);
        $this->assertSame(['Automation activity', 'Manual activity', 'Shared activity'], $titles[1]);
    }

    public function test_a_coach_with_no_curricula_sees_the_notice_instead_of_the_table(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create();
        User::factory()->create();
        $this->enrollParticipants($week->program);

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder(['Participants With Missing Requirements', '>0</dd>', 'Missing Video Recordings', '>0</dd>', '0 row(s)'], false)
            ->assertSee('No missing submissions found for the selected filters.');

        $this->assertStringNotContainsString('<table', $response->getContent());
        $this->assertStringNotContainsString('Pagination Navigation', $response->getContent());
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants(): void
    {
        $this->assertSame($this->reportQueryCount(1), $this->reportQueryCount(3));
    }

    public function test_rows_follow_each_participants_own_curriculum_and_a_participant_in_no_curriculum_has_none(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        User::factory()->create(['name' => 'Ivy Incurriculum']);
        $this->enrollParticipants($week->program);
        $otherWeek = TrainingWeek::factory()->for(Program::factory()->create(['is_active' => false]))->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($otherWeek, 'week')->create(['title' => 'Legacy task']);
        $oscar = User::factory()->create(['name' => 'Oscar Otherprogram']);
        $otherWeek->program->participants()->attach($oscar, ['joined_at' => now()]);
        User::factory()->create(['name' => 'Nora Nocurriculum']);

        $this->assertSame(
            [['Ivy Incurriculum', 'Explore checkout'], ['Oscar Otherprogram', 'Legacy task']],
            array_map(fn (array $row): array => [$row[0], $row[2]], $this->listedRows($this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.reports.missing-submissions')))),
        );
    }

    /**
     * Add every participant to the curriculum and return it. The factory weeks open today, so every week is one the
     * participants can open (the report lists only those).
     */
    private function enrollParticipants(Program $program): Program
    {
        $program->participants()->syncWithoutDetaching(
            User::query()->where('role', UserRole::Participant)->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['joined_at' => now()]])->all(),
        );

        return $program;
    }

    /**
     * Two curricula, each with a week of two active activities ("Kickoff deliverable", "Second deliverable"). In
     * Curriculum X an admin assigned Ana and then Ben as Pat's mentor, and Dan mentors Quinn; in Curriculum Y Cy mentors
     * Pat, who submitted Y's kickoff deliverable.
     *
     * @return array{programX: Program, programY: Program, ana: User, ben: User, cy: User, dan: User}
     */
    private function mentorScopeFixture(): array
    {
        $programX = Program::factory()->create(['name' => 'Curriculum X']);
        $programY = Program::factory()->create(['name' => 'Curriculum Y']);
        $this->deliverableWeek($programX);
        $kickoffInY = $this->deliverableWeek($programY);
        $pat = User::factory()->create(['name' => 'Pat Participant']);
        $quinn = User::factory()->create(['name' => 'Quinn Other']);
        [$ana, $ben, $cy, $dan] = User::factory()->count(4)->create(['role' => 'coach'])->all();
        $this->mentor($programX, $ana, $pat);
        MentorAssignment::query()->where('mentor_id', $ana->id)->update(['ended_at' => now()]);
        $this->mentor($programX, $ben, $pat);
        $this->mentor($programX, $dan, $quinn);
        $this->mentor($programY, $cy, $pat);
        $submission = ActivitySubmission::factory()->for($kickoffInY)->for($pat, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        return ['programX' => $programX, 'programY' => $programY, 'ana' => $ana, 'ben' => $ben, 'cy' => $cy, 'dan' => $dan];
    }

    /**
     * Give the curriculum a Week 2 with the activities "Kickoff deliverable" and "Second deliverable", and return the
     * kickoff one.
     */
    private function deliverableWeek(Program $program): Activity
    {
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1]);
        $kickoff = Activity::factory()->for($week, 'week')->create(['title' => 'Kickoff deliverable']);
        Activity::factory()->for($week, 'week')->create(['title' => 'Second deliverable']);

        return $kickoff;
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
     * The participant name, curriculum name and requirement of each listed row, in order.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function listedRows(TestResponse $response): array
    {
        preg_match_all('/<tr data-missing-row>.*?>([^<]+)<\/a><\/strong>.*?<span class="wrap-break-word">([^<]+)<\/span>.*?<strong>([^<]+)<\/strong>/s', $response->assertOk()->getContent(), $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [html_entity_decode($match[1]), html_entity_decode($match[2]), html_entity_decode($match[3])], $matches);
    }

    /**
     * The curriculum ids the report's curriculum filter offers, in order.
     *
     * @return list<int>
     */
    private function programOptionIds(TestResponse $response): array
    {
        preg_match_all('/<option value="(\d+)"/', $this->selectMarkup($response->getContent(), 'missing-program'), $ids);

        return array_map(intval(...), $ids[1]);
    }

    /**
     * The curriculum filter's option label: "Name (dates)".
     */
    private function optionLabel(Program $program): string
    {
        return $program->name.' ('.$program->dateRangeLabel().')';
    }

    /**
     * The markup of the select with the given id, options included.
     */
    private function selectMarkup(string $html, string $selectId): string
    {
        $this->assertSame(1, preg_match('/<select id="'.$selectId.'".*?<\/select>/s', $html, $select));

        return $select[0];
    }

    /**
     * Build a new active curriculum with Weeks 2 and 3 (an activity and a Gate with a weekly video criterion each) and
     * the given number of new participants in it, mentored there by a new coach, and count the queries of that
     * coach's report request after a warm-up request.
     */
    private function reportQueryCount(int $participantCount): int
    {
        Program::query()->update(['is_active' => false]);
        $program = Program::factory()->create();

        foreach ([2 => 1, 3 => 2] as $weekNumber => $position) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $position]);
            Activity::factory()->for($week, 'week')->create();
            GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        }

        $participants = User::factory()->count($participantCount)->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $this->mentor($this->enrollParticipants($program), $coach, ...$participants->all());

        $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))->assertOk()->assertSee('Weekly Video Recording');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
