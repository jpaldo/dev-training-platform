<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminUserListPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rows_show_the_employee_id_access_type_status_and_account_actions_of_each_user(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 10));
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin', 'created_at' => now()->subDays(5)]);
        User::factory()->create(['role' => 'coach', 'name' => 'Cole Coach', 'employee_id' => null, 'created_at' => now()->subDays(4)]);
        User::factory()->create(['name' => 'Max Manual', 'created_at' => now()->subDays(3)]);
        User::factory()->create(['name' => 'Ava Automation', 'created_at' => now()->subDays(2)]);
        $hybrid = User::factory()->create(['name' => 'Hal Hybrid', 'employee_id' => 'EMP-0042', 'is_active' => false, 'created_at' => now()->subDay()]);

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN · USERS',
                'User Management',
                'Access Type determines each user\'s role in the training platform.',
                'id="admin-users-main-action"', 'href="'.route('admin.users.create').'"', '+ Add User',
                'aria-label="Admin settings"',
                'href="'.route('admin.settings.google-drive').'"', 'Google Drive Settings',
                'href="'.route('admin.settings.ai').'"', 'AI Settings',
                'aria-label="Filter users"',
                'role="region" aria-labelledby="user-list-title"',
                '>Name</th>', '>Employee ID</th>', '>Email</th>',
                '>Hal Hybrid</th>', '>EMP-0042</td>', '>Participant</td>', '>Disabled</span>', 'Mar 9, 2026',
                'href="'.route('admin.users.edit', $hybrid).'"', 'Edit',
                'action="'.route('admin.users.enable', $hybrid).'"', 'Enable',
                '>Ava Automation</th>', '>Participant</td>', '>Active</span>', 'Mar 8, 2026',
                '>Max Manual</th>', '>Participant</td>', '>Active</span>',
                '>Cole Coach</th>', '>—</td>', '>Mentor</td>', '>Active</span>',
                '>Ada Admin</th>', '>Admin</td>', '>Active</span>', 'Mar 5, 2026',
                'action="'.route('admin.users.password-setup-link', $admin).'"', 'data-confirm="Create a new password setup link for this user? Any earlier unused link stops working."', 'New Setup Link',
                'action="'.route('admin.users.disable', $admin).'"', 'data-confirm="Disable this account? Historical training records will be preserved."', 'Disable',
            ], false)
            ->assertDontSee('action="'.route('admin.users.disable', $hybrid).'"', false)
            ->assertDontSee('action="'.route('admin.users.enable', $admin).'"', false)
            ->assertDontSee('action="'.route('admin.users.password-setup-link', $hybrid).'"', false)
            ->assertDontSee('Password setup link')
            ->assertDontSee('QA Path')
            ->assertDontSee('name="path"', false)
            ->assertDontSee('QA Automation')
            ->assertDontSee('QA Manual')
            ->assertDontSee('href="'.route('admin.attendance.index').'"', false)
            ->assertDontSee('View Attendance');
    }

    public function test_filters_keep_their_values_and_an_empty_result_shows_a_notice_instead_of_the_table(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['name' => 'Ava Automation']);
        User::factory()->create(['name' => 'Max Manual']);

        $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Ava', 'access_type' => 'participant', 'status' => 'active']))
            ->assertOk()
            ->assertSee('name="search" value="Ava"', false)
            ->assertSee('<option value="participant" selected>Participant</option>', false)
            ->assertSee('<option value="active" selected>Active</option>', false)
            ->assertSee('>Ava Automation</th>', false)
            ->assertDontSee('Max Manual');

        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'disabled']))
            ->assertOk()
            ->assertSee('<option value="disabled" selected>Disabled</option>', false)
            ->assertSee('No users match these filters.')
            ->assertDontSee('<table', false);
    }

    public function test_the_access_type_filter_lists_only_users_with_that_role_and_ignores_a_path(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        User::factory()->create(['role' => 'coach', 'name' => 'Cole Coach']);
        User::factory()->create(['name' => 'Max Manual']);
        User::factory()->create(['name' => 'Nia Nopath']);

        $this->actingAs($admin)->get(route('admin.users.index', ['access_type' => 'participant', 'path' => 'automation']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSeeInOrder([
                '<option value="">All</option>',
                '<option value="admin" >Admin</option>',
                '<option value="coach" >Mentor</option>',
                '<option value="participant" selected>Participant</option>',
            ], false)
            ->assertSee('>Max Manual</th>', false)
            ->assertSee('>Nia Nopath</th>', false)
            ->assertDontSee('>Cole Coach</th>', false)
            ->assertDontSee('>Ada Admin</th>', false);

        $this->actingAs($admin)->get(route('admin.users.index', ['access_type' => 'coach']))
            ->assertOk()
            ->assertSee('>Cole Coach</th>', false)
            ->assertDontSee('>Max Manual</th>', false)
            ->assertDontSee('>Ada Admin</th>', false);
    }

    public function test_the_access_type_filter_rejects_the_former_qa_access_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['qa_manual', 'qa_automation', 'qa_automation_manual'] as $formerAccessType) {
            $this->actingAs($admin)->get(route('admin.users.index', ['access_type' => $formerAccessType]))
                ->assertSessionHasErrors(['access_type' => 'The selected access type is invalid.']);
        }
    }

    public function test_searching_for_an_employee_id_finds_that_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['name' => 'Ava Automation', 'employee_id' => 'EMP-1234']);
        User::factory()->create(['name' => 'Max Manual', 'employee_id' => 'EMP-9876']);

        $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'EMP-1234']))
            ->assertOk()
            ->assertSee('placeholder="Name, email or employee ID"', false)
            ->assertSee('>Ava Automation</th>', false)
            ->assertDontSee('Max Manual');
    }

    public function test_the_one_time_password_setup_link_is_shown_with_its_expiry_after_creating_a_user(): void
    {
        config(['password_setup.expire' => 45]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->withSession(['password_setup_url' => 'https://example.test/password/setup/token'])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Password setup link',
                'It expires in 45 minutes and is not shown again.',
                'value="https://example.test/password/setup/token" readonly aria-label="One-time password setup link"',
            ], false);
    }

    public function test_the_setup_link_names_its_user_and_a_long_expiry_reads_in_days(): void
    {
        config(['password_setup.expire' => 4320]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->withSession(['password_setup_url' => 'https://example.test/password/setup/token', 'password_setup_email' => 'mentor@example.test'])
            ->get(route('admin.users.index'))
            ->assertSee('Share this one-time link with mentor@example.test through your approved secure channel. It expires in 3 days and is not shown again.');
    }
}
