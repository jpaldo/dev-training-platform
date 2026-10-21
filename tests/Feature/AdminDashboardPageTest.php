<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\AdminSettingsStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminDashboardPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_the_labelled_counts_the_integration_statuses_and_the_admin_tool_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        [$first, $second, $third] = User::factory()->count(3)->create()->all();
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 2]);
        $activity = Activity::factory()->for($week, 'week')->create();
        ActivitySubmission::factory()->for($activity)->for($first, 'participant')->create(['status' => 'submitted']);
        ActivitySubmission::factory()->for($activity)->for($second, 'participant')->create(['status' => 'needs_revision']);
        DailyLog::factory()->for($activity)->for($third, 'participant')->create();
        $gate = TrainingGate::factory()->create(['training_week_id' => $week->id]);
        GateEvaluation::factory()->create(['training_gate_id' => $gate->id, 'user_id' => $first->id, 'status' => 'passed']);
        GateEvaluation::factory()->create(['training_gate_id' => $gate->id, 'user_id' => $second->id, 'status' => 'not_ready']);
        WeekFourInterviewPair::create(['participant_one_id' => $first->id, 'participant_two_id' => $second->id, 'assigned_by_id' => $admin->id, 'status' => 'active']);
        foreach ([$first, $second, $third] as $index => $participant) {
            WeekFourMentorInterviewSchedule::create([
                'participant_id' => $participant->id,
                'mentor_id' => $coach->id,
                'activity_id' => $activity->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
                'timezone' => 'Asia/Manila',
                'status' => [WeekFourMentorInterviewSchedule::SCHEDULED, WeekFourMentorInterviewSchedule::FAILED, WeekFourMentorInterviewSchedule::PENDING][$index],
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN DASHBOARD',
                'System overview',
                '<dt', 'PARTICIPANTS', '<dd', '3',
                '<dt', 'COACHES', '<dd', '1',
                '<dt', 'ADMINS', '<dd', '1',
                'Training overview',
                'GENERAL', '<strong class="text-white">1</strong> participant(s) with activity or log records',
                'WEEK2', '<strong class="text-white">2</strong>',
                'WEEK3', '<strong class="text-white">0</strong>',
                'WEEK4', '<strong class="text-white">0</strong>',
                'Review / Gate overview',
                'AWAITING REVIEW', '<dd class="m-0 text-3xl font-black">1</dd>',
                'NEEDS REVISION', '<dd class="m-0 text-3xl font-black">1</dd>',
                'PENDING GATES', '<dd class="m-0 text-3xl font-black">1</dd>',
                'Week 4',
                'PARTNER ASSIGNED', '<dd class="m-0 text-3xl font-black">2</dd>',
                'WAITING FOR PARTNER', '<dd class="m-0 text-3xl font-black">1</dd>',
                'MENTOR INTERVIEWS SCHEDULED', '<dd class="m-0 text-3xl font-black">1</dd>',
                'CALENDAR ATTENTION', '<dd class="m-0 text-3xl font-black">2</dd>',
                'System / integration status',
                '<strong>Google:</strong>',
                '<strong>Email:</strong> '.e(app(AdminSettingsStatusService::class)->email()['status']),
                '<strong>AI:</strong> '.e(app(AdminSettingsStatusService::class)->ai()['status']),
                '<strong>Queue:</strong> Application queue configuration available; detailed worker health remains on the existing operations tooling.',
                'aria-label="Admin tools"',
                'href="'.route('admin.users.index').'"', 'Manage Participants',
                'href="'.route('coach.reviews').'"', 'Coach Reviews',
                'href="'.route('coach.gates').'"', 'Manage Gates',
                'href="'.route('coach.reports').'"', 'Reports',
                'href="'.route('training.reusable-assets.index').'"', 'Reusable Asset Library',
                'href="'.route('coach.dashboard').'"', 'Coach Tools',
                'href="'.route('admin.settings.index').'"', 'Settings',
            ], false);
    }

    public function test_week_counts_are_zero_without_an_active_program(): void
    {
        $activity = Activity::factory()->for(TrainingWeek::factory()->for(Program::factory()->state(['is_active' => false]))->state(['week_number' => 2]), 'week')->create();
        ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted']);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'training_path' => null]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK2', '<strong class="text-white">0</strong>',
                'AWAITING REVIEW', '<dd class="m-0 text-3xl font-black">1</dd>',
            ], false);
    }
}
