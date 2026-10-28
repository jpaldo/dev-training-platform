<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserFormPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_add_user_page_shows_the_empty_form_with_the_password_setup_notice_and_one_main_action(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users.create'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN · USERS',
                'Add User',
                'id="admin-user-main-action"', 'form="admin-user-form"', 'Create User',
                'href="'.route('admin.users.index').'"', 'Cancel',
                'method="POST" action="'.route('admin.users.store').'" id="admin-user-form"',
                'Employee ID *', 'id="employee_id" name="employee_id" value="" autocomplete="off" required',
                'First Name *', 'id="first_name" name="first_name" value="" required',
                'Last Name *', 'id="last_name" name="last_name" value="" required',
                'id="email" name="email" type="email" value=""',
                'Job Role', 'id="job_role" name="job_role"',
                '<option value="">Not set</option>',
                '<option value="project_manager" >Project Manager (PM)</option>',
                '<option value="qa_tester" >QA Tester</option>',
                '<option value="developer" >Developer</option>',
                'id="access_type" name="access_type"',
                '<option value="admin" >Admin</option>',
                '<option value="coach" >Mentor</option>',
                '<option value="participant" >Participant</option>',
                'Admin, Mentor and Participant map to the platform\'s existing authorization model.',
                'Secure password setup',
                'name="is_active" value="0"', 'name="is_active" value="1" checked',
                'Active account',
            ], false)
            ->assertDontSee('name="_method"', false)
            ->assertDontSee('qa_manual', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('Training Drive')
            ->assertDontSee('Assigned Mentors')
            ->assertDontSee('Attendance summary');
    }

    public function test_editing_a_participant_shows_the_saved_values_and_drive_without_mentor_assignment(): void
    {
        $participant = User::factory()->create(['name' => 'Hal Hybrid', 'employee_id' => 'EMP-0042', 'job_role' => 'qa_tester', 'email' => 'hal@example.test', 'is_active' => false, 'drive_link' => 'https://drive.google.com/drive/folders/hal']);

        $this->actingAs($this->admin())->get(route('admin.users.edit', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'Edit User',
                'form="admin-user-form"', 'Save User',
                'action="'.route('admin.users.update', $participant).'" id="admin-user-form"',
                'name="_method" value="PUT"',
                'id="employee_id" name="employee_id" value="EMP-0042"',
                'id="first_name" name="first_name" value="Hal"',
                'id="last_name" name="last_name" value="Hybrid"',
                'value="hal@example.test"',
                '<option value="qa_tester" selected>QA Tester</option>',
                '<option value="participant" selected>Participant</option>',
                'id="password" name="password" type="password"',
                'id="password_confirmation" name="password_confirmation" type="password"',
                'Training Drive',
                'href="https://drive.google.com/drive/folders/hal" target="_blank" rel="noopener noreferrer"', 'Open Training Drive ↗',
            ], false)
            ->assertDontSee('name="is_active" value="1" checked', false)
            ->assertDontSee('Attendance summary')
            ->assertDontSee('View attendance')
            ->assertDontSee('Assigned Mentors')
            ->assertDontSee('name="coach_ids[]"', false)
            ->assertDontSee('Secure password setup');
    }

    #[DataProvider('accessTypesByRole')]
    public function test_editing_a_user_preselects_the_access_type_of_their_role(UserRole $role, string $selectedOption): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($this->admin())->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee($selectedOption, false)
            ->assertSeeInOrder(['<option value="admin"', '<option value="coach"', '<option value="participant"'], false);
    }

    /**
     * @return array<string, array{UserRole, string}>
     */
    public static function accessTypesByRole(): array
    {
        return [
            'participant' => [UserRole::Participant, '<option value="participant" selected>Participant</option>'],
            'admin' => [UserRole::Admin, '<option value="admin" selected>Admin</option>'],
        ];
    }

    public function test_editing_a_coach_has_no_coach_assignment_and_shows_a_missing_drive_link(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($this->admin())->get(route('admin.users.edit', $coach))
            ->assertOk()
            ->assertSee('<option value="coach" selected>Mentor</option>', false)
            ->assertSeeInOrder(['Training Drive', 'Not provided'])
            ->assertDontSee('Assigned Mentors');
    }

    public function test_editing_a_user_with_an_employee_id_marks_it_required(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => 'EMP-0043']);

        $this->actingAs($this->admin())->get(route('admin.users.edit', $coach))
            ->assertOk()
            ->assertSee('Employee ID *')
            ->assertSee('id="employee_id" name="employee_id" value="EMP-0043" autocomplete="off" required', false);
    }

    public function test_editing_a_user_without_an_employee_id_leaves_it_optional_and_the_job_role_unset(): void
    {
        $legacy = User::factory()->create(['employee_id' => null, 'job_role' => null]);

        $this->actingAs($this->admin())->get(route('admin.users.edit', $legacy))
            ->assertOk()
            ->assertSee('>Employee ID</label>', false)
            ->assertSee('id="employee_id" name="employee_id" value=""', false)
            ->assertDontSee('Employee ID *')
            ->assertDontSee('autocomplete="off" required', false)
            ->assertDontSee('selected>Developer</option>', false);
    }

    public function test_a_rejected_save_returns_to_the_form_with_the_error_and_the_old_input(): void
    {
        $participant = User::factory()->create();

        $this->actingAs($this->admin())
            ->from(route('admin.users.edit', $participant))
            ->followingRedirects()
            ->put(route('admin.users.update', $participant), ['employee_id' => 'EMP-7777', 'first_name' => 'Renamed', 'last_name' => 'Person', 'email' => 'not-an-email', 'job_role' => 'project_manager', 'access_type' => 'coach', 'is_active' => '0'])
            ->assertOk()
            ->assertSeeInOrder([
                'role="alert"',
                'value="EMP-7777"',
                'value="Renamed"',
                'value="Person"',
                'value="not-an-email"',
                '<option value="project_manager" selected>Project Manager (PM)</option>',
                '<option value="coach" selected>Mentor</option>',
            ], false)
            ->assertDontSee('name="is_active" value="1" checked', false);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
