<?php

namespace Tests\Feature;

use App\Enums\InterviewerApprovalStatus;
use App\Models\InterviewScheduler;
use App\Models\User;
use App\Rules\ExternalBookingUrl;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class InterviewSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_interview_page_shows_active_interviewers_and_tabs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        InterviewScheduler::create(['interview_type' => 'mock', 'full_name' => 'External Mentor', 'booking_url' => 'https://calendar.app.google/mock', 'mock_booking_url' => 'https://calendar.app.google/mock', 'is_active' => true]);
        InterviewScheduler::create(['interview_type' => 'final_reassessment', 'full_name' => 'Final Reviewer', 'booking_url' => 'https://calendar.app.google/final', 'final_booking_url' => 'https://calendar.app.google/final', 'is_active' => true]);

        $this->get(route('interviews.index'))->assertOk()->assertSee('Schedule your interview with our Mentors')->assertSee('External Mentor')->assertSee('Final Re-assessment Interview')->assertSee('rel="noopener noreferrer"', false);
        $this->actingAs($admin)->get(route('admin.settings.interview-scheduling'))->assertOk();
    }

    public function test_both_interviewer_uses_separate_links_in_each_public_tab(): void
    {
        InterviewScheduler::create(['interview_type' => 'both', 'full_name' => 'Dual Interviewer', 'booking_url' => 'https://calendar.app.google/mock-dual', 'mock_booking_url' => 'https://calendar.app.google/mock-dual', 'final_booking_url' => 'https://calendar.app.google/final-dual', 'is_active' => true]);

        $this->get(route('interviews.index'))->assertOk()->assertSee('https://calendar.app.google/mock-dual')->assertSee('https://calendar.app.google/final-dual');
    }

    public function test_admin_can_configure_public_page_title_and_empty_title_uses_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.settings.interview-scheduling.title.update'), ['public_title' => ' Full Scale AI Training '])->assertRedirect();
        $this->get(route('interviews.index'))->assertSee('Full Scale AI Training')->assertSee('<title>Full Scale AI Training | Interview Scheduling</title>', false);
        $this->get(route('interviews.register'))->assertSee('Full Scale AI Training');

        $this->actingAs($admin)->post(route('admin.settings.interview-scheduling.title.update'), ['public_title' => ''])->assertRedirect();
        $this->get(route('interviews.index'))->assertSee('Developer Training Platform');
    }

    public function test_public_registration_page_renders_the_form_and_calendar_configuration_link(): void
    {
        $this->get(route('interviews.register'))
            ->assertOk()
            ->assertSee('Become an Interviewer')
            ->assertSee('Configure with Google Calendar')
            ->assertSee('https://calendar.google.com/calendar/u/0/r/appointment', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('Full Name')
            ->assertSee('Mock Interview Booking Link')
            ->assertSee('Mock Interview')
            ->assertSee('Final Re-assessment Interview')
            ->assertSee('Both')
            ->assertSee('Submit Interviewer Information');
    }

    public function test_public_registration_accepts_each_interview_type_without_creating_a_user(): void
    {
        $beforeUsers = User::query()->count();

        foreach ([
            ['Mock Reviewer', 'mock'],
            ['Final Reviewer', 'final_reassessment'],
            ['Both Reviewer', 'both'],
        ] as [$name, $type]) {
            $payload = [
                'full_name' => $name,
                'interview_type' => $type,
            ];
            if ($type === 'mock' || $type === 'both') {
                $payload['mock_booking_url'] = 'https://calendar.app.google/'.strtolower(str_replace(' ', '-', $name)).'-mock';
            }
            if ($type === 'final_reassessment' || $type === 'both') {
                $payload['final_booking_url'] = 'https://calendar.app.google/'.strtolower(str_replace(' ', '-', $name)).'-final';
            }
            $this->post(route('interviews.register.store'), $payload)->assertRedirect(route('interviews.register'))->assertSessionHas('status');
        }

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'Mock Reviewer', 'interview_type' => 'mock', 'approval_status' => 'pending']);
        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'Final Reviewer', 'interview_type' => 'final_reassessment', 'approval_status' => 'pending']);
        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'Both Reviewer', 'interview_type' => 'both', 'approval_status' => 'pending']);
    }

    public function test_public_registration_requires_the_url_for_each_selected_type(): void
    {
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['full_name' => 'Mock Missing', 'interview_type' => 'mock'])->assertSessionHasErrors('mock_booking_url');
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['full_name' => 'Final Missing', 'interview_type' => 'final_reassessment'])->assertSessionHasErrors('final_booking_url');
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['full_name' => 'Both Missing', 'interview_type' => 'both', 'mock_booking_url' => 'https://calendar.app.google/mock'])->assertSessionHasErrors('final_booking_url');
    }

    public function test_rate_limited_public_registration_is_rejected_before_validation(): void
    {
        foreach (range(1, 5) as $attempt) {
            RateLimiter::hit('public-interviewer-registration|127.0.0.1', 600);
        }

        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['interview_type' => 'other'])
            ->assertRedirect(route('interviews.register'))
            ->assertSessionHasErrors('registration')
            ->assertSessionDoesntHaveErrors(['full_name', 'interview_type']);
        $this->assertDatabaseCount('interview_schedulers', 0);
    }

    public function test_invalid_public_registration_counts_toward_the_rate_limit(): void
    {
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['interview_type' => 'other'])->assertSessionHasErrors('interview_type');

        $this->assertSame(1, RateLimiter::attempts('public-interviewer-registration|127.0.0.1'));
    }

    public function test_provider_neutral_booking_urls_are_accepted_and_mixed_providers_work(): void
    {
        $this->post(route('interviews.register.store'), ['full_name' => 'Calendly Reviewer', 'interview_type' => 'mock', 'mock_booking_url' => 'https://calendly.com/example/mock'])->assertRedirect();
        $this->post(route('interviews.register.store'), ['full_name' => 'Mixed Reviewer', 'interview_type' => 'both', 'mock_booking_url' => 'https://calendly.com/example/mock', 'final_booking_url' => 'https://cal.com/example/final'])->assertRedirect();

        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'Calendly Reviewer', 'mock_booking_url' => 'https://calendly.com/example/mock']);
        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'Mixed Reviewer', 'mock_booking_url' => 'https://calendly.com/example/mock', 'final_booking_url' => 'https://cal.com/example/final']);
    }

    public function test_provider_neutral_rule_rejects_unsafe_destinations(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,test', 'file:///tmp/booking', 'http://booking.example.com', '/relative/path', '//booking.example.com/path', 'https://localhost/booking', 'https://127.0.0.1/booking', 'https://10.0.0.1/booking'] as $url) {
            $this->assertTrue(Validator::make(['url' => $url], ['url' => [new ExternalBookingUrl]])->fails(), $url);
        }
    }

    public function test_admin_and_registered_mentor_accept_provider_neutral_urls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.settings.interview-scheduling.store'), ['interview_type' => 'mock', 'full_name' => 'Cal Admin', 'mock_booking_url' => 'https://booking.example.com/admin', 'is_active' => '1'])->assertRedirect();
        $mentor = User::factory()->create(['role' => 'coach']);
        $this->actingAs($mentor)->post(route('coach.mentor-booking-calendar.store'), ['booking_url' => 'https://outlook.office.com/book/example'])->assertRedirect();
        $this->assertDatabaseHas('interview_schedulers', ['user_id' => $mentor->id, 'mock_booking_url' => 'https://outlook.office.com/book/example']);
    }

    public function test_admin_can_create_external_interviewer_and_unsafe_url_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['interview_type' => 'mock', 'full_name' => 'External Mentor', 'email' => 'mentor@example.com', 'mock_booking_url' => 'https://calendar.app.google/abc', 'is_active' => '1'];
        $this->actingAs($admin)->post(route('admin.settings.interview-scheduling.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'External Mentor', 'user_id' => null]);
        $this->actingAs($admin)->from(route('admin.settings.interview-scheduling'))->post(route('admin.settings.interview-scheduling.store'), [...$payload, 'mock_booking_url' => 'javascript:alert(1)'])->assertSessionHasErrors('mock_booking_url');
    }

    public function test_assigned_participant_sees_registered_mentor_booking_link_without_local_slot_form(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);
        $mentor = User::factory()->create(['role' => 'coach']);
        $participant->assignedCoaches()->attach($mentor->id);
        InterviewScheduler::create(['interview_type' => 'mock', 'user_id' => $mentor->id, 'full_name' => $mentor->name, 'email' => $mentor->email, 'booking_url' => 'https://calendar.app.google/mock', 'is_active' => true]);

        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertSee('Schedule Mock Interview')->assertSee('https://calendar.app.google/mock')->assertDontSee('dashboard-mentor-slot')->assertDontSee('participant.mentor-interview.book');
    }

    public function test_public_registration_is_pending_until_admin_approval_and_both_appears_in_both_tabs(): void
    {
        $response = $this->post(route('interviews.register.store'), ['full_name' => 'External Reviewer', 'mock_booking_url' => 'https://calendar.app.google/mock-external', 'final_booking_url' => 'https://calendar.app.google/final-external', 'interview_type' => 'both']);
        $response->assertRedirect(route('interviews.register'))->assertSessionHas('status');
        $this->assertDatabaseHas('interview_schedulers', ['full_name' => 'External Reviewer', 'interview_type' => 'both', 'approval_status' => 'pending', 'is_active' => false, 'submitted_publicly' => true]);
        $this->get(route('interviews.index'))->assertDontSee('External Reviewer');

        $admin = User::factory()->create(['role' => 'admin']);
        $request = InterviewScheduler::query()->where('full_name', 'External Reviewer')->firstOrFail();
        $this->assertSame(InterviewerApprovalStatus::Pending, $request->approval_status);
        $this->actingAs($admin)->patch(route('admin.settings.interview-scheduling.approve', $request))->assertRedirect();
        $this->get(route('interviews.index'))->assertSee('External Reviewer');

        $request->refresh();
        $this->assertSame(InterviewerApprovalStatus::Approved, $request->approval_status);
        $this->assertTrue($request->is_active);
        $this->assertSame($admin->id, $request->approved_by);
    }

    public function test_rejected_public_registration_stays_hidden_and_can_be_submitted_again(): void
    {
        $payload = ['full_name' => 'Rejected Reviewer', 'mock_booking_url' => 'https://calendar.app.google/rejected', 'interview_type' => 'mock'];
        $this->post(route('interviews.register.store'), $payload);
        $request = InterviewScheduler::query()->where('full_name', 'Rejected Reviewer')->firstOrFail();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('admin.settings.interview-scheduling.reject', $request))->assertRedirect();

        $request->refresh();
        $this->assertSame(InterviewerApprovalStatus::Rejected, $request->approval_status);
        $this->assertFalse($request->is_active);
        $this->assertNotNull($request->rejected_at);
        $this->get(route('admin.settings.interview-scheduling'))->assertOk()->assertDontSee('Rejected Reviewer');
        $this->get(route('interviews.index'))->assertDontSee('Rejected Reviewer');

        $this->post(route('interviews.register.store'), $payload)->assertSessionHas('status', 'Thank you. Your interviewer information has been submitted for review.');
        $this->assertSame(2, InterviewScheduler::query()->where('full_name', 'Rejected Reviewer')->count());
    }

    public function test_public_registration_rejects_unsafe_urls_and_does_not_create_user(): void
    {
        $before = User::query()->count();
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['full_name' => 'Unsafe', 'mock_booking_url' => 'javascript:alert(1)', 'interview_type' => 'mock'])->assertSessionHasErrors('mock_booking_url');
        $this->assertSame($before, User::query()->count());
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), ['full_name' => 'Invalid Type', 'mock_booking_url' => 'https://calendar.app.google/safe', 'interview_type' => 'other'])->assertSessionHasErrors('interview_type');
    }

    public function test_public_registration_duplicate_is_idempotent(): void
    {
        $payload = ['full_name' => 'Duplicate Reviewer', 'mock_booking_url' => 'https://calendar.app.google/duplicate', 'interview_type' => 'mock'];
        $this->post(route('interviews.register.store'), $payload);
        $this->post(route('interviews.register.store'), $payload);

        $this->assertSame(1, InterviewScheduler::query()->where('full_name', 'Duplicate Reviewer')->count());
    }

    public function test_admin_can_edit_pending_public_request_before_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $request = InterviewScheduler::create([
            'full_name' => 'Pending Reviewer',
            'booking_url' => 'https://calendar.app.google/pending',
            'mock_booking_url' => 'https://calendar.app.google/pending',
            'interview_type' => 'mock',
            'approval_status' => InterviewerApprovalStatus::Pending,
            'submitted_publicly' => true,
            'is_active' => false,
        ]);

        $this->actingAs($admin)->patch(route('admin.settings.interview-scheduling.update', $request), [
            'full_name' => 'Reviewed Interviewer',
            'mock_booking_url' => 'https://calendar.app.google/reviewed',
            'final_booking_url' => 'https://calendar.app.google/reviewed-final',
            'interview_type' => 'both',
            'is_active' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('interview_schedulers', [
            'id' => $request->id,
            'full_name' => 'Reviewed Interviewer',
            'booking_url' => 'https://calendar.app.google/reviewed',
            'interview_type' => 'both',
            'approval_status' => 'pending',
            'is_active' => false,
        ]);
    }
}
