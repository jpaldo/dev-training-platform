<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    public function test_a_user_created_with_only_a_name_gets_a_first_and_last_name_from_it(): void
    {
        $user = User::factory()->create(['name' => 'Ana Maria Cruz', 'first_name' => null, 'last_name' => null]);

        $user->refresh();
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Maria Cruz', $user->last_name);
        $this->assertSame('Ana Maria Cruz', $user->name);
    }

    public function test_a_user_created_with_a_one_word_name_gets_an_empty_last_name(): void
    {
        $user = User::factory()->create(['name' => 'Prince', 'first_name' => '', 'last_name' => '']);

        $user->refresh();
        $this->assertSame('Prince', $user->first_name);
        $this->assertSame('', $user->last_name);
        $this->assertSame('Prince', $user->name);
    }

    public function test_changing_the_name_of_a_user_with_only_a_first_name_does_not_split_it_again(): void
    {
        $user = User::factory()->create(['first_name' => 'Prince', 'last_name' => '']);

        $user->update(['name' => 'Prince Charles Reyes']);

        $user->refresh();
        $this->assertSame('Prince Charles Reyes', $user->name);
        $this->assertSame('Prince', $user->first_name);
        $this->assertSame('', $user->last_name);
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

    public function test_job_role_options_list_every_role_in_order_with_its_label(): void
    {
        $this->assertSame([
            'project_manager' => 'Project Manager (PM)',
            'qa_tester' => 'QA Tester',
            'developer' => 'Developer',
        ], JobRole::options());
    }
}
