<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\User;
use App\Services\AdminSettingsStatusService;
use App\Services\EmailConfigurationService;
use App\Services\GoogleCalendarService;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSettingsAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_has_a_system_dashboard_and_coach_tools_remain_available(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Program::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('ADMIN DASHBOARD')->assertSee('System overview')->assertSee(route('coach.dashboard'));
        $this->actingAs($admin)->get(route('coach.dashboard'))->assertOk();
        Auth::logout();
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_settings_landing_and_subpages_are_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);

        foreach ([route('admin.settings.index'), route('admin.settings.google'), route('admin.settings.email'), route('admin.settings.training')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
            $this->actingAs($coach)->get($url)->assertForbidden();
            $this->actingAs($participant)->get($url)->assertForbidden();
        }
        Auth::logout();
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
    }

    public function test_google_settings_show_safe_readiness_and_never_render_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['services.google_drive.client_id' => 'client-id.apps.googleusercontent.com', 'services.google_drive.client_secret' => 'super-secret', 'services.google_drive.redirect_uri' => 'https://example.test/profile/google-drive/callback', 'services.google_drive.enabled' => true]);

        $this->actingAs($admin)->get(route('admin.settings.google'))->assertOk()
            ->assertSee('https://example.test/profile/google-drive/callback')
            ->assertSee('drive.file')
            ->assertSee('calendar.events')
            ->assertSee('Existing Google Drive connections')
            ->assertDontSee('super-secret')
            ->assertDontSee(config('app.key'));
    }

    public function test_email_and_training_settings_show_actual_safe_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.settings.email'))->assertOk()->assertSee(config('mail.default'))->assertSee('Development Only');
        $this->actingAs($admin)->get(route('admin.settings.training'))->assertOk()->assertSee('Asia/Manila')->assertSee('60 minutes')->assertSee('300 MB')->assertSee('350 MB');
    }

    public function test_admin_can_save_smtp_configuration_without_rendering_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.email.update'), [
            'mailer' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_scheme' => 'tls',
            'smtp_username' => 'mailer@example.test',
            'smtp_password' => 'super-secret-password',
            'from_address' => 'qa@example.test',
            'from_name' => 'QA Training Platform',
            'mentor_emails_enabled' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $setting = ApplicationSetting::query()->where('key', EmailConfigurationService::SMTP_PASSWORD)->firstOrFail();
        $this->assertTrue($setting->is_encrypted);
        $this->assertSame('super-secret-password', Crypt::decryptString($setting->value));
        $this->actingAs($admin)->get(route('admin.settings.email'))->assertOk()->assertDontSee('super-secret-password');
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
    }

    public function test_blank_smtp_password_preserves_existing_credential_and_test_email_uses_saved_configuration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(EmailConfigurationService::class);
        $service->save([
            'mailer' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_scheme' => 'tls',
            'smtp_username' => 'mailer@example.test', 'smtp_password' => 'keep-me', 'from_address' => 'qa@example.test',
            'from_name' => 'QA Training Platform', 'mentor_emails_enabled' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.settings.email.update'), [
            'mailer' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_scheme' => 'tls',
            'smtp_username' => 'mailer@example.test', 'smtp_password' => '', 'from_address' => 'qa@example.test',
            'from_name' => 'QA Training Platform', 'mentor_emails_enabled' => 1,
        ])->assertRedirect();
        $this->assertSame('keep-me', Crypt::decryptString(ApplicationSetting::query()->where('key', EmailConfigurationService::SMTP_PASSWORD)->value('value')));

        Mail::fake();
        $this->actingAs($admin)->post(route('admin.settings.email.test'), ['recipient' => 'admin@example.test'])->assertRedirect()->assertSessionHas('status');
    }

    public function test_admin_test_email_is_delivered_to_the_recipient(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.settings.email.test'), ['recipient' => 'admin@example.test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('admin@example.test', $messages->first()->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_log_mailer_does_not_require_smtp_fields_and_non_admin_cannot_modify_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($admin)->put(route('admin.settings.email.update'), [
            'mailer' => 'log', 'from_address' => 'qa@example.test', 'from_name' => 'QA Training Platform', 'mentor_emails_enabled' => 0,
        ])->assertRedirect();
        $this->assertSame('log', app(EmailConfigurationService::class)->current()['mailer']);
        $this->actingAs($coach)->put(route('admin.settings.email.update'), [
            'mailer' => 'smtp', 'from_address' => 'qa@example.test', 'from_name' => 'QA Training Platform', 'mentor_emails_enabled' => 1,
        ])->assertForbidden();
    }

    public function test_invalid_email_configuration_does_not_echo_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.settings.email'))->put(route('admin.settings.email.update'), [
            'mailer' => 'smtp', 'smtp_password' => 'do-not-echo', 'from_address' => 'invalid', 'from_name' => 'QA Training Platform', 'mentor_emails_enabled' => 1,
        ])->assertRedirect(route('admin.settings.email'))->assertSessionHasErrors(['smtp_host', 'smtp_port', 'from_address']);
        $this->assertArrayNotHasKey('smtp_password', session('_old_input', []));
    }

    public function test_google_calendar_status_is_not_reported_ready_without_external_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['services.google_drive.client_id' => 'client-id.apps.googleusercontent.com', 'services.google_drive.client_secret' => 'super-secret', 'services.google_drive.redirect_uri' => 'https://example.test/profile/google-drive/callback', 'services.google_drive.enabled' => true]);

        $response = $this->actingAs($admin)->get(route('admin.settings.google'))->assertOk();
        $response->assertSee('Requires manual Google Cloud Console verification')->assertSee('Application configured — authorization required')->assertDontSee('CALENDAR / MEET</span><p class="success">Ready');
        $this->assertFalse(app(AdminSettingsStatusService::class)->google()['calendar_ready']);
    }

    public function test_google_settings_identify_connections_that_need_calendar_reauthorization(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'access_token' => 'access', 'scopes' => ['https://www.googleapis.com/auth/drive.file'], 'connected_at' => now()]);

        $this->actingAs($admin)->get(route('admin.settings.google'))->assertOk()->assertSee('Reauthorization required for some connections');
    }

    public function test_calendar_participant_availability_is_admin_controlled_and_defaults_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($admin)->get(route('admin.settings.google'))->assertOk()->assertSee('Disabled');
        $this->actingAs($admin)->put(route('admin.settings.google.calendar-availability'), ['enabled' => 1])->assertRedirect();
        $this->assertTrue(app(GoogleDriveConfigurationService::class)->participantCalendarEnabled());
        $this->actingAs($coach)->put(route('admin.settings.google.calendar-availability'), ['enabled' => 0])->assertForbidden();
        $this->actingAs($participant)->put(route('admin.settings.google.calendar-availability'), ['enabled' => 0])->assertForbidden();
    }

    public function test_admin_verification_oauth_is_admin_only_and_requests_calendar_scope_when_participants_are_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);
        config(['services.google_drive.client_id' => 'client-id', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'https://example.test/profile/google-drive/callback', 'services.google_drive.enabled' => true]);

        $response = $this->actingAs($admin)->get(route('admin.settings.google.verification.connect'))->assertRedirect();
        $this->assertStringContainsString(rawurlencode('https://www.googleapis.com/auth/calendar.events'), $response->headers->get('Location'));
        $this->actingAs($participant)->get(route('admin.settings.google.verification.connect'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.settings.google.verification.callback', ['state' => 'forged', 'code' => 'code']))->assertRedirect(route('admin.settings.google'))->assertSessionHasErrors('google_verification');
    }

    public function test_admin_verification_returning_to_shared_profile_callback_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['services.google_drive.client_id' => 'client-id', 'services.google_drive.client_secret' => 'secret', 'services.google_drive.redirect_uri' => 'http://localhost/profile/google-drive/callback', 'services.google_drive.enabled' => true]);
        $this->actingAs($admin)->get(route('admin.settings.google.verification.connect'))->assertRedirect();
        $state = session('google_verification_oauth.state');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'verification-access', 'refresh_token' => 'verification-refresh', 'expires_in' => 3600, 'scope' => 'openid email profile '.GoogleDriveConnectionService::DRIVE_FILE_SCOPE.' '.GoogleCalendarService::CALENDAR_SCOPE], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'verification-account', 'email' => $admin->email], 200),
        ]);

        $this->actingAs($admin)->get(route('profile.google-drive.callback', ['state' => $state, 'code' => 'verification-code']))
            ->assertRedirect(route('admin.settings.google'))
            ->assertSessionHas('status', 'Google Calendar verification authorization completed.');
        $this->assertDatabaseHas('google_drive_connections', ['user_id' => $admin->id, 'google_account_id' => 'verification-account']);
    }
}
