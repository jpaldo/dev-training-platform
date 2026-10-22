<?php

namespace Tests\Feature;

use App\Enums\InterviewerApprovalStatus;
use App\Enums\InterviewType;
use App\Models\ApplicationSetting;
use App\Models\InterviewScheduler;
use App\Services\InterviewBookingLinkService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicInterviewPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_scheduling_page_lists_approved_active_interviewers_per_tab_with_their_booking_links(): void
    {
        $this->interviewer('Zed Mentor', InterviewType::Mock, displayOrder: 1, mockUrl: 'https://calendar.app.google/zed-mock');
        $this->interviewer('Amy Mentor', InterviewType::Mock, displayOrder: 1, mockUrl: 'https://calendar.app.google/amy-mock');
        $this->interviewer('Bea Both', InterviewType::Both, displayOrder: 0, finalUrl: 'https://calendar.app.google/bea-final', sharedUrl: 'https://calendar.app.google/bea-shared');
        $this->interviewer('Pending Person', InterviewType::Mock, approvalStatus: InterviewerApprovalStatus::Pending);
        $this->interviewer('Inactive Person', InterviewType::FinalReassessment, isActive: false);

        $response = $this->get(route('interviews.index'))
            ->assertOk()
            ->assertSee('<title>Developer Training Platform | Interview Scheduling</title>', false)
            ->assertSeeInOrder([
                '<header',
                '>Developer Training Platform</div>',
                '</header>',
                '<main',
                '>Schedule your interview with our Mentors</h1>',
                'Choose the interview type and select an available interviewer to schedule your session.',
                'href="'.route('interviews.register').'"',
                '>Are you an interviewer? Add Your Booking Calendar</a>',
                'role="tablist" aria-label="Interview type"',
                'id="interview-tab-mock" aria-controls="interview-panel-mock" aria-selected="true"',
                'Mock Interview',
                'id="interview-tab-final" aria-controls="interview-panel-final" aria-selected="false"',
                'Final Re-assessment Interview',
                'id="interview-panel-mock" aria-labelledby="interview-tab-mock" tabindex="0" >',
                '>Bea Both</h2>',
                'Mentor / Interviewer',
                '<a href="https://calendar.app.google/bea-shared" target="_blank" rel="noopener noreferrer"',
                '>Schedule Mock Interview</a>',
                '>Amy Mentor</h2>',
                '<a href="https://calendar.app.google/amy-mock" target="_blank" rel="noopener noreferrer"',
                '>Zed Mentor</h2>',
                '<a href="https://calendar.app.google/zed-mock" target="_blank" rel="noopener noreferrer"',
                'id="interview-panel-final" aria-labelledby="interview-tab-final" tabindex="0"',
                'hidden >',
                '>Bea Both</h2>',
                'Final Re-assessment Interviewer',
                '<a href="https://calendar.app.google/bea-final" target="_blank" rel="noopener noreferrer"',
                '>Schedule Final Re-assessment</a>',
                '</main>',
            ], false)
            ->assertDontSee('Pending Person')
            ->assertDontSee('Inactive Person')
            ->assertDontSee('<img', false)
            ->assertDontSee('No Mock Interview schedules are available yet.');

        $content = $response->getContent();
        $this->assertSame(3, substr_count($content, '>Schedule Mock Interview</a>'));
        $this->assertSame(1, substr_count($content, '>Schedule Final Re-assessment</a>'));
        $this->assertStandaloneAppStyledPage($response);
    }

    public function test_scheduling_page_shows_the_configured_logo_and_an_empty_state_per_tab(): void
    {
        ApplicationSetting::query()->create(['key' => InterviewBookingLinkService::TITLE_KEY, 'value' => 'Full Scale AI Training', 'is_encrypted' => false]);
        ApplicationSetting::query()->create(['key' => InterviewBookingLinkService::LOGO_KEY, 'value' => 'public-interview/logo.png', 'is_encrypted' => false]);

        $this->get(route('interviews.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<header',
                '<img src="'.route('interviews.logo').'" alt="Full Scale AI Training logo"',
                '</header>',
                'id="interview-panel-mock"',
                'No Mock Interview schedules are available yet.',
                'id="interview-panel-final"',
                'No Final Re-assessment schedules are available yet.',
            ], false)
            ->assertDontSee('>Full Scale AI Training</div>', false)
            ->assertDontSee('<h2', false);
    }

    public function test_registration_page_shows_the_steps_and_the_form_with_conditional_booking_link_fields(): void
    {
        $response = $this->get(route('interviews.register'))
            ->assertOk()
            ->assertSee('<title>Developer Training Platform | Become an Interviewer</title>', false)
            ->assertSeeInOrder([
                '<main',
                '>Become an Interviewer</h1>',
                '>Developer Training Platform</p>',
                'Add your booking calendar so participants can schedule an interview with you.',
                '>1. Configure your booking calendar</h2>',
                'Create or manage your appointment schedule using your preferred scheduling service.',
                '<a href="https://calendar.google.com/calendar/u/0/r/appointment" target="_blank" rel="noopener noreferrer"',
                '>Configure with Google Calendar</a>',
                'You may use Google Calendar, Calendly, Microsoft Bookings, Cal.com, or another external scheduling service.',
                '>2. Copy your public booking link</h2>',
                'After creating your appointment schedule, copy the public booking page link provided by your scheduling service.',
                '>3. Submit your information</h2>',
                '<form method="POST" action="'.route('interviews.register.store').'" data-interview-registration-form',
                'name="_token"',
                '<label for="full_name"',
                '>Full Name *</label>',
                '<input id="full_name" name="full_name" value="" required maxlength="255"',
                '<label for="interview_type"',
                '>Interview Type *</label>',
                '<select id="interview_type" name="interview_type" required',
                '<option value="">Choose an interview type</option>',
                '<option value="mock" >Mock Interview</option>',
                '<option value="final_reassessment" >Final Re-assessment Interview</option>',
                '<option value="both" >Both</option>',
                '<div id="mock-booking-field" data-mock-booking-field hidden>',
                '>Mock Interview Booking Link *</label>',
                '<input id="mock_booking_url" name="mock_booking_url" type="url" value="" placeholder="https://booking.example.com/mock" aria-describedby="mock_booking_url-hint"',
                'id="mock_booking_url-hint"',
                'Paste the public booking page link participants should use to schedule this interview.',
                '<div id="final-booking-field" data-final-booking-field hidden>',
                '>Final Re-assessment Booking Link *</label>',
                '<input id="final_booking_url" name="final_booking_url" type="url" value="" placeholder="https://booking.example.com/final" aria-describedby="final_booking_url-hint"',
                'id="final_booking_url-hint"',
                '<button type="submit"',
                '>Submit Interviewer Information</button>',
                '</form>',
                '<a href="'.route('interviews.index').'"',
                '>Return to interview scheduling</a>',
                '</main>',
            ], false)
            ->assertDontSee('role="status"', false)
            ->assertDontSee('role="alert"', false);

        $this->assertStandaloneAppStyledPage($response);
    }

    public function test_successful_registration_returns_to_the_page_with_the_status_message(): void
    {
        $this->post(route('interviews.register.store'), [
            'full_name' => 'Ada Interviewer',
            'interview_type' => 'mock',
            'mock_booking_url' => 'https://calendar.app.google/ada-mock',
        ])->assertRedirect(route('interviews.register'));

        $this->get(route('interviews.register'))
            ->assertOk()
            ->assertSeeInOrder([
                '>Become an Interviewer</h1>',
                'role="status"',
                'Thank you. Your interviewer information has been submitted for review.',
                'Once approved, your booking calendar will appear on the interview scheduling page.',
                '>1. Configure your booking calendar</h2>',
            ], false)
            ->assertDontSee('role="alert"', false);
    }

    public function test_rejected_registration_shows_the_first_error_and_keeps_the_old_input(): void
    {
        $this->from(route('interviews.register'))->post(route('interviews.register.store'), [
            'full_name' => 'Ada Interviewer',
            'interview_type' => 'both',
            'mock_booking_url' => 'https://calendar.app.google/ada-mock',
        ])->assertRedirect(route('interviews.register'));

        $this->get(route('interviews.register'))
            ->assertOk()
            ->assertSeeInOrder([
                '>Become an Interviewer</h1>',
                'role="alert">The final booking url field is required.</div>',
                '>1. Configure your booking calendar</h2>',
                'name="full_name" value="Ada Interviewer"',
                '<option value="both" selected>Both</option>',
                'name="mock_booking_url" type="url" value="https://calendar.app.google/ada-mock"',
                'name="final_booking_url" type="url" value=""',
            ], false)
            ->assertDontSee('role="status"', false);
    }

    /**
     * The page is a standalone document (no app layout) that loads the Vite `app.css` and has no inline styles or scripts.
     */
    private function assertStandaloneAppStyledPage(TestResponse $response): void
    {
        $content = $response->getContent();

        $this->assertStringStartsWith('<!doctype html>', ltrim($content));
        $this->assertStringContainsString('href="'.app(Vite::class)->asset('resources/css/app.css').'"', $content);
        $this->assertStringNotContainsString('<style', $content);
        $this->assertStringNotContainsString('style="', $content);
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('app-sidebar', $content);
        $this->assertStringNotContainsString('DEVELOPER TRAINING PLATFORM', $content);
    }

    private function interviewer(
        string $fullName,
        InterviewType $interviewType,
        int $displayOrder = 0,
        ?string $mockUrl = null,
        ?string $finalUrl = null,
        ?string $sharedUrl = null,
        InterviewerApprovalStatus $approvalStatus = InterviewerApprovalStatus::Approved,
        bool $isActive = true,
    ): InterviewScheduler {
        return InterviewScheduler::query()->create([
            'interview_type' => $interviewType,
            'full_name' => $fullName,
            'booking_url' => $sharedUrl ?? $mockUrl ?? $finalUrl ?? 'https://calendar.app.google/shared',
            'mock_booking_url' => $mockUrl,
            'final_booking_url' => $finalUrl,
            'is_active' => $isActive,
            'display_order' => $displayOrder,
            'approval_status' => $approvalStatus,
        ]);
    }
}
