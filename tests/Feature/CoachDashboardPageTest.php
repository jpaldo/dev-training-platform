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

    public function test_coach_sees_the_calendar_card_the_labelled_counts_and_the_coach_tool_links(): void
    {
        $activity = Activity::factory()->create();
        foreach (['submitted', 'under_review', 'needs_revision'] as $status) {
            ActivitySubmission::factory()->for($activity)->create(['status' => $status]);
        }

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'COACH OPERATIONS',
                'Training overview',
                'MENTOR INTERVIEW AVAILABILITY',
                'Configure your interview calendar',
                'href="'.route('coach.mentor-booking-calendar.index').'"',
                'Manage Booking Calendar',
                '<dt', 'PARTICIPANTS', '<dd', '3',
                '<dt', 'COMPLETED', '<dd', '0',
                '<dt', 'NEEDS REVIEW', '<dd', '2',
                '<dt', 'NEEDS REVISION', '<dd', '1',
                '<dt', 'AT RISK', '<dd', '1',
                'aria-label="Coach tools"',
                'href="'.route('coach.participants').'"', 'Participants',
                'href="'.route('coach.reviews').'"', 'Review queue',
                'href="'.route('coach.recovery.index').'"', 'Recovery Requests',
                'href="'.route('coach.gates').'"', 'Gates',
                'href="'.route('coach.reports').'"', 'Reports',
                'href="'.route('coach.attendance.index').'"', 'Attendance',
                'href="'.route('coach.scoring.index').'"', 'Scoring',
            ], false)
            ->assertDontSee('href="'.route('admin.users.index').'"', false)
            ->assertDontSee('Settings · Google Drive');
    }

    public function test_admin_also_sees_the_users_and_google_drive_settings_links(): void
    {
        Activity::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Coach tools"',
                'href="'.route('coach.scoring.index').'"', 'Scoring',
                'href="'.route('admin.users.index').'"', 'Users',
                'href="'.route('admin.settings.google-drive').'"', 'Settings · Google Drive',
            ], false);
    }
}
