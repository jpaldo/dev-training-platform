<?php

namespace Tests\Feature;

use App\Models\InterviewScheduler;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MentorBookingCalendarPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mentor_without_a_booking_link_sees_the_steps_and_an_empty_form(): void
    {
        $mentor = User::factory()->create(['role' => 'coach']);

        $this->actingAs($mentor)->get(route('coach.mentor-booking-calendar.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'MENTOR BOOKING CALENDAR',
                '>Mentor Mock Interview Booking</h1>',
                'form="mentor-booking-form"', '>Save Booking Link</button>',
                '>Optional</h2>',
                'href="https://calendar.google.com/calendar/u/0/r/appointment" target="_blank" rel="noopener noreferrer"', '>Configure with Google Calendar</a>',
                '>Copy your public booking page link</h2>',
                '>Step 3</h2>',
                'id="mentor-booking-form" method="POST" action="'.route('coach.mentor-booking-calendar.store').'"',
                'id="booking_url" name="booking_url" type="url" value=""',
            ], false)
            ->assertDontSee('Remove Link')
            ->assertDontSee('remove-booking-link', false)
            ->assertDontSee('Status: Booking Link Configured');
    }

    public function test_mentor_with_a_booking_link_sees_it_with_the_remove_form_and_status(): void
    {
        $mentor = User::factory()->create(['role' => 'coach']);
        $this->bookingFor($mentor, 'https://booking.example.com/general', 'https://booking.example.com/mock');

        $this->actingAs($mentor)->get(route('coach.mentor-booking-calendar.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'name="booking_url" type="url" value="https://booking.example.com/mock"',
                'form="remove-booking-link"', '>Remove Link</button>',
                'id="remove-booking-link" method="POST" action="'.route('coach.mentor-booking-calendar.destroy').'"',
                'name="_method" value="DELETE"',
                'Status: Booking Link Configured',
                'href="https://booking.example.com/mock" target="_blank" rel="noopener noreferrer"', '>Open Booking Page</a>',
            ], false);
    }

    public function test_booking_link_falls_back_to_the_general_link_without_a_mock_link(): void
    {
        $mentor = User::factory()->create(['role' => 'coach']);
        $this->bookingFor($mentor, 'https://booking.example.com/general', null);

        $this->actingAs($mentor)->get(route('coach.mentor-booking-calendar.index'))
            ->assertOk()
            ->assertSee('value="https://booking.example.com/general"', false)
            ->assertSee('href="https://booking.example.com/general"', false);
    }

    public function test_mentor_does_not_see_another_mentors_link_or_a_final_reassessment_link(): void
    {
        $mentor = User::factory()->create(['role' => 'coach']);
        $this->bookingFor(User::factory()->create(['role' => 'coach']), 'https://booking.example.com/other', 'https://booking.example.com/other-mock');
        InterviewScheduler::create(['interview_type' => InterviewScheduler::FINAL_REASSESSMENT, 'user_id' => $mentor->id, 'full_name' => $mentor->name, 'booking_url' => 'https://booking.example.com/final', 'is_active' => true]);

        $this->actingAs($mentor)->get(route('coach.mentor-booking-calendar.index'))
            ->assertOk()
            ->assertSee('id="booking_url" name="booking_url" type="url" value=""', false)
            ->assertDontSee('booking.example.com/other')
            ->assertDontSee('booking.example.com/final')
            ->assertDontSee('Status: Booking Link Configured');
    }

    public function test_rejected_link_is_redisplayed_after_a_failed_save(): void
    {
        $mentor = User::factory()->create(['role' => 'coach']);
        $this->bookingFor($mentor, 'https://booking.example.com/general', 'https://booking.example.com/mock');

        $this->actingAs($mentor)
            ->from(route('coach.mentor-availability.index'))
            ->followingRedirects()
            ->post(route('coach.mentor-booking-calendar.store'), ['booking_url' => 'http://booking.example.com/insecure'])
            ->assertOk()
            ->assertSee('value="http://booking.example.com/insecure"', false)
            ->assertSee('href="https://booking.example.com/mock"', false);
    }

    private function bookingFor(User $mentor, string $bookingUrl, ?string $mockBookingUrl): InterviewScheduler
    {
        return InterviewScheduler::create([
            'interview_type' => InterviewScheduler::MOCK,
            'user_id' => $mentor->id,
            'full_name' => $mentor->name,
            'email' => $mentor->email,
            'booking_url' => $bookingUrl,
            'mock_booking_url' => $mockBookingUrl,
            'is_active' => true,
        ]);
    }
}
