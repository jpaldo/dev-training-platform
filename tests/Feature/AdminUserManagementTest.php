<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_manage_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('User Management');
        $this->actingAs(User::factory()->create(['role' => 'coach']))->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_non_admins_cannot_change_a_users_employee_id(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $participant = User::factory()->create(['employee_id' => 'EMP-OLD']);

        $this->actingAs($coach)
            ->put(route('admin.users.update', $participant), $this->userPayload(['employee_id' => 'EMP-NEW', 'email' => $participant->email, 'access_type' => 'qa_manual']))
            ->assertForbidden();

        $this->assertSame('EMP-OLD', $participant->fresh()->employee_id);
    }

    public function test_admin_can_create_each_access_type_with_existing_role_mapping(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        foreach ([['admin', 'admin', null], ['coach', 'coach', null], ['qa_manual', 'participant', 'manual'], ['qa_automation', 'participant', 'automation'], ['qa_automation_manual', 'participant', 'automation_manual']] as [$access, $role, $path]) {
            $email = $access.'-'.fake()->unique()->safeEmail();
            $this->actingAs($admin)->post(route('admin.users.store'), $this->userPayload(['employee_id' => 'EMP-'.$access, 'email' => $email, 'access_type' => $access]))->assertRedirect(route('admin.users.index'))->assertSessionHas('password_setup_url');
            $this->assertDatabaseHas('users', ['email' => $email, 'employee_id' => 'EMP-'.$access, 'role' => $role, 'training_path' => $path, 'is_active' => 1]);
            $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'user_created']);
        }
    }

    public function test_admin_can_create_a_coach_with_an_employee_id_and_correct_it_later(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->userPayload(['employee_id' => 'EMP-1001', 'email' => 'cora@example.test', 'job_role' => 'project_manager']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $coach = User::query()->where('email', 'cora@example.test')->firstOrFail();
        $this->assertSame('EMP-1001', $coach->employee_id);
        $this->assertSame('Cora', $coach->first_name);
        $this->assertSame('Coach', $coach->last_name);
        $this->assertSame('Cora Coach', $coach->name);
        $this->assertSame(JobRole::ProjectManager, $coach->job_role);
        $this->assertSame(UserRole::Coach, $coach->role);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $coach), $this->userPayload(['employee_id' => 'EMP-1010', 'first_name' => 'Cora', 'last_name' => 'Cruz', 'email' => $coach->email, 'job_role' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $coach->refresh();
        $this->assertSame('EMP-1010', $coach->employee_id);
        $this->assertSame('Cora Cruz', $coach->name);
        $this->assertNull($coach->job_role);
        $audit = AdminAuditLog::query()->where('action', 'user_updated')->sole();
        $this->assertSame(['access_type' => 'coach', 'is_active' => true, 'employee_id_changed' => true], $audit->metadata);
    }

    public function test_saving_a_user_without_changing_the_employee_id_records_that_it_did_not_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null, 'employee_id' => 'EMP-2002']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $coach), $this->userPayload(['employee_id' => 'EMP-2002', 'email' => $coach->email]))
            ->assertSessionHasNoErrors();

        $this->assertSame('EMP-2002', $coach->fresh()->employee_id);
        $this->assertFalse(AdminAuditLog::query()->where('action', 'user_updated')->sole()->metadata['employee_id_changed']);
    }

    public function test_adding_a_user_requires_an_employee_id_and_rejects_an_unknown_job_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->userPayload(['employee_id' => '', 'email' => 'no-id@example.test', 'job_role' => 'designer']))
            ->assertSessionHasErrors(['employee_id' => 'The employee ID field is required.', 'job_role']);

        $this->assertDatabaseMissing('users', ['email' => 'no-id@example.test']);
    }

    public function test_adding_a_user_with_a_taken_employee_id_shows_an_error_and_saves_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        User::factory()->create(['employee_id' => 'EMP-3003']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->userPayload(['employee_id' => 'EMP-3003', 'email' => 'copy@example.test']))
            ->assertSessionHasErrors(['employee_id' => 'The employee ID has already been taken.']);

        $this->assertDatabaseMissing('users', ['email' => 'copy@example.test']);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'user_created']);
    }

    public function test_correcting_an_employee_id_to_a_taken_one_shows_an_error_and_saves_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        User::factory()->create(['employee_id' => 'EMP-4004']);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null, 'employee_id' => 'EMP-4005', 'name' => 'Cora Coach']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $coach), $this->userPayload(['employee_id' => 'EMP-4004', 'first_name' => 'Renamed', 'email' => $coach->email]))
            ->assertSessionHasErrors(['employee_id' => 'The employee ID has already been taken.']);

        $coach->refresh();
        $this->assertSame('EMP-4005', $coach->employee_id);
        $this->assertSame('Cora Coach', $coach->name);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'user_updated']);
    }

    public function test_clearing_an_existing_employee_id_shows_an_error_and_saves_nothing(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null, 'employee_id' => 'EMP-5005', 'name' => 'Cora Coach']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $coach), $this->userPayload(['employee_id' => '', 'first_name' => 'Renamed', 'email' => $coach->email]))
            ->assertSessionHasErrors(['employee_id' => 'The employee ID field is required.']);

        $coach->refresh();
        $this->assertSame('EMP-5005', $coach->employee_id);
        $this->assertSame('Cora Coach', $coach->name);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'user_updated']);
    }

    public function test_a_user_without_an_employee_id_can_still_be_edited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $legacy = User::factory()->create(['role' => 'coach', 'training_path' => null, 'employee_id' => null]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $legacy), $this->userPayload(['employee_id' => '', 'first_name' => 'Lea', 'last_name' => 'Legacy', 'email' => $legacy->email]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $legacy->refresh();
        $this->assertNull($legacy->employee_id);
        $this->assertSame('Lea Legacy', $legacy->name);
    }

    public function test_admin_can_change_access_type_without_deleting_history_and_disable_enable_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $this->actingAs($admin)->put(route('admin.users.update', $participant), $this->userPayload(['employee_id' => $participant->employee_id, 'email' => $participant->email, 'access_type' => 'qa_automation']))->assertRedirect();
        $participant->refresh();
        $this->assertSame(UserRole::Participant, $participant->role);
        $this->assertSame(TrainingPath::Automation, $participant->training_path);
        $this->actingAs($admin)->post(route('admin.users.disable', $participant))->assertRedirect();
        $this->assertFalse($participant->fresh()->is_active);
        $this->assertFalse(Hash::check('PasswordDoesNotMatter', $participant->password));
        $this->actingAs($admin)->post(route('admin.users.enable', $participant))->assertRedirect();
        $this->assertTrue($participant->fresh()->is_active);
    }

    public function test_last_active_admin_cannot_be_disabled_or_demoted(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $this->actingAs($admin)->post(route('admin.users.disable', $admin))->assertSessionHasErrors('user');
        $this->actingAs($admin)->put(route('admin.users.update', $admin), $this->userPayload(['employee_id' => $admin->employee_id, 'email' => $admin->email, 'access_type' => 'coach']))->assertSessionHasErrors('access_type');
    }

    public function test_last_active_admin_can_update_their_own_details_while_keeping_admin_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), $this->userPayload(['employee_id' => $admin->employee_id, 'first_name' => 'Renamed', 'last_name' => 'Admin', 'email' => $admin->email, 'access_type' => 'admin']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $admin->refresh();
        $this->assertSame('Renamed Admin', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
    }

    public function test_last_active_admin_cannot_be_disabled_from_the_edit_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), $this->userPayload(['employee_id' => $admin->employee_id, 'email' => $admin->email, 'access_type' => 'admin', 'is_active' => 0]))
            ->assertSessionHasErrors(['user' => 'The last active admin cannot be disabled.']);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_disabled_users_cannot_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'disabled@example.test', 'is_active' => false]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_admin_can_assign_and_remove_active_coaches_from_a_participant(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $participant = User::factory()->create(['role' => 'participant']);
        $coach = User::factory()->create(['role' => 'coach', 'is_active' => true]);

        $this->actingAs($admin)->get(route('admin.users.edit', $participant))->assertOk()->assertSee('Assigned Coaches');
        $this->actingAs($admin)->post(route('admin.users.assignments', $participant), ['coach_ids' => [$coach->id]])->assertRedirect();
        $this->assertTrue($participant->fresh()->assignedCoaches->contains($coach));
        $this->actingAs($admin)->post(route('admin.users.assignments', $participant), ['coach_ids' => []])->assertRedirect();
        $this->assertCount(0, $participant->fresh()->assignedCoaches);
    }

    public function test_admin_assignment_rejects_inactive_or_non_coach_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $participant = User::factory()->create(['role' => 'participant']);
        $inactiveCoach = User::factory()->create(['role' => 'coach', 'is_active' => false]);
        $otherParticipant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($admin)->post(route('admin.users.assignments', $participant), ['coach_ids' => [$inactiveCoach->id, $otherParticipant->id]])->assertRedirect();
        $this->assertCount(0, $participant->fresh()->assignedCoaches);
    }

    public function test_admin_assignment_returns_not_found_for_non_participant_before_validating_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $coach = User::factory()->create(['role' => 'coach', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.users.assignments', $coach), ['coach_ids' => ['not-a-number']])
            ->assertNotFound();

        $this->assertCount(0, $coach->fresh()->assignedCoaches);
    }

    /**
     * A valid add / edit user form submission for an active Mentor / Coach named Cora Coach, with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(array $overrides): array
    {
        return [
            'first_name' => 'Cora',
            'last_name' => 'Coach',
            'access_type' => 'coach',
            'is_active' => 1,
            ...$overrides,
        ];
    }
}
