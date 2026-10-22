<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachParticipantDailyLogPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_participant_summary_week_options_and_logs_newest_first(): void
    {
        $this->travelTo('2026-09-20 10:00:00');
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Ada Alpha', 'email' => 'ada@example.test', 'training_path' => 'manual']);
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 3]);
        TrainingWeek::factory()->for($program)->create(['week_number' => 2]);
        TrainingWeek::factory()->for($program)->create(['week_number' => 5, 'is_active' => false]);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Explore checkout']);
        DailyLog::factory()->for($participant, 'participant')->for($activity)->create([
            'logged_on' => '2026-09-14',
            'content' => "Checked the cart\nFiled two bugs",
            'created_at' => '2026-09-14 16:05:00',
        ]);
        DailyLog::factory()->for($participant, 'participant')->create([
            'activity_id' => null,
            'logged_on' => '2026-09-15',
            'content' => 'Read the testing guide',
            'created_at' => '2026-09-15 09:30:00',
        ]);
        DailyLog::factory()->for(User::factory(), 'participant')->for($activity)->create(['content' => 'Someone else entirely']);

        $response = $this->actingAs($coach)->get(route('coach.participants.daily-logs', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH · DAILY LOGS',
                '>Daily Logs</h1>',
                '>Ada Alpha</strong>', '>ada@example.test</span>', '>Manual QA</span>', '>2 total logs</span>',
                'href="'.route('coach.participants').'"', '>← Back to Participants</a>',
                'action="'.route('coach.participants.daily-logs', $participant).'"',
                '<option value="">All Weeks</option>',
                '<option value="2"', '>Week 2</option>',
                '<option value="3"', '>Week 3</option>',
                'name="date" value="" max="2026-09-20"',
                '>Filter</button>',
                '>Daily Logs</h2>', '· 2 total', 'Showing 1–2 of 2 Daily Logs',
                '>Sep 15, 2026</th>', '>General</span>', '>General training</td>', '>Read the testing guide</td>', '>Sep 15, 2026 · 9:30 AM</td>',
                '>Sep 14, 2026</th>', '>Week 3</span>', '>Explore checkout</td>', ">Checked the cart\nFiled two bugs</td>", '>Sep 14, 2026 · 4:05 PM</td>',
            ], false)
            ->assertDontSee('Week 5')
            ->assertDontSee('Someone else entirely')
            ->assertDontSee('>Clear</a>', false)
            ->assertDontSee('No Daily Logs have been submitted by this participant yet.');

        $this->assertSame(2, substr_count($response->getContent(), 'data-log-row'));
        $this->assertStringNotContainsString('selected', $this->weekSelectMarkup($response->getContent()));
    }

    public function test_filters_keep_their_values_and_the_pagination_links_keep_the_query(): void
    {
        $this->travelTo('2026-09-20 10:00:00');
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2]);
        $otherWeek = TrainingWeek::factory()->for($program)->create(['week_number' => 3]);
        $activity = Activity::factory()->for($week, 'week')->create();
        DailyLog::factory()->count(26)->for($participant, 'participant')->for($activity)
            ->sequence(fn (Sequence $sequence): array => ['content' => sprintf('Filtered log %02d', $sequence->index + 1)])
            ->create(['logged_on' => '2026-09-14']);
        DailyLog::factory()->for($participant, 'participant')->for(Activity::factory()->for($otherWeek, 'week'))->create(['logged_on' => '2026-09-14', 'content' => 'Other week log']);

        $firstPage = $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'week' => 2, 'date' => '2026-09-14']))
            ->assertOk()
            ->assertSee('<option value="2" selected>Week 2</option>', false)
            ->assertSee('name="date" value="2026-09-14"', false)
            ->assertSee('href="'.route('coach.participants.daily-logs', $participant).'"', false)
            ->assertSee('>Clear</a>', false)
            ->assertSee('Showing 1–25 of 26 Daily Logs')
            ->assertSee(e(route('coach.participants.daily-logs', [$participant, 'week' => 2, 'date' => '2026-09-14', 'page' => 2])), false)
            ->assertDontSee('Other week log');
        $this->assertSame(25, substr_count($firstPage->getContent(), 'data-log-row'));
        $this->assertSame(1, substr_count($this->weekSelectMarkup($firstPage->getContent()), 'selected'));

        $secondPage = $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'week' => 2, 'date' => '2026-09-14', 'page' => 2]))
            ->assertOk()
            ->assertSee('Showing 26–26 of 26 Daily Logs');
        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-log-row'));
    }

    public function test_a_participant_without_logs_shows_the_notice_instead_of_a_table(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();

        $this->actingAs($coach)->get(route('coach.participants.daily-logs', $participant))
            ->assertOk()
            ->assertSee('0 total logs')
            ->assertSee('No Daily Logs have been submitted by this participant yet.')
            ->assertDontSee('Go to the last page')
            ->assertDontSee('Showing')
            ->assertDontSee('<table', false);
    }

    public function test_week_options_come_from_the_active_program_and_filter_by_its_week(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $activeProgram = Program::factory()->create();
        $laterProgram = Program::factory()->create();
        Program::factory()->create(['is_active' => false]);
        $activeWeek = TrainingWeek::factory()->for($activeProgram)->create(['week_number' => 2]);
        $laterWeek = TrainingWeek::factory()->for($laterProgram)->create(['week_number' => 2]);
        DailyLog::factory()->for($participant, 'participant')->for(Activity::factory()->for($activeWeek, 'week'))->create(['content' => 'Active program log']);
        DailyLog::factory()->for($participant, 'participant')->for(Activity::factory()->for($laterWeek, 'week'))->create(['content' => 'Later program log']);

        $response = $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'week' => 2]))
            ->assertOk()
            ->assertSee('Active program log')
            ->assertDontSee('Later program log');

        $this->assertSame(1, substr_count($this->weekSelectMarkup($response->getContent()), '>Week 2</option>'));
    }

    public function test_a_filter_without_matches_says_so_instead_of_no_logs_yet(): void
    {
        $this->travelTo('2026-09-20 10:00:00');
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        DailyLog::factory()->for($participant, 'participant')->create(['logged_on' => '2026-09-14']);

        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'date' => '2026-09-15']))
            ->assertOk()
            ->assertSee('1 total logs')
            ->assertSee('No Daily Logs match the selected filters.')
            ->assertDontSee('No Daily Logs have been submitted by this participant yet.')
            ->assertDontSee('Showing')
            ->assertDontSee('<table', false);
    }

    public function test_a_page_past_the_last_one_links_to_the_last_page(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        DailyLog::factory()->count(26)->for($participant, 'participant')->create(['activity_id' => null]);

        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'page' => 5]))
            ->assertOk()
            ->assertSee('· 26 total')
            ->assertSee('There are no Daily Logs on this page.')
            ->assertSeeInOrder(['href="'.e(route('coach.participants.daily-logs', [$participant, 'page' => 2])).'"', '>Go to the last page</a>'], false)
            ->assertDontSee('Showing')
            ->assertDontSee('No Daily Logs have been submitted by this participant yet.')
            ->assertDontSee('<table', false);
    }

    public function test_query_count_does_not_grow_with_the_number_of_logs(): void
    {
        $this->assertSame($this->queryCountForLogs(1), $this->queryCountForLogs(3));
    }

    private function weekSelectMarkup(string $html): string
    {
        $this->assertSame(1, preg_match('/<select id="daily-log-week".*?<\/select>/s', $html, $select));

        return $select[0];
    }

    /**
     * Create a participant with the given number of Daily Logs, each on an activity in its own week, and count the
     * queries of a coach's request for that participant's Daily Logs after a warm-up request.
     */
    private function queryCountForLogs(int $logCount): int
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        DailyLog::factory()->count($logCount)->for($participant, 'participant')
            ->sequence(fn (Sequence $sequence): array => ['activity_id' => Activity::factory()->for(TrainingWeek::factory(), 'week')])
            ->create();
        $url = route('coach.participants.daily-logs', $participant);
        $this->actingAs($coach)->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
