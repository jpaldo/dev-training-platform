<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\DailyLog;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        $ada = User::factory()->create(['name' => 'Ada Alpha', 'email' => 'ada@example.test', 'training_path' => 'automation_manual']);
        $ada->assignedCoaches()->attach([$admin->id, $coach->id]);
        foreach (['2026-09-10', '2026-09-11'] as $date) {
            DailyLog::factory()->for($ada, 'participant')->for($activity)->create(['logged_on' => $date]);
        }

        $response = $this->actingAs($coach)->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH / REPORTS',
                'href="'.e(route('coach.reports')).'"', 'Back to Reports',
                'Missing submissions',
                'href="'.e(route('coach.reports.missing-submissions.export')).'"', '>Export Missing Submissions CSV</a>',
                'aria-label="Reports"',
                '>Progress</a>', '>Daily Log Completion</a>', '>Daily Log Details</a>', '>Missing Submissions</a>',
                '<option value="">All participants</option>', '<option value="'.$ada->id.'"', '>Ada Alpha</option>',
                '<option value="">All mentors</option>',
                '<option value="'.$admin->id.'"', '>Admin Blake</option>', '<option value="'.$coach->id.'"', '>Coach Avery</option>',
                'Participants With Missing Requirements', '>1</dd>',
                'Missing Activities', '>1</dd>',
                'Missing Daily Logs', '>2</dd>',
                'Missing General Training Logs', '>1</dd>',
                'Missing Video Recordings', '>1</dd>',
                'Actionable missing requirements', '4 row(s)',
                'href="'.e(route('coach.participants.show', $ada)).'"', '>Ada Alpha</a>', '>ada@example.test</span>', '>Automation / Manual</span>',
                '>Admin Blake, Coach Avery</td>', '>General Training</td>', '>General Training</td>', '<strong>General Training Daily Logs</strong>',
                '>5</td>', '>0</td>', '>5</td>', '>—</td>', '>Not Submitted</span>', '>—</td>',
                '>Ada Alpha</a>', '>Daily Logs</td>', '>Week 2</td>', '<strong>Week 2 Daily Logs</strong>',
                '>5</td>', '>2</td>', '>3</td>', '>—</td>', '>Partially Submitted</span>', '>Sep 11, 2026</td>',
                '>Ada Alpha</a>', '>Video Recording</td>', '>Week 2</td>', '<strong>Weekly Video Recording</strong>',
                '>1</td>', '>0</td>', '>1</td>', '>0</td>', '>Not Uploaded</span>', '>—</td>',
                '>Ada Alpha</a>', '>Activities</td>', '>Week 2</td>', '<strong>Explore checkout</strong>',
                '>1</td>', '>0</td>', '>1</td>', '>0</td>', '>Not Submitted</span>', '>—</td>',
            ], false)
            ->assertDontSee('No missing submissions found for the selected filters.');

        $html = $response->getContent();
        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $html, $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Missing Submissions<\/a>/', $reportNav[1]);
        $this->assertSame(4, substr_count($html, 'data-missing-row'));
        $this->assertStringNotContainsString('Coach Avery', $this->selectMarkup($html, 'missing-participant'));
        $this->assertMatchesRegularExpression('/border-amber-500[^"]*">Partially Submitted<\/span>/', $html);
        $this->assertMatchesRegularExpression('/border-danger[^"]*">Not Uploaded<\/span>/', $html);
        $this->assertSame(2, preg_match_all('/border-danger[^"]*">Not Submitted<\/span>/', $html));
    }

    public function test_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        Program::factory()->create();
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
            'category' => 'general_training',
            'week' => 'general',
            'status' => 'not_submitted',
            'requirement' => 'General',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ];

        $firstPage = $this->actingAs($mentor)->get(route('coach.reports.missing-submissions', $filters))
            ->assertOk()
            ->assertSee('<option value="automation" selected>Automation</option>', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('<option value="general_training" selected>General Training</option>', false)
            ->assertSee('<option value="general" selected>General Training</option>', false)
            ->assertSee('<option value="not_submitted" selected>Not Submitted</option>', false)
            ->assertSee('value="General"', false)
            ->assertSee('value="2026-09-01"', false)
            ->assertSee('value="2026-09-30"', false)
            ->assertSee('href="'.e(route('coach.reports.missing-submissions.export', $filters)).'"', false)
            ->assertSeeInOrder(['Participants With Missing Requirements', '>26</dd>', 'Missing General Training Logs', '>26</dd>'], false)
            ->assertSee('26 row(s)')
            ->assertSee(e(route('coach.reports.missing-submissions', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Page Manual</a>', false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-missing-row'));

        $secondPage = $this->actingAs($mentor)->get(route('coach.reports.missing-submissions', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertSee('>Page Person 26</a>', false);

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-missing-row'));
    }

    public function test_rows_without_a_configured_expectation_are_listed_but_not_counted(): void
    {
        config(['training.general_training_daily_logs_expected' => null]);
        Program::factory()->create();
        $ada = User::factory()->create(['name' => 'Ada Alpha']);
        DailyLog::factory()->for($ada, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-12']);
        User::factory()->create(['name' => 'Bo Beta']);

        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports.missing-submissions', ['participant' => $ada->id]))
            ->assertOk()
            ->assertSee('<option value="'.$ada->id.'" selected>Ada Alpha</option>', false)
            ->assertSeeInOrder([
                'Participants With Missing Requirements', '>0</dd>',
                'Missing Activities', '>0</dd>',
                'Missing Daily Logs', '>0</dd>',
                'Missing General Training Logs', '>0</dd>',
                'Missing Video Recordings', '>0</dd>',
                '1 row(s)',
                '>Ada Alpha</a>', '<strong>General Training Daily Logs</strong>',
                '>—</td>', '>1</td>', '>—</td>', '>—</td>', '>Expectation Not Configured</span>', '>Sep 12, 2026</td>',
            ], false)
            ->assertDontSee('Bo Beta</a>', false);

        $this->assertMatchesRegularExpression('/border-danger[^"]*">Expectation Not Configured<\/span>/', $response->getContent());
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

    /**
     * The markup of the select with the given id, options included.
     */
    private function selectMarkup(string $html, string $selectId): string
    {
        $this->assertSame(1, preg_match('/<select id="'.$selectId.'".*?<\/select>/s', $html, $select));

        return $select[0];
    }
}
