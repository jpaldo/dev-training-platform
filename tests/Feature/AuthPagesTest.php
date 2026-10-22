<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordSetupService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_sees_the_sign_in_form_with_its_single_submit_and_register_link(): void
    {
        $response = $this->get(route('login'))
            ->assertOk()
            ->assertSeeInOrder([
                'DEVELOPER TRAINING PLATFORM',
                '>Sign in</h1>',
                'method="post" action="'.route('login.store').'"',
                'name="_token"',
                '<label for="email"',
                'id="email" name="email" type="email" value="" autocomplete="email" required autofocus',
                '<label for="password"',
                'id="password" name="password" type="password" autocomplete="current-password" required',
                'name="remember" type="checkbox" value="1"',
                'Remember me',
                'type="submit"',
                '>Sign in</button>',
                '</form>',
                'New participant?',
                'href="'.route('register').'"',
                'Create an account.',
            ], false)
            ->assertDontSee('role="alert"', false);

        $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()));
        $this->assertSame(1, substr_count($response->getContent(), '<button'));
    }

    public function test_rejected_sign_in_shows_the_email_error_and_keeps_only_the_email(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.test']);

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="email" name="email" type="email" value="pat@example.test"',
                'role="alert">'.__('auth.failed').'</p>',
                'id="password" name="password" type="password"',
            ], false)
            ->assertDontSee('wrong-password');
    }

    public function test_guest_sees_the_registration_form_with_every_training_path_and_sign_in_link(): void
    {
        $response = $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder([
                'DEVELOPER TRAINING PLATFORM',
                '>Create participant account</h1>',
                'method="post" action="'.route('register.store').'"',
                'name="_token"',
                '<label for="name"',
                'id="name" name="name" value="" autocomplete="name" required autofocus',
                '<label for="email"',
                'id="email" name="email" type="email" value="" autocomplete="email" required',
                '<label for="training_path"',
                'id="training_path" name="training_path" required',
                '<option value="">Select a path…</option>',
                '<option value="manual" >Manual QA</option>',
                '<option value="automation" >Automation QA</option>',
                '<option value="automation_manual" >Automation / Manual</option>',
                '<label for="password"',
                'id="password" name="password" type="password" autocomplete="new-password" required',
                '<label for="password_confirmation"',
                'id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required',
                '>Create account</button>',
                '</form>',
                'Already registered?',
                'href="'.route('login').'"',
                'Sign in.',
            ], false)
            ->assertDontSee('role="alert"', false);

        $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()));
        $this->assertSame(1, substr_count($response->getContent(), '<button'));
    }

    public function test_rejected_registration_shows_field_errors_and_keeps_the_entered_values(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->from(route('register'))
            ->post(route('register.store'), [
                'name' => 'Pat Participant',
                'email' => 'taken@example.test',
                'training_path' => 'automation',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertRedirect(route('register'));

        $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="name" name="name" value="Pat Participant"',
                'id="email" name="email" type="email" value="taken@example.test"',
                'role="alert">The email has already been taken.</p>',
                '<option value="automation" selected>Automation QA</option>',
                'id="password" name="password" type="password"',
                'role="alert">The password field must be at least 8 characters.</p>',
            ], false)
            ->assertDontSee('value="short"', false);
    }

    public function test_password_setup_page_shows_the_invited_email_and_the_form_for_its_token(): void
    {
        $user = User::factory()->create(['email' => 'invitee@example.test']);
        $token = app(PasswordSetupService::class)->issue($user);

        $response = $this->get(route('password.setup', $token))
            ->assertOk()
            ->assertSeeInOrder([
                'ACCOUNT SECURITY',
                '>Set your password</h1>',
                'Create a password for invitee@example.test. This one-time setup link expires shortly.',
                'method="POST" action="'.route('password.setup.store', $token).'"',
                'name="_token"',
                '<label for="password"',
                'id="password" name="password" type="password" autocomplete="new-password" required',
                '<label for="password_confirmation"',
                'id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required',
                'type="submit"',
                '>Set password</button>',
                '</form>',
            ], false)
            ->assertDontSee('role="alert"', false);

        $this->assertSame(1, substr_count($response->getContent(), '<button'));
    }

    public function test_rejected_password_setup_shows_the_first_error_in_an_alert(): void
    {
        $user = User::factory()->create();
        $token = app(PasswordSetupService::class)->issue($user);

        $this->from(route('password.setup', $token))
            ->post(route('password.setup.store', $token), [
                'password' => 'A-secure-password-2026!',
                'password_confirmation' => 'Another-password-2026!',
            ])
            ->assertRedirect(route('password.setup', $token));

        $this->get(route('password.setup', $token))
            ->assertOk()
            ->assertSeeInOrder([
                '>Set your password</h1>',
                'role="alert">The password field confirmation does not match.</div>',
                'action="'.route('password.setup.store', $token).'"',
            ], false)
            ->assertDontSee('A-secure-password-2026!');
    }

    public function test_completed_password_setup_shows_its_status_on_the_sign_in_page(): void
    {
        $token = app(PasswordSetupService::class)->issue(User::factory()->create());

        $this->followingRedirects()
            ->post(route('password.setup.store', $token), [
                'password' => 'A-secure-password-2026!',
                'password_confirmation' => 'A-secure-password-2026!',
            ])
            ->assertOk()
            ->assertSeeInOrder([
                '>Sign in</h1>',
                'role="status">Your password has been set. You can now sign in.</div>',
                'action="'.route('login.store').'"',
            ], false);
    }
}
