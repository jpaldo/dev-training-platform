<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthenticationAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_redirected_from_their_profile(): void
    {
        $this->get(route('profile.show'))
            ->assertRedirect(route('login'));
    }

    public function test_registration_creates_a_participant_without_honoring_a_supplied_privileged_role(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Manual Participant',
            'email' => 'manual@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'training_path' => 'manual',
            'role' => 'admin',
        ])->assertRedirect(route('profile.show'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'manual@example.test',
            'role' => 'participant',
            'training_path' => 'manual',
        ]);
    }

    public function test_authenticated_participants_can_update_only_their_own_profile_fields(): void
    {
        $participant = User::factory()->create(['training_path' => 'manual']);

        $this->actingAs($participant)
            ->patch(route('profile.update'), [
                'name' => 'Updated Participant',
                'email' => 'updated@example.test',
                'training_path' => 'automation',
                'role' => 'admin',
            ])
            ->assertRedirect(route('profile.show'));

        $this->assertDatabaseHas('users', [
            'id' => $participant->id,
            'name' => 'Updated Participant',
            'email' => 'updated@example.test',
            'role' => 'participant',
            'training_path' => 'automation',
        ]);
    }

    public function test_registration_and_profile_updates_reject_an_unknown_training_path(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Unknown Path',
            'email' => 'unknown-path@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'training_path' => 'performance',
        ])->assertSessionHasErrors(['training_path' => 'The selected training path is invalid.']);
        $this->assertGuest();

        $participant = User::factory()->create(['training_path' => 'manual']);
        $this->actingAs($participant)
            ->patch(route('profile.update'), ['name' => $participant->name, 'email' => $participant->email, 'training_path' => 'performance'])
            ->assertSessionHasErrors(['training_path' => 'The selected training path is invalid.']);
        $this->assertDatabaseHas('users', ['id' => $participant->id, 'training_path' => 'manual']);
    }

    public function test_invalid_credentials_are_rejected_without_authenticating_the_user(): void
    {
        $participant = User::factory()->create(['email' => 'participant@example.test']);

        $this->post(route('login.store'), [
            'email' => $participant->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_valid_credentials_start_a_native_platform_session(): void
    {
        $participant = User::factory()->create(['email' => 'signed-in@example.test']);

        $this->post(route('login.store'), [
            'email' => $participant->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($participant);
    }

    public function test_privileged_roles_redirect_to_valid_role_landing_pages(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->post(route('login.store'), ['email' => $coach->email, 'password' => 'password'])
            ->assertRedirect(route('coach.dashboard'));
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_normal_login_ignores_stale_intended_urls_and_uses_the_new_users_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->withSession(['url.intended' => route('admin.settings.index')])
            ->post(route('login.store'), ['email' => $participant->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->withSession(['url.intended' => route('coach.reviews')])
            ->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));

        $this->withSession(['url.intended' => route('admin.dashboard')])
            ->post(route('login.store'), ['email' => $coach->email, 'password' => 'password'])
            ->assertRedirect(route('coach.dashboard'));
    }

    public function test_logged_out_protected_route_does_not_redirect_a_different_role_into_it(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $participant->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_participants_cannot_view_another_participants_training_records(): void
    {
        $participant = User::factory()->create();
        $otherParticipant = User::factory()->create();
        $submission = ActivitySubmission::factory()->for($otherParticipant, 'participant')->create();
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create();
        $evidence = SubmissionEvidence::factory()->for($version)->create();
        $dailyLog = DailyLog::factory()->for($otherParticipant, 'participant')->for(Activity::factory())->create();
        $gateEvaluation = GateEvaluation::factory()->for($otherParticipant, 'participant')->create();
        $review = SubmissionReview::factory()->for($version)->create();

        $this->assertFalse($participant->can('view', $submission));
        $this->assertFalse($participant->can('view', $version));
        $this->assertFalse($participant->can('view', $evidence));
        $this->assertFalse($participant->can('view', $dailyLog));
        $this->assertFalse($participant->can('view', $gateEvaluation));
        $this->assertFalse($participant->can('view', $review));
    }

    public function test_coaches_and_admins_can_review_training_records_while_participants_cannot_modify_gate_results(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $coach->assignedParticipants()->attach($participant->id);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create();
        $gateEvaluation = GateEvaluation::factory()->for(TrainingGate::factory(), 'gate')->for($participant, 'participant')->create();

        $this->assertTrue($coach->can('view', $participant));
        $this->assertFalse($coach->can('update', $participant));
        $this->assertTrue($coach->can('view', $submission));
        $this->assertTrue($coach->can('update', $gateEvaluation));
        $this->assertTrue($admin->can('update', $participant));
        $this->assertTrue($admin->can('view', $submission));
        $this->assertFalse($participant->can('update', $gateEvaluation));
    }

    public function test_existing_qa_validation_lab_otp_exercise_remains_available_without_platform_authentication(): void
    {
        $this->get(route('lab.login'))->assertOk();

        $this->post(route('lab.send-otp'))->assertRedirect(route('lab.otp'));

        $this->post(route('lab.verify-otp'), ['otp' => '248163'])
            ->assertOk()
            ->assertSee('Authenticated');
    }
}
