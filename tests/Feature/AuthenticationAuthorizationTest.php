<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TypeError;
use ValueError;

class AuthenticationAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_redirected_from_their_profile(): void
    {
        $this->get(route('profile.show'))
            ->assertRedirect(route('login'));
    }

    public function test_registration_creates_a_participant_with_their_identity_without_honoring_a_supplied_privileged_role(): void
    {
        $this->post(route('register.store'), [
            ...$this->registrationFields(),
            'role' => 'admin',
        ])->assertRedirect(route('profile.show'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'manual@example.test',
            'employee_id' => 'FS-2001',
            'first_name' => 'Ana Maria',
            'last_name' => 'Cruz',
            'name' => 'Ana Maria Cruz',
            'job_role' => 'qa_tester',
            'role' => 'participant',
            'training_path' => 'manual',
        ]);
    }

    public function test_registration_with_a_taken_employee_id_creates_nothing(): void
    {
        User::factory()->create(['employee_id' => 'FS-2001']);

        $this->post(route('register.store'), $this->registrationFields())
            ->assertSessionHasErrors(['employee_id' => 'The employee ID has already been taken.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'manual@example.test']);
    }

    /**
     * @param  array<string, string>  $fieldOverrides
     */
    #[DataProvider('missingOrInvalidIdentityFields')]
    public function test_registration_requires_a_valid_employee_id_and_job_role(array $fieldOverrides, string $field, string $error): void
    {
        $this->post(route('register.store'), [...$this->registrationFields(), ...$fieldOverrides])
            ->assertSessionHasErrors([$field => $error]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'manual@example.test']);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function missingOrInvalidIdentityFields(): array
    {
        return [
            'missing employee ID' => [['employee_id' => ''], 'employee_id', 'The employee ID field is required.'],
            'missing job role' => [['job_role' => ''], 'job_role', 'The job role field is required.'],
            'invalid job role' => [['job_role' => 'designer'], 'job_role', 'The selected job role is invalid.'],
        ];
    }

    public function test_authenticated_participants_can_update_only_their_own_profile_fields(): void
    {
        $participant = User::factory()->create(['training_path' => 'manual']);

        $this->actingAs($participant)
            ->patch(route('profile.update'), [
                'first_name' => 'Updated',
                'last_name' => 'Participant',
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

    public function test_only_participants_must_keep_a_training_path_on_their_profile(): void
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);

        $this->actingAs($participant)
            ->patch(route('profile.update'), ['first_name' => $participant->first_name, 'last_name' => $participant->last_name, 'email' => $participant->email])
            ->assertSessionHasErrors(['training_path' => 'The training path field is required.']);
        $this->assertDatabaseHas('users', ['id' => $participant->id, 'training_path' => 'manual']);

        $this->actingAs($coach)
            ->patch(route('profile.update'), ['first_name' => 'Updated', 'last_name' => 'Coach', 'email' => $coach->email])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $coach->id, 'name' => 'Updated Coach', 'training_path' => null]);
    }

    public function test_registration_and_profile_updates_reject_an_unknown_training_path(): void
    {
        $this->post(route('register.store'), [...$this->registrationFields(), 'training_path' => 'performance'])
            ->assertSessionHasErrors(['training_path' => 'The selected training path is invalid.']);
        $this->assertGuest();

        $participant = User::factory()->create(['training_path' => 'manual']);
        $this->actingAs($participant)
            ->patch(route('profile.update'), ['first_name' => $participant->first_name, 'last_name' => $participant->last_name, 'email' => $participant->email, 'training_path' => 'performance'])
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

    public function test_a_user_cannot_hold_a_role_outside_participant_coach_and_admin(): void
    {
        $this->assertThrows(fn (): User => User::factory()->create(['email' => 'guest@example.test', 'role' => 'guest']), ValueError::class);

        $this->assertDatabaseMissing('users', ['email' => 'guest@example.test']);
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
        $gateEvaluation = GateEvaluation::factory()->for($otherParticipant, 'participant')->create();
        $review = SubmissionReview::factory()->for($version)->create();

        $this->assertFalse($participant->can('view', $submission));
        $this->assertFalse($participant->can('view', $version));
        $this->assertFalse($participant->can('view', $evidence));
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

    public function test_only_participants_can_create_training_records(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        foreach ([ActivitySubmission::class, SubmissionVersion::class, SubmissionEvidence::class] as $record) {
            $this->assertTrue($participant->can('create', $record), $record);
            $this->assertFalse($coach->can('create', $record), $record);
            $this->assertFalse($admin->can('create', $record), $record);
        }
    }

    public function test_only_admins_can_create_users_or_update_another_user(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('update', $coach));
        $this->assertFalse($coach->can('create', User::class));
        $this->assertFalse($participant->can('create', User::class));
        $this->assertFalse($participant->can('update', $coach));
        $this->assertTrue($participant->can('update', $participant));
    }

    public function test_role_helpers_accept_only_role_cases(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->assertTrue($coach->hasAnyRole(UserRole::Coach));
        $this->assertTrue($coach->hasAnyRole(UserRole::Participant, UserRole::Coach));
        $this->assertFalse($participant->hasAnyRole(UserRole::Coach, UserRole::Admin));
        $this->assertFalse($admin->hasAnyRole());
        $this->assertThrows(fn (): bool => $admin->hasAnyRole('admin'), TypeError::class);
        $this->assertFalse($participant->isCoachOrAdmin());
        $this->assertTrue($coach->isCoachOrAdmin());
        $this->assertTrue($admin->isCoachOrAdmin());
    }

    public function test_every_role_middleware_parameter_on_a_route_is_a_user_role(): void
    {
        $roleParameters = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (IlluminateRoute $route): array => $route->gatherMiddleware())
            ->filter(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'))
            ->flatMap(fn (string $middleware): array => explode(',', substr($middleware, strlen('role:'))))
            ->unique()
            ->values()
            ->all();

        $this->assertNotEmpty($roleParameters);
        foreach ($roleParameters as $role) {
            $this->assertNotNull(UserRole::tryFrom($role), "The route middleware role:{$role} is not a user role.");
        }
    }

    public function test_role_middleware_fails_loudly_for_an_unknown_or_missing_role(): void
    {
        Route::middleware(['web', 'auth', 'role:admin,superuser'])->get('/testing/unknown-role', fn (): string => 'reached');
        Route::middleware(['web', 'auth', 'role'])->get('/testing/missing-role', fn (): string => 'reached');
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->withoutExceptionHandling();

        $this->assertThrows(
            fn (): TestResponse => $this->actingAs($admin)->get('/testing/unknown-role'),
            ValueError::class,
            '"superuser" is not a valid backing value',
        );
        $this->assertThrows(
            fn (): TestResponse => $this->actingAs($admin)->get('/testing/missing-role'),
            InvalidArgumentException::class,
            'The role middleware needs at least one role.',
        );
    }

    public function test_only_admins_and_assigned_coaches_manage_a_participant(): void
    {
        $participant = User::factory()->create();
        $otherParticipant = User::factory()->create();
        $assignedCoach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $otherCoach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $assignedCoach->assignedParticipants()->attach($participant->id);

        $this->assertTrue($admin->canManageParticipant($participant));
        $this->assertTrue($assignedCoach->canManageParticipant($participant));
        $this->assertTrue($assignedCoach->canManageParticipant($participant->load('assignedCoaches')));
        $this->assertFalse($otherCoach->canManageParticipant($participant));
        $this->assertFalse($assignedCoach->canManageParticipant($otherCoach));
        $this->assertFalse($otherParticipant->canManageParticipant($participant));
    }

    public function test_validation_lab_pages_are_gone(): void
    {
        $this->get('/lab/login')->assertNotFound();
        $this->get('/checklist')->assertNotFound();
        $this->get('/result/1')->assertNotFound();
    }

    public function test_daily_log_pages_are_gone(): void
    {
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);

        $this->actingAs($participant)->get('/daily-logs')->assertNotFound();
        $this->actingAs($coach)->get('/coach/reports/daily-logs')->assertNotFound();
        $this->actingAs($coach)->get('/coach/participants/'.$participant->id.'/daily-logs')->assertNotFound();
    }

    public function test_week_three_workspace_route_is_gone(): void
    {
        $participant = User::factory()->create();
        $week = TrainingWeek::factory()->create(['week_number' => 3]);

        $this->actingAs($participant)->post('/training/weeks/'.$week->id.'/week-three/workspace', ['findings' => []])->assertNotFound();
    }

    public function test_week_four_peer_interview_routes_are_gone(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);

        $this->actingAs($coach)->get('/coach/week-four/interview-questionnaire')->assertNotFound();
        $this->actingAs($coach)->post('/coach/week-four/interview-pairs', ['participant_one_id' => $participant->id, 'participant_two_id' => $coach->id])->assertNotFound();
        $this->actingAs($coach)->post('/coach/week-four/participants/'.$participant->id.'/peer-waiver')->assertNotFound();
        $this->actingAs($participant)->post('/training/week-four/partner-feedback', ['question_number' => 1, 'content' => 'Clear answer.'])->assertNotFound();
    }

    public function test_mentor_interview_booking_and_calendar_routes_are_gone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Activity::factory()->for(TrainingWeek::factory()->state(['week_number' => 4]), 'week')->create(['position' => 2]);
        $scheduleUrl = '/training/weeks/'.$activity->training_week_id.'/activities/'.$activity->id.'/mentor-interview/schedule';

        $this->actingAs($participant)->post($scheduleUrl, ['scheduled_at' => '2026-11-10T09:00'])->assertNotFound();
        $this->actingAs($participant)->put($scheduleUrl, ['scheduled_at' => '2026-11-10T10:00'])->assertNotFound();
        $this->actingAs($participant)->post('/training/mentor-interviews/1/cancel')->assertNotFound();
        $this->actingAs($coach)->post('/training/coach/mentor-interviews/1/cancel')->assertNotFound();
        $this->actingAs($coach)->get('/coach/mentor-availability')->assertNotFound();
        $this->actingAs($coach)->get('/coach/mentor-booking-calendar')->assertNotFound();
        $this->actingAs($coach)->post('/coach/mentor-booking-calendar', ['booking_url' => 'https://calendar.app.google/mock'])->assertNotFound();
        $this->actingAs($coach)->delete('/coach/mentor-booking-calendar')->assertNotFound();
        $this->actingAs($admin)->get('/admin/settings/google/verification/connect')->assertNotFound();
        $this->actingAs($admin)->get('/admin/settings/google/verification/callback?state=state&code=code')->assertNotFound();
    }

    public function test_reusable_asset_library_routes_are_gone(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get('/training/reusable-assets')->assertNotFound();
        $this->actingAs($participant)->get('/training/reusable-assets/1/download')->assertNotFound();
    }

    public function test_training_guide_routes_are_gone(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($participant)->get('/training/guides/2')->assertNotFound();
        $this->actingAs($participant)->get('/training/guides/2/download')->assertNotFound();
    }

    /**
     * A complete, valid self-registration.
     *
     * @return array<string, string>
     */
    private function registrationFields(): array
    {
        return [
            'employee_id' => 'FS-2001',
            'first_name' => 'Ana Maria',
            'last_name' => 'Cruz',
            'email' => 'manual@example.test',
            'job_role' => 'qa_tester',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'training_path' => 'manual',
        ];
    }
}
