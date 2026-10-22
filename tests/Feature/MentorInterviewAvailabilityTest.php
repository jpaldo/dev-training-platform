<?php

namespace Tests\Feature;

use App\Enums\MentorInterviewAvailabilityStatus;
use App\Models\GoogleDriveConnection;
use App\Models\MentorInterviewAvailability;
use App\Models\Program;
use App\Models\User;
use App\Services\GoogleDriveConfigurationService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorInterviewAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mentor_can_open_availability_and_participant_sees_only_assigned_mentor_slots(): void
    {
        [$participant, $mentor] = $this->fixture();
        $other = User::factory()->create(['role' => 'coach', 'is_active' => true]);
        $date = now('Asia/Manila')->addDays(3)->toDateString();
        $availability = MentorInterviewAvailability::create(['mentor_id' => $mentor->id, 'available_date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'slot_duration_minutes' => 60, 'capacity' => 2, 'status' => 'open']);
        MentorInterviewAvailability::create(['mentor_id' => $other->id, 'available_date' => $date, 'start_time' => '13:00', 'end_time' => '14:00', 'slot_duration_minutes' => 60, 'capacity' => 1, 'status' => 'open']);

        $this->actingAs($participant)->get(route('participant.mentor-interview.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($participant)->get(route('dashboard'))->assertOk()->assertDontSee('dashboard-mentor-slot');
    }

    public function test_legacy_local_slot_booking_is_replaced_by_external_calendar_link(): void
    {
        [$participant, $mentor] = $this->fixture();
        $date = now('Asia/Manila')->addDays(3)->toDateString();
        $availability = MentorInterviewAvailability::create(['mentor_id' => $mentor->id, 'available_date' => $date, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 60, 'capacity' => 1, 'status' => 'open']);
        $this->actingAs($participant)->post(route('participant.mentor-interview.book'), ['slot' => $availability->id.'|'.$date.'T09:00'])->assertRedirect(route('dashboard'))->assertSessionHasErrors('mentor_interview');
        $this->assertDatabaseMissing('week_four_mentor_interview_schedules', ['participant_id' => $participant->id]);
    }

    public function test_capacity_is_enforced_server_side(): void
    {
        [$participant, $mentor] = $this->fixture();
        $second = User::factory()->create(['role' => 'participant']);
        $second->assignedCoaches()->attach($mentor->id);
        GoogleDriveConnection::create(['user_id' => $second->id, 'google_account_id' => 'account-2', 'google_email' => $second->email, 'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now()]);
        $date = now('Asia/Manila')->addDays(3)->toDateString();
        $availability = MentorInterviewAvailability::create(['mentor_id' => $mentor->id, 'available_date' => $date, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 60, 'capacity' => 1, 'status' => 'open']);
        $payload = ['availability_id' => $availability->id, 'slot_start' => $date.'T09:00'];
        $this->actingAs($participant)->post(route('participant.mentor-interview.book'), $payload)->assertRedirect()->assertSessionHasErrors('mentor_interview');
        $this->actingAs($second)->post(route('participant.mentor-interview.book'), $payload)->assertRedirect()->assertSessionHasErrors('mentor_interview');
    }

    public function test_mentor_can_manage_own_availability_but_participant_cannot(): void
    {
        [$participant, $mentor] = $this->fixture();
        $payload = ['available_date' => now('Asia/Manila')->addDays(4)->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 60, 'capacity' => 1];
        $this->actingAs($mentor)->post(route('coach.mentor-availability.store'), $payload)->assertRedirect();
        $this->actingAs($participant)->post(route('coach.mentor-availability.store'), $payload)->assertForbidden();
    }

    public function test_admin_adds_availability_for_the_selected_mentor(): void
    {
        [, $mentor] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('coach.mentor-availability.store'), $this->availabilityPayload() + ['mentor_id' => $mentor->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('mentor_interview_availabilities', ['mentor_id' => $mentor->id, 'start_time' => '09:00', 'capacity' => 1]);
    }

    public function test_admin_cannot_add_availability_for_a_user_who_is_not_a_coach(): void
    {
        [$participant] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('coach.mentor-availability.store'), $this->availabilityPayload() + ['mentor_id' => $participant->id])
            ->assertNotFound();

        $this->assertDatabaseCount('mentor_interview_availabilities', 0);
    }

    public function test_admin_can_update_another_mentors_availability(): void
    {
        [, $mentor] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $availability = MentorInterviewAvailability::create($this->availabilityPayload() + ['mentor_id' => $mentor->id, 'status' => MentorInterviewAvailabilityStatus::Open]);

        $this->actingAs($admin)
            ->patch(route('coach.mentor-availability.update', $availability), ['end_time' => '11:00', 'capacity' => 2] + $this->availabilityPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $availability->fresh()->capacity);
    }

    public function test_mentor_can_update_own_availability_but_not_another_mentors(): void
    {
        [, $mentor] = $this->fixture();
        $otherMentor = User::factory()->create(['role' => 'coach', 'is_active' => true]);
        $availability = MentorInterviewAvailability::create($this->availabilityPayload() + ['mentor_id' => $mentor->id, 'status' => MentorInterviewAvailabilityStatus::Open]);
        $update = ['end_time' => '11:00', 'capacity' => 2] + $this->availabilityPayload();

        $this->actingAs($otherMentor)
            ->patch(route('coach.mentor-availability.update', $availability), $update)
            ->assertForbidden();
        $this->assertSame(1, $availability->fresh()->capacity);

        $this->actingAs($mentor)
            ->patch(route('coach.mentor-availability.update', $availability), $update)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $availability->fresh()->capacity);
    }

    public function test_availability_update_validates_input_before_checking_ownership(): void
    {
        [, $mentor] = $this->fixture();
        $otherMentor = User::factory()->create(['role' => 'coach', 'is_active' => true]);
        $availability = MentorInterviewAvailability::create($this->availabilityPayload() + ['mentor_id' => $mentor->id, 'status' => MentorInterviewAvailabilityStatus::Open]);

        $this->actingAs($otherMentor)
            ->patch(route('coach.mentor-availability.update', $availability), ['start_time' => '9am', 'capacity' => 21] + $this->availabilityPayload())
            ->assertRedirect()
            ->assertSessionHasErrors(['start_time', 'capacity']);
    }

    public function test_closing_availability_frees_its_window_for_new_availability(): void
    {
        [, $mentor] = $this->fixture();

        $this->actingAs($mentor)
            ->post(route('coach.mentor-availability.store'), $this->availabilityPayload())
            ->assertSessionHasNoErrors();
        $availability = MentorInterviewAvailability::query()->where('mentor_id', $mentor->id)->sole();
        $this->assertSame(MentorInterviewAvailabilityStatus::Open, $availability->status);

        $this->actingAs($mentor)
            ->post(route('coach.mentor-availability.store'), $this->availabilityPayload())
            ->assertSessionHasErrors('start_time');

        $this->actingAs($mentor)
            ->patch(route('coach.mentor-availability.close', $availability))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(MentorInterviewAvailabilityStatus::Closed, $availability->fresh()->status);

        $this->actingAs($mentor)
            ->post(route('coach.mentor-availability.store'), $this->availabilityPayload())
            ->assertSessionHasNoErrors();
        $this->assertSame(2, MentorInterviewAvailability::query()->where('mentor_id', $mentor->id)->count());
    }

    public function test_calendar_disabled_does_not_hide_coach_availability_management_and_admin_can_inspect(): void
    {
        [$participant, $mentor] = $this->fixture();
        app(GoogleDriveConfigurationService::class)->saveParticipantCalendarAvailability(false);
        $this->actingAs($mentor)->get(route('coach.mentor-booking-calendar.index'))->assertOk()->assertSee('MENTOR BOOKING CALENDAR');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('coach.mentor-booking-calendar.index'))->assertOk()->assertSee('MENTOR BOOKING CALENDAR');
    }

    /** @return array{available_date: string, start_time: string, end_time: string, slot_duration_minutes: int, capacity: int} */
    private function availabilityPayload(): array
    {
        return ['available_date' => now('Asia/Manila')->addDays(4)->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 60, 'capacity' => 1];
    }

    /** @return array{0:User,1:User} */
    private function fixture(): array
    {
        app(GoogleDriveConfigurationService::class)->saveParticipantCalendarAvailability(true);
        $this->seed(QaTrainingProgramSeeder::class);
        Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $mentor = User::factory()->create(['role' => 'coach', 'is_active' => true]);
        $participant->assignedCoaches()->attach($mentor->id);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => $participant->email, 'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now()]);
        GoogleDriveConnection::create(['user_id' => $mentor->id, 'google_account_id' => 'mentor-account', 'google_email' => $mentor->email, 'access_token' => 'mentor-access', 'refresh_token' => 'mentor-refresh', 'token_expires_at' => now()->addHour(), 'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.events'], 'connected_at' => now()]);

        return [$participant, $mentor];
    }
}
