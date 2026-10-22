<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeIdPromptTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_coach_without_an_employee_id_is_sent_to_the_prompt_after_logging_in(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => null]);

        $this->followingRedirects()
            ->post(route('login.store'), ['email' => $coach->email, 'password' => 'password'])
            ->assertOk()
            ->assertViewIs('account.employee-id')
            ->assertSee('Add your employee ID');

        $this->assertAuthenticatedAs($coach);
    }

    public function test_any_other_page_redirects_to_the_prompt(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => null]);

        $this->actingAs($coach)->get(route('coach.dashboard'))->assertRedirect(route('account.employee-id.edit'));
        $this->actingAs($coach)->get(route('profile.show'))->assertRedirect(route('account.employee-id.edit'));
    }

    public function test_requests_expecting_json_get_a_403_instead_of_a_redirect(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'employee_id' => null]);

        $this->actingAs($participant)
            ->postJson(route('markdown.preview'), ['content' => 'Draft notes'])
            ->assertForbidden()
            ->assertJson(['message' => 'Add your employee ID to continue.']);
    }

    public function test_a_user_without_an_employee_id_can_log_out(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => null]);

        $this->actingAs($coach)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_saving_an_employee_id_continues_to_the_role_dashboard(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => null]);

        $this->actingAs($coach)
            ->patch(route('account.employee-id.update'), ['employee_id' => 'EMP-2001'])
            ->assertRedirect(route('coach.dashboard'));

        $this->assertSame('EMP-2001', $coach->fresh()->employee_id);
        $this->actingAs($coach->fresh())->get(route('coach.dashboard'))->assertOk();
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidEmployeeIds')]
    public function test_an_invalid_employee_id_is_rejected_and_nothing_is_saved(array $payload, string $error): void
    {
        User::factory()->create(['employee_id' => 'EMP-TAKEN']);
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => null]);

        $this->actingAs($coach)
            ->from(route('account.employee-id.edit'))
            ->patch(route('account.employee-id.update'), $payload)
            ->assertRedirect(route('account.employee-id.edit'))
            ->assertSessionHasErrors(['employee_id' => $error]);

        $this->assertNull($coach->fresh()->employee_id);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidEmployeeIds(): array
    {
        return [
            'taken' => [['employee_id' => 'EMP-TAKEN'], 'The employee ID has already been taken.'],
            'missing' => [['employee_id' => ''], 'The employee ID field is required.'],
            'too long' => [['employee_id' => str_repeat('9', 51)], 'The employee ID field must not be greater than 50 characters.'],
        ];
    }

    public function test_a_user_with_an_employee_id_never_sees_the_prompt_and_cannot_change_their_id(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => 'EMP-3003']);

        $this->actingAs($coach)->get(route('coach.dashboard'))->assertOk();
        $this->actingAs($coach)->get(route('account.employee-id.edit'))->assertRedirect(route('coach.dashboard'));
        $this->actingAs($coach)->patch(route('account.employee-id.update'), ['employee_id' => 'EMP-9999'])->assertForbidden();

        $this->assertSame('EMP-3003', $coach->fresh()->employee_id);
    }
}
