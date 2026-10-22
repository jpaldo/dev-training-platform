<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyLogPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_training_log_shows_weekly_progress_the_logs_and_a_single_new_log_main_action(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1);
        $weekTwo = $this->week($program, 2);
        $weekThree = $this->week($program, 3);
        TrainingWeek::factory()->for($program)->create(['week_number' => 9, 'position' => 9, 'is_active' => false]);
        $weekOneActivity = Activity::factory()->for($weekOne, 'week')->create();
        $weekTwoActivity = Activity::factory()->for($weekTwo, 'week')->create(['title' => 'Exploratory charter']);
        $weekThreeActivity = Activity::factory()->for($weekThree, 'week')->create();
        $this->logs($participant, $weekOneActivity, 4);
        $this->logs($participant, $weekTwoActivity, 4);
        $this->logs($participant, $weekThreeActivity, 2);
        $activityLog = DailyLog::factory()->for($participant, 'participant')->for($weekTwoActivity)->create(['logged_on' => '2026-09-09', 'content' => 'Found a blocker']);
        $generalLog = DailyLog::factory()->for($participant, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-10', 'content' => 'General notes']);

        $response = $this->actingAs($participant)->withSession(['status' => 'Daily log saved.'])->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'DAILY LOG',
                'Your training log',
                'id="daily-logs-main-action"',
                'href="'.route('daily-logs.create').'"',
                'New log →',
                'role="status"',
                'Daily log saved.',
                'Weekly progress',
                'Week 1',
                '4 / 5 completed',
                'Complete 1 more daily log.',
                'Week 2',
                '5 / 5 completed',
                '5 of 5 daily logs completed.',
                'Week 3',
                '2 / 5 completed',
                'Complete 3 more daily logs.',
                'Daily Logs',
                'Your daily logs, newest first',
                'Sep 10, 2026',
                'General Training',
                '—',
                'General Training',
                'General notes',
                'href="'.route('daily-logs.edit', $generalLog).'"',
                'Edit',
                'Sep 9, 2026',
                'Training Activity',
                'Week 2',
                'Exploratory charter',
                'Found a blocker',
                'href="'.route('daily-logs.edit', $activityLog).'"',
                'Edit',
            ], false)
            ->assertDontSee('Week 9');

        $this->assertSame(1, substr_count($response->getContent(), 'New log'));
        $this->assertSame(12, substr_count($response->getContent(), 'data-daily-log-row'));
    }

    public function test_training_log_counts_weekly_progress_against_the_configured_minimum(): void
    {
        config(['training.daily_logs_per_week' => 3]);
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $this->logs($participant, Activity::factory()->for($this->week($program, 1), 'week')->create(), 3);
        $this->logs($participant, Activity::factory()->for($this->week($program, 2), 'week')->create(), 1);

        $this->actingAs($participant)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Each active training week requires 3 completed daily logs.',
                'Week 1', '3 / 3 completed', '3 of 3 daily logs completed.',
                'Week 2', '1 / 3 completed', 'Complete 2 more daily logs.',
            ]);
    }

    public function test_training_log_without_logs_shows_the_empty_state(): void
    {
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->get(route('daily-logs.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="daily-logs-main-action"',
                'New log →',
                'Weekly progress',
                'Daily Logs',
                'No Daily Logs yet.',
            ], false)
            ->assertDontSee('<table', false);

        $this->assertSame(1, substr_count($response->getContent(), 'New log'));
    }

    public function test_training_log_query_count_does_not_grow_with_weeks_and_logs(): void
    {
        $this->assertSame($this->trainingLogQueryCount(1), $this->trainingLogQueryCount(3));
    }

    public function test_new_log_form_offers_only_activities_of_accessible_weeks_and_submits_from_the_main_action(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $weekOne = $this->week($program, 1);
        $lockedWeek = $this->week($program, 2);
        Activity::factory()->for($weekOne, 'week')->create(['title' => 'Requirement review', 'position' => 1]);
        Activity::factory()->for($weekOne, 'week')->create(['title' => 'Retired activity', 'position' => 2, 'is_active' => false]);
        Activity::factory()->for($lockedWeek, 'week')->create(['title' => 'Locked charter', 'position' => 1]);
        $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();

        $response = $this->actingAs($participant)->get(route('daily-logs.create'))
            ->assertOk()
            ->assertSeeInOrder([
                'DAILY LOG',
                "Record today's work",
                'id="daily-log-main-action"',
                'form="daily-log-form"',
                'Save log',
                'action="'.route('daily-logs.store').'" id="daily-log-form"',
                'for="daily-log-date"',
                'data-date-picker',
                'name="logged_on" value="'.$today.'" max="'.$today.'" required',
                'Select the date you performed this activity.',
                'for="daily-log-activity"',
                'Activity (optional)',
                '<option value="">General training</option>',
                'Week 1 · Requirement review',
                'for="daily-log-content"',
                'name="content"',
            ], false)
            ->assertDontSee('Retired activity')
            ->assertDontSee('Locked charter')
            ->assertDontSee('name="_method"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'Save log'));
    }

    public function test_rejected_new_log_returns_to_the_form_with_the_error_and_the_old_input(): void
    {
        $participant = User::factory()->create();
        $week = $this->week(Program::factory()->create(), 1);
        $activity = Activity::factory()->for($week, 'week')->create(['title' => 'Requirement review']);
        $futureDate = now(config('app.timezone', 'Asia/Manila'))->addDay()->toDateString();

        $this->actingAs($participant)->from(route('daily-logs.create'))->followingRedirects()
            ->post(route('daily-logs.store'), ['activity_id' => $activity->id, 'logged_on' => $futureDate, 'content' => 'Draft notes'])
            ->assertOk()
            ->assertSeeInOrder([
                'role="alert"',
                'The logged on field must be a date before or equal to',
                'value="'.$futureDate.'"',
                '<option value="'.$activity->id.'" selected>Week 1 · Requirement review</option>',
                'Draft notes</textarea>',
            ], false);

        $this->assertSame(0, $participant->dailyLogs()->count());
    }

    public function test_new_log_form_query_count_does_not_grow_with_activities(): void
    {
        $this->assertSame($this->newLogFormQueryCount(1), $this->newLogFormQueryCount(3));
    }

    public function test_edit_form_shows_the_stored_log_and_updates_from_the_main_action(): void
    {
        $participant = User::factory()->create();
        $storedDate = now(config('app.timezone', 'Asia/Manila'))->subDays(3)->toDateString();
        $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();
        $log = DailyLog::factory()->for($participant, 'participant')->create(['activity_id' => null, 'logged_on' => $storedDate, 'content' => 'Existing entry']);

        $response = $this->actingAs($participant)->get(route('daily-logs.edit', $log))
            ->assertOk()
            ->assertSeeInOrder([
                'DAILY LOG',
                'Edit training log',
                'id="daily-log-main-action"',
                'form="daily-log-form"',
                'Update log',
                'action="'.route('daily-logs.update', $log).'" id="daily-log-form"',
                'name="_method" value="PATCH"',
                'name="logged_on" value="'.$storedDate.'" max="'.$today.'" required',
                'Existing entry</textarea>',
            ], false)
            ->assertDontSee('name="activity_id"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'Update log'));
    }

    public function test_rejected_log_update_returns_to_the_edit_form_with_the_error_and_the_old_input(): void
    {
        $participant = User::factory()->create();
        $log = DailyLog::factory()->for($participant, 'participant')->create(['activity_id' => null, 'logged_on' => '2026-09-10', 'content' => 'Existing entry']);
        $editUrl = route('daily-logs.edit', $log);

        $this->actingAs($participant)->from($editUrl)->followingRedirects()
            ->patch(route('daily-logs.update', $log), ['logged_on' => '2026-09-10', 'content' => ''])
            ->assertOk()
            ->assertSeeInOrder([
                'role="alert"',
                'The content field is required.',
                'value="2026-09-10"',
                '></textarea>',
            ], false);

        $this->assertSame('Existing entry', $log->fresh()->content);
    }

    /**
     * Give a new participant an active program with the given number of active weeks, each with one activity and the
     * given number of daily logs, plus as many general logs, and count the queries of the training log page request
     * after a warm-up request.
     */
    private function trainingLogQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        foreach (range(1, $rows) as $weekNumber) {
            $activity = Activity::factory()->for($this->week($program, $weekNumber), 'week')->create();
            $this->logs($participant, $activity, $rows);
        }
        DailyLog::factory()->count($rows)->for($participant, 'participant')->create(['activity_id' => null]);
        $url = route('daily-logs.index');

        $this->actingAs($participant)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($participant)->get($url)->assertOk();
        DB::disableQueryLog();
        $this->assertSame($rows * $rows + $rows, substr_count($response->getContent(), 'data-daily-log-row'));
        $program->update(['is_active' => false]);

        return count(DB::getQueryLog());
    }

    /**
     * Give a new participant a program with the given number of weeks, each with one activity and a Gate the
     * participant passed (so every week is accessible), and count the queries of the new log form request after a
     * warm-up request.
     */
    private function newLogFormQueryCount(int $rows): int
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        foreach (range(1, $rows) as $weekNumber) {
            $week = $this->week($program, $weekNumber);
            Activity::factory()->for($week, 'week')->create(['title' => "Activity of week {$weekNumber}"]);
            GateEvaluation::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        }
        $url = route('daily-logs.create');

        $this->actingAs($participant)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($participant)->get($url)->assertOk();
        DB::disableQueryLog();
        $this->assertSame($rows, substr_count($response->getContent(), 'Activity of week'));
        Activity::query()->update(['is_active' => false]);

        return count(DB::getQueryLog());
    }

    private function week(Program $program, int $weekNumber): TrainingWeek
    {
        return TrainingWeek::factory()->for($program)->create(['week_number' => $weekNumber, 'position' => $weekNumber]);
    }

    private function logs(User $participant, Activity $activity, int $count): void
    {
        DailyLog::factory()->count($count)->for($participant, 'participant')->for($activity)->create(['logged_on' => '2026-09-01']);
    }
}
