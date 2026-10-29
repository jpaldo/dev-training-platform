<?php

namespace Tests\Feature;

use App\Models\PasswordSetupToken;
use App\Models\User;
use App\Services\PasswordSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_account_without_a_plaintext_password_and_receive_a_one_time_setup_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'employee_id' => 'EMP-9001',
            'first_name' => 'New',
            'last_name' => 'Admin',
            'email' => 'new-admin@example.test',
            'access_type' => 'admin',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('admin.users.index'))->assertSessionHas('password_setup_url');
        $user = User::query()->where('email', 'new-admin@example.test')->firstOrFail();
        $url = $response->getSession()->get('password_setup_url');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertNotSame($token, PasswordSetupToken::query()->firstOrFail()->token_hash);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'target_user_id' => $user->id, 'action' => 'user_created']);
        $this->get($url)->assertOk()->assertSee('Set your password');

        $this->post(route('password.setup.store', $token), [
            'password' => 'A-secure-password-2026!',
            'password_confirmation' => 'A-secure-password-2026!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('A-secure-password-2026!', $user->fresh()->password));
        $this->assertNotNull(PasswordSetupToken::query()->firstOrFail()->fresh()->used_at);
    }

    public function test_password_setup_token_is_hashed_expiring_and_single_use(): void
    {
        $user = User::factory()->create();
        $service = app(PasswordSetupService::class);
        $token = $service->issue($user);

        $record = PasswordSetupToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertNotNull($record->expires_at);

        $service->complete($token, 'A-secure-password-2026!');
        $this->assertNull($service->findValid($token));
        $this->post(route('password.setup.store', $token), [
            'password' => 'Another-password-2026!',
            'password_confirmation' => 'Another-password-2026!',
        ])->assertSessionHasErrors('token');
    }

    public function test_invalid_and_expired_tokens_cannot_set_passwords(): void
    {
        $this->get(route('password.setup', ['token' => str_repeat('a', 64)]))->assertNotFound();

        $user = User::factory()->create();
        $token = app(PasswordSetupService::class)->issue($user);
        PasswordSetupToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->get(route('password.setup', $token))->assertNotFound();
    }

    public function test_non_admin_cannot_initiate_account_setup(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->post(route('admin.users.store'), [
                'employee_id' => 'EMP-9002',
                'first_name' => 'Blocked',
                'last_name' => 'Admin',
                'email' => 'blocked@example.test',
                'access_type' => 'admin',
            ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_admin_issues_a_new_setup_link_that_replaces_the_unused_one_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach', 'email' => 'mentor@example.test']);
        $earlierToken = app(PasswordSetupService::class)->issue($mentor, $admin);

        $response = $this->actingAs($admin)->post(route('admin.users.password-setup-link', $mentor));

        $response->assertRedirect(route('admin.users.index'))->assertSessionHas('password_setup_email', 'mentor@example.test');
        $url = $response->getSession()->get('password_setup_url');
        $this->get($url)->assertOk()->assertSee('Create a password for mentor@example.test.');
        $this->get(route('password.setup', $earlierToken))->assertNotFound();
        $this->assertSame(2, PasswordSetupToken::query()->where('user_id', $mentor->id)->count());
        $this->assertDatabaseHas('password_setup_tokens', ['token_hash' => hash('sha256', basename($url)), 'created_by' => $admin->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'target_user_id' => $mentor->id, 'action' => 'password_setup_link_issued']);
    }

    public function test_a_disabled_account_gets_no_new_setup_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $disabled = User::factory()->create(['is_active' => false]);

        $response = $this->actingAs($admin)->from(route('admin.users.index'))->post(route('admin.users.password-setup-link', $disabled));

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['user' => 'Enable this account before creating a password setup link.'])
            ->assertSessionMissing('password_setup_url');
        $this->assertDatabaseCount('password_setup_tokens', 0);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'password_setup_link_issued']);
    }

    public function test_non_admin_cannot_issue_a_new_setup_link(): void
    {
        $participant = User::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->post(route('admin.users.password-setup-link', $participant))
            ->assertForbidden();

        $this->assertDatabaseCount('password_setup_tokens', 0);
    }

    public function test_account_can_log_in_after_password_setup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'employee_id' => 'EMP-9003',
            'first_name' => 'Direct',
            'last_name' => 'Admin',
            'email' => 'direct-admin@example.test',
            'access_type' => 'admin',
            'is_active' => 1,
        ])->assertRedirect(route('admin.users.index'));
        $token = basename(parse_url($response->getSession()->get('password_setup_url'), PHP_URL_PATH));
        $this->post(route('logout'));

        $this->post(route('password.setup.store', $token), [
            'password' => 'A-secure-password-2026!',
            'password_confirmation' => 'A-secure-password-2026!',
        ])->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => 'direct-admin@example.test',
            'password' => 'A-secure-password-2026!',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'direct-admin@example.test')->firstOrFail());
    }
}
