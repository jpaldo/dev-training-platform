<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MissingSubmissionReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_missing_requirements_with_the_summary_counts(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Blake']);
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => GateCriterion::TYPE_WEEKLY_VIDEO]);
        $ada = User::factory()->create(['name' => 'Ada Alpha', 'email' => 'ada@example.test', 'training_path' => 'automation_manual']);
        $ada->assignedCoaches()->attach([$admin->id, $coach->id]);

        $response = $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH / REPORTS',
                'href="'.e(route('coach.reports')).'"', 'Back to Reports',
                'Missing submissions',
                'href="'.e(route('coach.reports.missing-submissions.export')).'"', '>Export Missing Submissions CSV</a>',
                'aria-label="Reports"',
                '>Progress</a>', '>Missing Submissions</a>',
                '<option value="">All participants</option>', '<option value="'.$ada->id.'"', '>Ada Alpha</option>',
                '<option value="">All mentors</option>',
                '<option value="'.$admin->id.'"', '>Admin Blake</option>', '<option value="'.$coach->id.'"', '>Coach Avery</option>',
                'Participants With Missing Requirements', '>1</dd>',
                'Missing Activities', '>1</dd>',
                'Missing Video Recordings', '>1</dd>',
                'Actionable missing requirements', '2 row(s)',
                'href="'.e(route('coach.participants.show', $ada)).'"', '>Ada Alpha</a>', '>ada@example.test</span>', '>Automation / Manual</span>',
                '>Admin Blake, Coach Avery</td>', '>Video Recording</td>', '>Week 2</td>', '<strong>Weekly Video Recording</strong>',
                '>1</td>', '>0</td>', '>1</td>', '>0</td>', '>Not Uploaded</span>',
                '>Ada Alpha</a>', '>Activities</td>', '>Week 2</td>', '<strong>Explore checkout</strong>',
                '>1</td>', '>0</td>', '>1</td>', '>0</td>', '>Not Submitted</span>',
            ], false)
            ->assertDontSee('No missing submissions found for the selected filters.')
            ->assertDontSee('Daily Log')
            ->assertDontSee('General Training')
            ->assertDontSee('Log Date');

        $html = $response->getContent();
        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $html, $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Missing Submissions<\/a>/', $reportNav[1]);
        $this->assertSame(2, substr_count($html, 'data-missing-row'));
        $this->assertStringNotContainsString('Coach Avery', $this->selectMarkup($html, 'missing-participant'));
        $this->assertMatchesRegularExpression('/border-danger[^"]*">Not Uploaded<\/span>/', $html);
        $this->assertMatchesRegularExpression('/border-danger[^"]*">Not Submitted<\/span>/', $html);
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create(['training_path' => 'automation']);
        $mentor->assignedParticipants()->attach($participants->modelKeys());
        $manual = User::factory()->create(['name' => 'Page Manual', 'training_path' => 'manual']);
        $manual->assignedCoaches()->attach($mentor->id);
        $filters = [
            'path' => 'automation',
            'mentor' => $mentor->id,
            'category' => 'activities',
            'week' => 2,
            'status' => 'not_submitted',
            'requirement' => 'Explore',
        ];

        $firstPage = $this->actingAs($mentor)->get(route('coach.reports.missing-submissions', $filters))
            ->assertOk()
            ->assertSee('<option value="automation" selected>Automation</option>', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('<option value="activities" selected>Activities</option>', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="not_submitted" selected>Not Submitted</option>', false)
            ->assertSee('value="Explore"', false)
            ->assertSee('href="'.e(route('coach.reports.missing-submissions.export', $filters)).'"', false)
            ->assertSeeInOrder(['Participants With Missing Requirements', '>26</dd>', 'Missing Activities', '>26</dd>', 'Missing Video Recordings', '>0</dd>'], false)
            ->assertSee('26 row(s)')
            ->assertSee(e(route('coach.reports.missing-submissions', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Page Manual</a>', false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-missing-row'));

        $secondPage = $this->actingAs($mentor)->get(route('coach.reports.missing-submissions', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertSee('>Page Person 26</a>', false);

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-missing-row'));
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

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.missing-submissions', ['week' => 1]))
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

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.missing-submissions'))
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

        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder(['Participants With Missing Requirements', '>2</dd>'], false);
    }

    public function test_the_removed_daily_log_filter_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports.missing-submissions', ['category' => 'daily_logs', 'week' => 'general', 'status' => 'partial']))
            ->assertSessionHasErrors(['category', 'week', 'status']);
    }

    /**
     * @param  list<string>  $missingActivities
     */
    #[DataProvider('pathActivities')]
    public function test_path_specific_activities_are_missing_only_for_participants_on_one_of_their_paths(TrainingPath $trainingPath, array $missingActivities): void
    {
        $week = TrainingWeek::factory()->for(Program::factory(), 'program')->create(['week_number' => 2, 'position' => 1]);
        Activity::factory()->for($week, 'week')->create(['title' => 'Shared activity', 'position' => 1]);
        $manualActivity = Activity::factory()->for($week, 'week')->create(['title' => 'Manual activity', 'position' => 2]);
        ActivityRequirement::factory()->for($manualActivity)->create(['participant_path' => TrainingPath::Manual]);
        $automationActivity = Activity::factory()->for($week, 'week')->create(['title' => 'Automation activity', 'position' => 3]);
        ActivityRequirement::factory()->for($automationActivity)->create(['participant_path' => TrainingPath::Automation]);
        $participant = User::factory()->create(['training_path' => $trainingPath]);

        $html = $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports.missing-submissions', ['participant' => $participant->id, 'category' => 'activities']))
            ->assertOk()
            ->getContent();

        preg_match_all('/<strong>([^<]+ activity)<\/strong>/', $html, $titles);
        $this->assertSame($missingActivities, $titles[1]);
    }

    /**
     * @return array<string, array{TrainingPath, list<string>}>
     */
    public static function pathActivities(): array
    {
        return [
            'manual' => [TrainingPath::Manual, ['Manual activity', 'Shared activity']],
            'automation' => [TrainingPath::Automation, ['Automation activity', 'Shared activity']],
            'automation / manual' => [TrainingPath::AutomationManual, ['Automation activity', 'Manual activity', 'Shared activity']],
        ];
    }

    public function test_without_missing_requirements_the_notice_replaces_the_table(): void
    {
        Program::factory()->create();

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

    /**
     * The markup of the select with the given id, options included.
     */
    private function selectMarkup(string $html, string $selectId): string
    {
        $this->assertSame(1, preg_match('/<select id="'.$selectId.'".*?<\/select>/s', $html, $select));

        return $select[0];
    }

    /**
     * Build a new active program with Weeks 2 and 3 (an activity and a Gate with a weekly video criterion each) and the given number of new
     * participants, and count the queries of a coach's report request after a warm-up request.
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

        User::factory()->count($participantCount)->create();
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))->assertOk()->assertSee('Weekly Video Recording');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
