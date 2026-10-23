<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmptyTrainingDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NO_ACTIVE_PROGRAM_NOTICE = 'No training program is active yet.';

    /**
     * Every page and CSV export of the participant, coach and admin areas that reads the active program or training
     * data, with the role that opens it and its route parameters.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function pages(): array
    {
        return [
            'participant dashboard' => ['participant', 'dashboard', []],
            'participant attendance' => ['participant', 'participant.attendance.index', []],
            'participant notifications' => ['participant', 'participant.notifications', []],
            'participant profile' => ['participant', 'profile.show', []],
            'coach dashboard' => ['coach', 'coach.dashboard', []],
            'coach participants' => ['coach', 'coach.participants', []],
            'coach review queue' => ['coach', 'coach.reviews', []],
            'coach recovery requests' => ['coach', 'coach.recovery.index', []],
            'coach Gate board' => ['coach', 'coach.gates', []],
            'coach progress report' => ['coach', 'coach.reports', []],
            'coach missing submissions' => ['coach', 'coach.reports.missing-submissions', []],
            'coach scoring completion' => ['coach', 'coach.reports.scoring-completion', []],
            'coach scoring overview' => ['coach', 'coach.scoring.index', []],
            'coach scoring criteria' => ['coach', 'coach.scoring.criteria', []],
            'coach scoring reports' => ['coach', 'coach.scoring.reports', []],
            'coach attendance' => ['coach', 'coach.attendance.index', []],
            'admin dashboard' => ['admin', 'admin.dashboard', []],
            'admin attendance' => ['admin', 'admin.attendance.index', []],
            'admin users' => ['admin', 'admin.users.index', []],
            'admin training settings' => ['admin', 'admin.settings.training', []],
            'progress csv' => ['coach', 'coach.reports.export', ['type' => 'progress']],
            'reviews csv' => ['coach', 'coach.reports.export', ['type' => 'reviews']],
            'Gates csv' => ['coach', 'coach.reports.export', ['type' => 'gates']],
            'at-risk csv' => ['coach', 'coach.reports.export', ['type' => 'risk']],
            'missing submissions csv' => ['coach', 'coach.reports.missing-submissions.export', []],
            'activity scores csv' => ['coach', 'coach.reports.scores', []],
            'participant score summary csv' => ['coach', 'coach.reports.participant-summary', []],
            'coach attendance csv' => ['coach', 'coach.attendance.export', []],
            'admin attendance csv' => ['admin', 'admin.attendance.export', []],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     */
    #[DataProvider('pages')]
    public function test_page_opens_without_any_program_or_training_data(string $role, string $routeName, array $parameters): void
    {
        $participant = User::factory()->create();
        $viewer = $role === 'participant' ? $participant : User::factory()->create(['role' => $role]);

        $this->actingAs($viewer)->get(route($routeName, $parameters))->assertOk();
    }

    public function test_coach_participant_page_opens_without_any_program_or_training_data(): void
    {
        $participant = User::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.participants.show', $participant))
            ->assertOk();
    }

    public function test_participant_dashboard_shows_empty_training_lists_without_any_training_data(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['You are not in a training yet.', 'No past trainings yet.'])
            ->assertDontSee('data-page-tabs', false);
    }

    public function test_scoring_overview_says_no_program_is_active_and_lists_participants_without_weeks(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.index'))
            ->assertOk()
            ->assertSeeInOrder([self::NO_ACTIVE_PROGRAM_NOTICE, 'Ada Lovelace', '0 / 0 (0%)'])
            ->assertDontSee('>W1<', false);
    }

    public function test_participant_scoring_page_says_no_program_is_active_and_shows_nothing_completed(): void
    {
        $participant = User::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.scoring.participant', $participant))
            ->assertOk()
            ->assertSeeInOrder([self::NO_ACTIVE_PROGRAM_NOTICE, 'OVERALL SCORE', '— / 100', 'OVERALL COMPLETION', '0 / 0'])
            ->assertDontSee('WEEK 1');
    }

    public function test_admin_dashboard_says_no_program_is_active_in_the_training_overview(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Training overview', self::NO_ACTIVE_PROGRAM_NOTICE, 'Review / Gate overview']);
    }

    public function test_participant_score_summary_csv_lists_each_participant_with_nothing_completed(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace', 'employee_id' => 'EMP-000001', 'email' => 'ada@example.com']);

        $csv = $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports.participant-summary'))
            ->assertOk()
            ->assertDownload('participant-score-summary.csv')
            ->streamedContent();

        $rows = array_map(str_getcsv(...), array_filter(explode("\n", $csv)));
        $this->assertCount(2, $rows);
        $this->assertSame(['Ada Lovelace', 'EMP-000001', 'ada@example.com'], array_slice($rows[1], 0, 3));
        $this->assertSame(['0', '0', '0', '', '', '', '', 'Not Scored', ''], array_slice($rows[1], 4));
    }

    public function test_pages_do_not_show_the_notice_while_a_program_is_active(): void
    {
        TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 1, 'position' => 1]);
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertDontSee(self::NO_ACTIVE_PROGRAM_NOTICE);
        $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk()->assertDontSee(self::NO_ACTIVE_PROGRAM_NOTICE);
        $this->actingAs($coach)->get(route('coach.scoring.participant', $participant))->assertOk()->assertDontSee(self::NO_ACTIVE_PROGRAM_NOTICE);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(self::NO_ACTIVE_PROGRAM_NOTICE);
    }
}
