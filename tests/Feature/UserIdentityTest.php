<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_saving_a_first_and_last_name_sets_the_full_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $user->update(['first_name' => 'Ana', 'last_name' => 'Cruz']);

        $this->assertSame('Ana Cruz', $user->fresh()->name);
    }

    public function test_changing_only_the_last_name_keeps_the_first_name_in_the_full_name(): void
    {
        $user = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);

        $user->update(['last_name' => 'Reyes']);

        $this->assertSame('Ana Reyes', $user->fresh()->name);
    }

    public function test_changing_only_the_name_leaves_the_first_and_last_name_untouched(): void
    {
        $user = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);

        $user->update(['name' => 'Ana C.']);

        $user->refresh();
        $this->assertSame('Ana C.', $user->name);
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Cruz', $user->last_name);
    }

    public function test_two_users_cannot_share_an_employee_id(): void
    {
        User::factory()->create(['employee_id' => 'FS-1001']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['employee_id' => 'FS-1001']);
    }

    public function test_several_users_can_have_no_employee_id(): void
    {
        User::factory()->count(2)->create(['employee_id' => null]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_the_migration_splits_existing_names_at_the_first_space_and_rolls_back(): void
    {
        $migration = $this->identityMigration();
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumns('users', ['employee_id', 'first_name', 'last_name', 'job_role']));

        DB::table('users')->insert([
            ['name' => 'Ana Maria Cruz', 'email' => 'ana@example.test', 'password' => 'secret'],
            ['name' => 'Prince', 'email' => 'prince@example.test', 'password' => 'secret'],
        ]);

        $migration->up();

        $this->assertDatabaseHas('users', ['email' => 'ana@example.test', 'first_name' => 'Ana', 'last_name' => 'Maria Cruz', 'name' => 'Ana Maria Cruz']);
        $this->assertDatabaseHas('users', ['email' => 'prince@example.test', 'first_name' => 'Prince', 'last_name' => '', 'name' => 'Prince']);
        $this->assertDatabaseHas('users', ['email' => 'prince@example.test', 'employee_id' => null, 'job_role' => null]);
    }

    public function test_job_role_options_list_every_role_in_order_with_its_label(): void
    {
        $this->assertSame([
            'project_manager' => 'Project Manager (PM)',
            'qa_tester' => 'QA Tester',
            'developer' => 'Developer',
        ], JobRole::options());
    }

    private function identityMigration(): Migration
    {
        return require database_path('migrations/2026_10_23_051317_add_identity_fields_to_users_table.php');
    }
}
