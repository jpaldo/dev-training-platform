<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\AdminSettingsStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_the_labelled_counts_the_integration_statuses_and_the_admin_tool_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'coach']);
        [$first, $second] = User::factory()->count(3)->create()->all();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2]);
        $activity = Activity::factory()->for($week, 'week')->create();
        ActivitySubmission::factory()->for($activity)->for($first, 'participant')->create(['status' => 'submitted']);
        ActivitySubmission::factory()->for($activity)->for($second, 'participant')->create(['status' => 'needs_revision']);
        $gate = TrainingGate::factory()->create(['training_week_id' => $week->id]);
        GateEvaluation::factory()->create(['training_gate_id' => $gate->id, 'user_id' => $first->id, 'status' => 'passed']);
        GateEvaluation::factory()->create(['training_gate_id' => $gate->id, 'user_id' => $second->id, 'status' => 'not_ready']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN DASHBOARD',
                'System overview',
                '<dt', 'PARTICIPANTS', '<dd', '3',
                '<dt', 'MENTORS', '<dd', '1',
                '<dt', 'ADMINS', '<dd', '1',
                'Review / Gate overview',
                'AWAITING REVIEW', '<dd class="m-0 text-3xl font-black">1</dd>',
                'NEEDS REVISION', '<dd class="m-0 text-3xl font-black">1</dd>',
                'PENDING GATES', '<dd class="m-0 text-3xl font-black">1</dd>',
                'System / integration status',
                '<strong>Google:</strong> Needs Configuration',
                '<strong>Email:</strong> '.e(app(AdminSettingsStatusService::class)->email()['status']),
                '<strong>AI:</strong> '.e(app(AdminSettingsStatusService::class)->ai()['status']),
                '<strong>Queue:</strong> Application queue configuration available; detailed worker health remains on the existing operations tooling.',
                'aria-label="Admin tools"',
                'href="'.route('admin.users.index').'"', 'Manage Participants',
                'href="'.route('coach.reviews').'"', 'Mentor Reviews',
                'href="'.route('coach.gates').'"', 'Manage Gates',
                'href="'.route('coach.reports').'"', 'Reports',
                'href="'.route('coach.dashboard').'"', 'Mentor Tools',
                'href="'.route('admin.settings.index').'"', 'Settings',
            ], false)
            ->assertDontSee('PARTNER ASSIGNED')
            ->assertDontSee('WAITING FOR PARTNER')
            ->assertDontSee('MENTOR INTERVIEWS SCHEDULED')
            ->assertDontSee('CALENDAR ATTENTION');
    }

    public function test_review_counts_cover_every_program_and_no_program_wide_training_stats_are_shown(): void
    {
        $activePrograms = Program::factory()->count(2)->create();
        $inactiveProgram = Program::factory()->create(['is_active' => false]);

        foreach ([...$activePrograms, $inactiveProgram] as $program) {
            $week = TrainingWeek::factory()->for($program)->create(['week_number' => 1]);
            $activity = Activity::factory()->for($week, 'week')->create();
            ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted']);
            ActivitySubmission::factory()->for($activity)->create(['status' => 'needs_revision']);
            GateEvaluation::factory()->create([
                'training_gate_id' => TrainingGate::factory()->create(['training_week_id' => $week->id])->id,
                'status' => 'needs_revision',
            ]);
        }

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Review / Gate overview',
                'AWAITING REVIEW', '<dd class="m-0 text-3xl font-black">3</dd>',
                'NEEDS REVISION', '<dd class="m-0 text-3xl font-black">3</dd>',
                'PENDING GATES', '<dd class="m-0 text-3xl font-black">3</dd>',
            ], false)
            ->assertDontSee('Training overview')
            ->assertDontSee('participant(s) with activity records')
            ->assertDontSee('No training program is active yet.');
    }

    public function test_the_curricula_card_lists_the_five_latest_starting_curricula_unscheduled_last_linked_to_their_pages(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $programs = collect([
            'October Run' => '2026-10-05',
            'January Run' => '2027-01-04',
            'September Run' => '2026-09-07',
            'November Run' => '2026-11-09',
            'August Run' => '2026-08-03',
            'December Run' => '2026-12-07',
        ])->map(fn (string $startsOn, string $name): Program => Program::factory()->withWeeks(4, Carbon::parse($startsOn))->create(['name' => $name]));
        Program::factory()->withWeeks(4)->unscheduled()->create(['name' => 'Draft Run']);
        $programs['November Run']->participants()->attach(User::factory()->count(2)->create(), ['joined_at' => now()]);

        $response = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Programs',
                'href="'.route('admin.programs.index').'"', 'View all programs',
                'href="'.route('admin.programs.show', $programs['January Run']).'"', 'January Run', 'Upcoming',
                'href="'.route('admin.programs.show', $programs['December Run']).'"', 'December Run', 'Upcoming',
                'href="'.route('admin.programs.show', $programs['November Run']).'"', 'November Run', 'Active', '9 Nov – 6 Dec 2026', 'Participants: <strong class="text-white tabular-nums">2</strong>',
                'href="'.route('admin.programs.show', $programs['October Run']).'"', 'October Run', 'Completed', 'Participants: <strong class="text-white tabular-nums">0</strong>',
                'href="'.route('admin.programs.show', $programs['September Run']).'"', 'September Run', 'Completed',
                'Review / Gate overview',
            ], false)
            ->assertDontSee('August Run')
            ->assertDontSee('Draft Run')
            ->assertDontSee('No programs yet.');

        $this->assertSame(5, substr_count($response->getContent(), 'data-recent-program-row'));
    }

    public function test_the_curricula_card_shows_an_empty_state_without_curricula(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Programs',
                'href="'.route('admin.programs.index').'"', 'View all programs',
                'No programs yet.',
                'Review / Gate overview',
            ], false)
            ->assertDontSee('data-recent-program-row', false)
            ->assertDontSee('No training program is active yet.');
    }
}
