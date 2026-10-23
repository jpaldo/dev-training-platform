<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
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

    public function test_guest_sees_the_registration_form_with_every_job_role_and_no_path_and_sign_in_link(): void
    {
        $response = $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder([
                'DEVELOPER TRAINING PLATFORM',
                '>Create participant account</h1>',
                'method="post" action="'.route('register.store').'"',
                'name="_token"',
                '<label for="employee_id"',
                'id="employee_id" name="employee_id" value="" autocomplete="off" required autofocus',
                '<label for="first_name"',
                'id="first_name" name="first_name" value="" autocomplete="given-name" required',
                '<label for="last_name"',
                'id="last_name" name="last_name" value="" autocomplete="family-name" required',
                '<label for="email"',
                'id="email" name="email" type="email" value="" autocomplete="email" required',
                '<label for="job_role"',
                'id="job_role" name="job_role" required',
                '<option value="">Select a job role…</option>',
                '<option value="project_manager" >Project Manager (PM)</option>',
                '<option value="qa_tester" >QA Tester</option>',
                '<option value="developer" >Developer</option>',
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
            ->assertDontSee('role="alert"', false)
            ->assertDontSee('id="name"', false)
            ->assertDontSee('Training path')
            ->assertDontSee('Trainings to join');

        $this->assertStringStartsWith('<!doctype html>', ltrim($response->getContent()));
        $this->assertSame(1, substr_count($response->getContent(), '<button'));
    }

    public function test_registration_form_offers_the_trainings_open_to_join_as_optional_checkboxes(): void
    {
        $this->travelTo('2026-11-10 09:00:00');
        $december = TrainingSession::factory()->create(['title' => 'December run', 'starts_on' => '2026-12-07', 'ends_on' => '2027-01-03']);
        $november = TrainingSession::factory()->create(['title' => 'November run', 'starts_on' => '2026-11-09', 'ends_on' => '2026-12-06']);
        TrainingSession::factory()->completed()->create(['title' => 'October run']);

        $response = $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="password_confirmation"',
                '<fieldset class="grid min-w-0 grid-cols-1 gap-3" aria-describedby="training_session_ids-hint">',
                'Trainings to join',
                '(optional)</span></legend>',
                'id="training_session_ids-hint"',
                'name="training_session_ids[]"',
                'value="'.$november->id.'"',
                '>November run</span>',
                '>9 Nov – 6 Dec 2026</span>',
                'name="training_session_ids[]"',
                'value="'.$december->id.'"',
                '>December run</span>',
                '>7 Dec 2026 – 3 Jan 2027</span>',
                '</fieldset>',
                '>Create account</button>',
            ], false)
            ->assertDontSee('October run');

        $this->assertDoesNotMatchRegularExpression('/\schecked\s/', $response->getContent());
    }

    public function test_rejected_registration_shows_field_errors_and_keeps_the_entered_values(): void
    {
        User::factory()->create(['email' => 'taken@example.test', 'employee_id' => 'FS-1001']);
        $session = TrainingSession::factory()->upcoming()->create(['title' => 'December run']);

        $this->from(route('register'))
            ->post(route('register.store'), [
                'employee_id' => 'FS-1001',
                'first_name' => 'Pat',
                'last_name' => 'Participant',
                'email' => 'taken@example.test',
                'job_role' => 'qa_tester',
                'password' => 'short',
                'password_confirmation' => 'short',
                'training_session_ids' => [(string) $session->id],
            ])
            ->assertRedirect(route('register'));

        $response = $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="employee_id" name="employee_id" value="FS-1001"',
                'role="alert">The employee ID has already been taken.</p>',
                'id="first_name" name="first_name" value="Pat"',
                'id="last_name" name="last_name" value="Participant"',
                'id="email" name="email" type="email" value="taken@example.test"',
                'role="alert">The email has already been taken.</p>',
                '<option value="qa_tester" selected>QA Tester</option>',
                'id="password" name="password" type="password"',
                'role="alert">The password field must be at least 8 characters.</p>',
                '>December run</span>',
            ], false)
            ->assertDontSee('value="short"', false);

        $this->assertMatchesRegularExpression('/value="'.$session->id.'"\s+checked\s/', $response->getContent());
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
