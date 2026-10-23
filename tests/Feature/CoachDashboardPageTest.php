<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CoachDashboardPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_coach_sees_the_labelled_counts_linked_to_the_pages_that_list_them_without_a_booking_calendar_card(): void
    {
        $activity = Activity::factory()->create();
        foreach (['submitted', 'under_review', 'needs_revision'] as $status) {
            ActivitySubmission::factory()->for($activity)->create(['status' => $status]);
        }

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR OPERATIONS',
                'Training overview',
                '<dt', 'href="'.route('coach.participants').'" class="outline-hidden after:absolute after:inset-0">PARTICIPANTS<span class="sr-only">, open the participants list</span></a>', '<dd', '3',
                '<dt class="text-xs font-black tracking-[0.12em] wrap-break-word text-muted uppercase">COMPLETED</dt>', '<dd', '0',
                '<dt', 'href="'.route('coach.reviews').'" class="outline-hidden after:absolute after:inset-0">NEEDS REVIEW<span class="sr-only">, open the review queue</span></a>', '<dd', '2',
                '<dt', 'href="'.route('coach.reviews', ['status' => 'needs_revision']).'" class="outline-hidden after:absolute after:inset-0">NEEDS REVISION<span class="sr-only">, open the review queue filtered to needs revision</span></a>', '<dd', '1',
                '<dt', 'href="'.route('coach.reports').'#at-risk-title" class="outline-hidden after:absolute after:inset-0">AT RISK<span class="sr-only">, open the at-risk participants in the progress report</span></a>', '<dd', '1',
            ], false)
            ->assertDontSee('aria-label="Coach tools"', false)
            ->assertDontSee('No active program.')
            ->assertDontSee('MENTOR INTERVIEW AVAILABILITY')
            ->assertDontSee('Manage Booking Calendar');
    }

    public function test_the_at_risk_link_target_exists_on_the_progress_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.reports'))
            ->assertOk()
            ->assertSee('id="at-risk-title"', false);
    }

    public function test_without_an_active_program_the_dashboard_shows_a_notice_and_no_completions(): void
    {
        $activity = Activity::factory()->create();
        $activity->week->program->update(['is_active' => false]);
        ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted']);

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Training overview',
                'role="status"',
                'No active program.',
                'Completion counts start once a training program is active.',
                '<dt', 'PARTICIPANTS', '<dd', '1',
                '<dt', 'COMPLETED', '<dd', '0',
                '<dt', 'NEEDS REVIEW', '<dd', '1',
            ], false);
    }

    public function test_admin_sees_the_same_stat_links_and_no_coach_tool_links(): void
    {
        Activity::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('coach.participants').'" class="outline-hidden after:absolute after:inset-0">PARTICIPANTS',
                'COMPLETED</dt>',
                'href="'.route('coach.reviews').'" class="outline-hidden after:absolute after:inset-0">NEEDS REVIEW',
                'href="'.route('coach.reviews', ['status' => 'needs_revision']).'" class="outline-hidden after:absolute after:inset-0">NEEDS REVISION',
                'href="'.route('coach.reports').'#at-risk-title" class="outline-hidden after:absolute after:inset-0">AT RISK',
            ], false)
            ->assertDontSee('aria-label="Coach tools"', false)
            ->assertDontSee('Settings · Google Drive');
    }
}
