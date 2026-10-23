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
                'Training overview',
                'WEEK2', '<strong class="text-white">2</strong> participant(s) with activity records',
                'WEEK3', '<strong class="text-white">0</strong>',
                'WEEK4', '<strong class="text-white">0</strong>',
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

    public function test_week_counts_are_zero_without_an_active_program(): void
    {
        $activity = Activity::factory()->for(TrainingWeek::factory()->for(Program::factory()->state(['is_active' => false]))->state(['week_number' => 2]), 'week')->create();
        ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK2', '<strong class="text-white">0</strong>',
                'AWAITING REVIEW', '<dd class="m-0 text-3xl font-black">1</dd>',
            ], false);
    }
}
