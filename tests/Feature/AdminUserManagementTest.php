<?php

namespace Tests\Feature;

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

    public function test_admin_can_create_each_access_type_with_existing_role_mapping(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        foreach ([['admin', 'admin', null], ['coach', 'coach', null], ['qa_manual', 'participant', 'manual'], ['qa_automation', 'participant', 'automation'], ['qa_automation_manual', 'participant', 'automation_manual']] as [$access, $role, $path]) {
            $email = $access.'-'.fake()->unique()->safeEmail();
            $this->actingAs($admin)->post(route('admin.users.store'), ['name' => ucfirst($access), 'email' => $email, 'access_type' => $access, 'is_active' => 1])->assertRedirect(route('admin.users.index'))->assertSessionHas('password_setup_url');
            $this->assertDatabaseHas('users', ['email' => $email, 'role' => $role, 'training_path' => $path, 'is_active' => 1]);
            $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'user_created']);
        }
    }

    public function test_admin_can_change_access_type_without_deleting_history_and_disable_enable_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'training_path' => null]);
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $this->actingAs($admin)->put(route('admin.users.update', $participant), ['name' => $participant->name, 'email' => $participant->email, 'access_type' => 'qa_automation', 'is_active' => 1])->assertRedirect();
        $participant->refresh();
        $this->assertSame('participant', $participant->role);
        $this->assertSame('automation', $participant->training_path);
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
        $this->actingAs($admin)->put(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'access_type' => 'coach', 'is_active' => 1])->assertSessionHasErrors('access_type');
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
}
