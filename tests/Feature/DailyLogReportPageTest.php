<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyLogReportPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_completion_summary_grouped_by_participant_week_and_activity(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Blake']);
        $week = TrainingWeek::factory()->create(['week_number' => 2]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        Activity::factory()->for($week, 'week')->create(['title' => 'Retired drill', 'is_active' => false]);

        $ada = User::factory()->create(['name' => 'Ada Alpha', 'email' => 'ada@example.test', 'training_path' => 'automation_manual']);
        $ada->assignedCoaches()->attach([$admin->id, $coach->id]);
        foreach (['2026-09-12', '2026-09-10', '2026-09-11'] as $date) {
            DailyLog::factory()->for($ada, 'participant')->for($activity)->create(['logged_on' => $date]);
        }
        DailyLog::factory()->for($ada, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-13']);
        $bo = User::factory()->create(['name' => 'Bo Beta', 'email' => 'bo@example.test', 'training_path' => 'manual']);
        DailyLog::factory()->for($bo, 'participant')->for($activity)->create(['logged_on' => '2026-09-10']);
        DailyLog::factory()->for($coach, 'participant')->for($activity)->create(['logged_on' => '2026-09-10']);

        $response = $this->actingAs($coach)->get(route('coach.reports.daily-logs'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH / REPORTS',
                'Daily Log Completion',
                'href="'.e(route('coach.reports.daily-logs.export')).'"', '>Export Daily Logs CSV</a>',
                'aria-label="Reports"',
                '>Progress</a>', '>Daily Log Completion</a>', '>Daily Log Details</a>', '>Missing Submissions</a>',
                '<option value="">All participants</option>',
                '<option value="'.$ada->id.'"', '>Ada Alpha</option>',
                '<option value="'.$bo->id.'"', '>Bo Beta</option>',
                '<option value="">All mentors</option>',
                '<option value="'.$admin->id.'"', '>Admin Blake</option>',
                '<option value="'.$coach->id.'"', '>Coach Avery</option>',
                '<option value="">All activities</option>',
                '<option value="'.$activity->id.'"', '>Explore checkout</option>',
                'Participants With Logs', '>2</dd>',
                'Total Logs', '>5</dd>',
                'Activities Logged', '>3</dd>',
                'Completion summary',
                '3 grouped row(s)',
                '>Ada Alpha</strong>', '>ada@example.test</span>', '>Automation / Manual</span>', '<strong>1</strong>', '>—</td>',
                '>Sep 13, 2026</li>', '>Admin Blake, Coach Avery</td>', '<strong>General Training</strong>',
                '>Ada Alpha</strong>', '>Automation / Manual</span>', '<strong>3</strong>', '>Week 2</td>',
                '>Sep 10, 2026</li>', '>Sep 11, 2026</li>', '>Sep 12, 2026</li>', '>Admin Blake, Coach Avery</td>', '<strong>Explore checkout</strong>',
                '>Bo Beta</strong>', '>bo@example.test</span>', '>Manual</span>', '<strong>1</strong>', '>Week 2</td>',
                '>Sep 10, 2026</li>', '>Not assigned</td>', '<strong>Explore checkout</strong>',
            ], false)
            ->assertDontSee('Retired drill')
            ->assertDontSee('No Daily Logs match the selected filters.');

        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $response->getContent(), $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Daily Log Completion<\/a>/', $reportNav[1]);
        $this->assertSame(3, substr_count($response->getContent(), 'data-completion-row'));
        $this->assertStringNotContainsString('Coach Avery', $this->selectMarkup($response->getContent(), 'daily-participant'));
    }

    public function test_completion_filters_keep_their_values_and_the_export_and_pagination_links_keep_the_query(): void
    {
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 2]), 'week')->create(['title' => 'Explore checkout']);
        $participants = User::factory()->count(26)
            ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Page Person %02d', $sequence->index + 1)])
            ->create(['training_path' => 'automation']);
        $mentor->assignedParticipants()->attach($participants->modelKeys());
        $participants->each(fn (User $participant): DailyLog => DailyLog::factory()->for($participant, 'participant')->for($activity)->create(['logged_on' => '2026-09-10']));
        DailyLog::factory()->for($participants->first(), 'participant')->for($activity)->create(['logged_on' => '2026-10-02']);
        $manual = User::factory()->create(['name' => 'Page Manual', 'training_path' => 'manual']);
        $manual->assignedCoaches()->attach($mentor->id);
        DailyLog::factory()->for($manual, 'participant')->for($activity)->create(['logged_on' => '2026-09-10']);
        $filters = ['path' => 'automation', 'mentor' => $mentor->id, 'week' => 2, 'activity' => $activity->id, 'from' => '2026-09-01', 'to' => '2026-09-30'];

        $firstPage = $this->actingAs($mentor)->get(route('coach.reports.daily-logs', $filters))
            ->assertOk()
            ->assertSee('<option value="automation" selected>Automation</option>', false)
            ->assertSee('<option value="'.$mentor->id.'" selected>Mentor Ely</option>', false)
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('<option value="'.$activity->id.'" selected>Explore checkout</option>', false)
            ->assertSee('value="2026-09-01"', false)
            ->assertSee('value="2026-09-30"', false)
            ->assertSee('href="'.e(route('coach.reports.daily-logs.export', $filters)).'"', false)
            ->assertSeeInOrder(['Participants With Logs', '>26</dd>', 'Total Logs', '>26</dd>', 'Activities Logged', '>26</dd>'], false)
            ->assertSee(e(route('coach.reports.daily-logs', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Page Manual</strong>', false)
            ->assertDontSee('Oct 2, 2026');

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-completion-row'));

        $secondPage = $this->actingAs($mentor)->get(route('coach.reports.daily-logs', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertSee('>Page Person 26</strong>', false);

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-completion-row'));
    }

    public function test_completion_report_without_logs_shows_the_notice_instead_of_the_table(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.daily-logs'))
            ->assertOk()
            ->assertSeeInOrder(['Participants With Logs', '>0</dd>', 'Total Logs', '>0</dd>', 'Activities Logged', '>0</dd>'], false)
            ->assertSee('No Daily Logs match the selected filters.');

        $this->assertStringNotContainsString('<table', $response->getContent());
    }

    public function test_coach_sees_the_detailed_daily_logs_newest_log_date_first(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Avery']);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 3]), 'week')->create(['title' => 'Explore checkout']);
        $ada = User::factory()->create(['name' => 'Ada Alpha', 'email' => 'ada@example.test', 'training_path' => 'automation_manual']);
        $ada->assignedCoaches()->attach($coach->id);
        DailyLog::factory()->for($ada, 'participant')->for($activity)->create([
            'logged_on' => '2026-09-10',
            'content' => "Found a blocker\nRetested the flow.",
            'created_at' => '2026-09-16 14:05:00',
        ]);
        $bo = User::factory()->create(['name' => 'Bo Beta', 'email' => 'bo@example.test', 'training_path' => 'manual']);
        DailyLog::factory()->for($bo, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-12', 'content' => 'Read the handbook.', 'created_at' => '2026-09-12 09:00:00']);
        DailyLog::factory()->for($coach, 'participant')->for($activity)->create(['content' => 'Coach notes']);

        $response = $this->actingAs($coach)->get(route('coach.reports.daily-logs.details'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH / REPORTS',
                'Detailed Daily Logs',
                'aria-label="Reports"',
                '>Progress</a>', '>Daily Log Completion</a>', '>Daily Log Details</a>', '>Missing Submissions</a>',
                'Submitted entries',
                '2 matching log(s)',
                '>Bo Beta</strong>', '>bo@example.test</span>', '>Manual</span>', '>Sep 12, 2026</td>', '>—</td>', '>General Training</td>', '>Not assigned</td>',
                'View details', '>Read the handbook.</p>', 'Submitted Sep 12, 2026 9:00 AM',
                '>Ada Alpha</strong>', '>ada@example.test</span>', '>Automation / Manual</span>', '>Sep 10, 2026</td>', '>Week 3</td>', '>Explore checkout</td>', '>Coach Avery</td>',
                'View details', ">Found a blocker\nRetested the flow.</p>", 'Submitted Sep 16, 2026 2:05 PM',
            ], false)
            ->assertDontSee('Coach notes')
            ->assertDontSee('No Daily Logs match the selected filters.');

        $this->assertSame(1, preg_match('/aria-label="Reports">(.*?)<\/nav>/s', $response->getContent(), $reportNav));
        $this->assertSame(1, substr_count($reportNav[1], 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Daily Log Details<\/a>/', $reportNav[1]);
        $this->assertSame(2, substr_count($response->getContent(), 'data-log-row'));
    }

    public function test_detail_filters_keep_their_values_and_the_pagination_links_keep_the_query(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Paged Participant', 'training_path' => 'automation']);
        DailyLog::factory()->count(26)->for($participant, 'participant')
            ->sequence(fn (Sequence $sequence): array => ['logged_on' => sprintf('2026-09-%02d', $sequence->index + 1), 'content' => sprintf('Entry %02d', $sequence->index + 1)])
            ->create(['activity_id' => null]);
        DailyLog::factory()->for(User::factory()->state(['training_path' => 'manual']), 'participant')->create(['content' => 'Manual entry']);
        $filters = ['participant' => $participant->id, 'path' => 'automation', 'from' => '2026-09-01', 'to' => '2026-09-30'];

        $firstPage = $this->actingAs($coach)->get(route('coach.reports.daily-logs.details', $filters))
            ->assertOk()
            ->assertSee('<option value="'.$participant->id.'" selected>Paged Participant</option>', false)
            ->assertSee('<option value="automation" selected>Automation</option>', false)
            ->assertSee('value="2026-09-01"', false)
            ->assertSee('value="2026-09-30"', false)
            ->assertSee('26 matching log(s)')
            ->assertSeeInOrder(['>Entry 26</p>', '>Entry 25</p>'], false)
            ->assertSee(e(route('coach.reports.daily-logs.details', [...$filters, 'page' => 2])), false)
            ->assertDontSee('Manual entry')
            ->assertDontSee('>Entry 01</p>', false);

        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-log-row'));

        $secondPage = $this->actingAs($coach)->get(route('coach.reports.daily-logs.details', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertSee('>Entry 01</p>', false);

        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-log-row'));
    }

    public function test_detail_report_without_logs_shows_the_notice_instead_of_the_table(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('coach.reports.daily-logs.details'))
            ->assertOk()
            ->assertSee('0 matching log(s)')
            ->assertSee('No Daily Logs match the selected filters.');

        $this->assertStringNotContainsString('<table', $response->getContent());
    }

    public function test_query_count_does_not_grow_with_the_number_of_participants_and_logs(): void
    {
        $this->assertSame($this->reportQueryCounts(1), $this->reportQueryCounts(3));
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
     * Create the given number of participants, each assigned to a new coach with two Daily Logs on each of two
     * activities in different weeks, and count the queries of that coach's completion and detail report requests
     * after a warm-up request.
     *
     * @return array{completion: int, details: int}
     */
    private function reportQueryCounts(int $participantCount): array
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participants = User::factory()->count($participantCount)->create();
        $coach->assignedParticipants()->attach($participants->modelKeys());
        $activities = Activity::factory()->count(2)->create();

        foreach ($participants as $participant) {
            foreach ($activities as $activity) {
                DailyLog::factory()->count(2)->for($participant, 'participant')->for($activity)->create();
            }
        }

        $counts = [];
        foreach (['completion' => 'coach.reports.daily-logs', 'details' => 'coach.reports.daily-logs.details'] as $key => $routeName) {
            $this->actingAs($coach)->get(route($routeName))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($coach)->get(route($routeName))->assertOk()->assertSee($participants->first()->name);
            DB::disableQueryLog();
            $counts[$key] = count(DB::getQueryLog());
        }

        return $counts;
    }
}
