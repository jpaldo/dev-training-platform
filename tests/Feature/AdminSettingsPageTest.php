<?php

namespace Tests\Feature;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Services\AdminSettingsStatusService;
use App\Services\EmailConfigurationService;
use App\Services\GoogleCalendarService;
use App\Services\GoogleDriveConfigurationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminSettingsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_settings_overview_lists_each_area_with_its_status_and_a_secondary_manage_link(): void
    {
        config(['training.week_four_mentor_interview_timezone' => 'Asia/Manila']);
        $this->configureGoogle(null);
        $statuses = app(AdminSettingsStatusService::class);

        $this->actingAs($this->admin())->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN SETTINGS',
                'Application settings',
                'Secret values are never displayed.',
                'AI CONFIGURATION', e($statuses->ai()['status']), 'Configure AI providers, models, and credentials.',
                'href="'.route('admin.settings.ai').'"', 'bg-raised', 'Manage AI Settings',
                'GOOGLE INTEGRATION', 'Needs application configuration', 'Drive, Picker, Calendar, and Google Meet scheduling configuration.',
                'href="'.route('admin.settings.google').'"', 'bg-raised', 'Manage Google Settings',
                'EMAIL', 'Development Only / Needs Configuration', 'Current mail transport and Mentor scheduling email readiness.',
                'href="'.route('admin.settings.email').'"', 'bg-raised', 'Manage Email Settings',
                'TRAINING SETTINGS', 'Asia/Manila', 'Mentor interview duration and application/server upload limits.',
                'href="'.route('admin.settings.training').'"', 'bg-raised', 'Manage Training Settings',
                'INTERVIEW SCHEDULING', 'Public booking links', 'Manage Mock Interview and Final Re-assessment interviewers and the public scheduling page.',
                'href="'.route('admin.settings.interview-scheduling').'"', 'bg-raised', 'Manage Interview Scheduling',
            ], false);
    }

    public function test_google_page_shows_a_configured_integration_with_masked_credentials_and_granted_verification(): void
    {
        $admin = $this->admin();
        $this->configureGoogle('client-id.apps.googleusercontent.com');
        app(GoogleDriveConfigurationService::class)->saveParticipantCalendarAvailability(true);
        GoogleDriveConnection::create(['user_id' => $admin->id, 'google_account_id' => 'admin-account', 'access_token' => 'admin-access-token', 'scopes' => [GoogleCalendarService::CALENDAR_SCOPE], 'connected_at' => now()]);

        $this->actingAs($admin)->get(route('admin.settings.google'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN SETTINGS · GOOGLE',
                'Google Integration',
                'Overall status',
                'OAUTH', 'border-emerald-400', 'Configured',
                'GOOGLE DRIVE', 'border-emerald-400', 'Configured',
                'CALENDAR / MEET', 'border-amber-500', 'Application configured — authorization required',
                'OAuth configuration',
                'Client ID', e(str_repeat('•', 32).'.com'),
                'Client Secret', '✓ Configured',
                'Current OAuth Redirect URI', '<code', 'https://example.test/profile/google-drive/callback</code>',
                'Scopes requested by the application', 'drive.file',
                'href="'.route('admin.settings.google-drive').'"', 'Manage OAuth / Drive Configuration',
                'Calendar &amp; Google Meet',
                '<strong>Legacy Calendar scope setting:</strong> Enabled (retained for compatibility and Admin verification only)',
                '<strong>Calendar scope:</strong> '.GoogleCalendarService::CALENDAR_SCOPE,
                'href="'.route('admin.settings.interview-scheduling').'"', 'Manage Interview Booking Links',
                'Calendar readiness',
                '✓', 'OAuth client configured',
                '✓', 'Calendar integration code configured',
                '⚠', 'Google Cloud consent / verification: Requires manual Google Cloud Console verification',
                '⚠', 'Current participant authorization: Calendar scope granted to connected accounts',
                '✓', 'Mentor interview duration and application timezone configured',
                'Google verification test',
                'Participant Calendar integration:', 'Enabled',
                'Calendar authorization for this Admin:', 'Granted',
                'Required scope:', GoogleCalendarService::CALENDAR_SCOPE,
                'href="'.route('admin.settings.google.verification.connect').'"', 'Authorize Calendar for Verification',
                'Configuration test',
                'method="POST" action="'.route('admin.settings.google-drive.test').'"', 'name="_token"', 'Test Configuration',
            ], false)
            ->assertDontSee('client-id.apps')
            ->assertDontSee('super-secret')
            ->assertDontSee('admin-access-token');
    }

    public function test_google_page_shows_what_an_unconfigured_integration_still_needs(): void
    {
        $this->configureGoogle(null);

        $this->actingAs($this->admin())->get(route('admin.settings.google'))
            ->assertOk()
            ->assertSeeInOrder([
                'OAUTH', 'border-danger', 'Needs Configuration',
                'GOOGLE DRIVE', 'border-danger', 'Needs Configuration',
                'CALENDAR / MEET', 'Needs application configuration',
                'Client ID', 'Not configured',
                'Client Secret', 'Not configured',
                '<strong>Legacy Calendar scope setting:</strong> Disabled',
                '✗', 'OAuth client configuration required',
                '✗', 'Calendar application configuration required',
                'Current participant authorization: Unknown — no participant authorization to inspect',
                'Participant Calendar integration:', 'Disabled',
                'Calendar authorization for this Admin:', 'Not granted',
            ], false);
    }

    public function test_google_page_shows_the_verification_status_and_error_messages(): void
    {
        $admin = $this->admin();
        $pending = ['state' => 'expected-state', 'user_id' => $admin->id, 'expires_at' => now()->addMinutes(10)->timestamp];

        $this->actingAs($admin)
            ->withSession(['google_verification_oauth' => $pending])
            ->followingRedirects()
            ->get(route('admin.settings.google.verification.callback', ['state' => 'expected-state', 'error' => 'access_denied']))
            ->assertOk()
            ->assertSeeInOrder(['Google Integration', 'role="status"', 'Google Calendar verification authorization was cancelled.', 'Overall status'], false);

        $this->actingAs($admin)
            ->followingRedirects()
            ->get(route('admin.settings.google.verification.callback', ['state' => 'forged', 'code' => 'code']))
            ->assertOk()
            ->assertSeeInOrder(['Google Integration', 'role="alert"', 'Google verification authorization could not be verified. Please try again.', 'Overall status'], false);
    }

    public function test_email_page_shows_the_saved_configuration_in_the_summary_and_the_form_without_the_password(): void
    {
        app(EmailConfigurationService::class)->save([
            'mailer' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_scheme' => 'tls',
            'smtp_username' => 'mailer@example.test', 'smtp_password' => 'stored-smtp-password', 'from_address' => 'qa@example.test',
            'from_name' => 'QA Training Platform', 'mentor_emails_enabled' => false,
        ]);

        $this->actingAs($this->admin())->get(route('admin.settings.email'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN SETTINGS · EMAIL',
                'Email configuration',
                'id="email-settings-main-action"', 'form="email-settings-form"', 'Save Email Configuration',
                'MAILER', 'SMTP',
                'STATUS', 'border-emerald-400', 'Configured',
                'MENTOR INTERVIEW EMAILS', 'Disabled',
                'From address:', 'qa@example.test',
                'From name:', 'QA Training Platform',
                'Delivery readiness:', 'Configured',
                'Configure delivery',
                'id="email-settings-form" method="POST" action="'.route('admin.settings.email.update').'"', 'name="_method" value="PUT"',
                '<option value="smtp" selected>SMTP</option>',
                'id="smtp_host" name="smtp_host" value="smtp.example.test"',
                'id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="587"',
                '<option value="tls" selected>TLS / STARTTLS</option>',
                'id="smtp_username" name="smtp_username" value="mailer@example.test"',
                'id="smtp_password" name="smtp_password" type="password"', 'Leave blank to keep the existing password. ✓ Password configured.',
                'name="clear_smtp_password" value="1"', 'Clear the stored SMTP password',
                'id="from_address" name="from_address" type="email" value="qa@example.test"',
                'id="from_name" name="from_name" value="QA Training Platform"',
                'name="mentor_emails_enabled" value="0"', 'name="mentor_emails_enabled" value="1"', 'Enable Mentor interview emails',
                'Send test email',
                'method="POST" action="'.route('admin.settings.email.test').'"',
                'id="test-recipient" name="recipient" type="email" value=""',
                'bg-raised', 'Send Test Email',
            ], false)
            ->assertDontSee('name="mentor_emails_enabled" value="1" checked', false)
            ->assertDontSee('stored-smtp-password');
    }

    public function test_email_page_falls_back_to_the_default_configuration(): void
    {
        config(['mail.default' => 'log', 'mail.from.address' => '', 'mail.from.name' => '', 'mail.mailers.smtp.password' => null]);

        $this->actingAs($this->admin())->get(route('admin.settings.email'))
            ->assertOk()
            ->assertSeeInOrder([
                'MAILER', 'LOG',
                'STATUS', 'border-danger', 'Development Only / Needs Configuration',
                'MENTOR INTERVIEW EMAILS', 'Enabled',
                'From address:', 'Not configured',
                'From name:', 'Not configured',
                '<option value="log" selected>Log (development)</option>',
                'Leave blank to keep the existing password. No password configured.',
                'name="mentor_emails_enabled" value="1" checked',
            ], false);
    }

    public function test_email_page_shows_the_saved_status_and_a_rejected_save_with_the_old_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.settings.email'))
            ->followingRedirects()
            ->put(route('admin.settings.email.update'), ['mailer' => 'log', 'from_address' => 'saved@example.test', 'from_name' => 'Saved Sender', 'mentor_emails_enabled' => '1'])
            ->assertOk()
            ->assertSeeInOrder(['role="status"', 'Email configuration saved securely.', 'From address:', 'saved@example.test'], false);

        $this->actingAs($admin)
            ->from(route('admin.settings.email'))
            ->followingRedirects()
            ->put(route('admin.settings.email.update'), ['mailer' => 'smtp', 'smtp_password' => 'rejected-password', 'from_address' => 'not-an-email', 'from_name' => 'Rejected Sender', 'mentor_emails_enabled' => '0'])
            ->assertOk()
            ->assertSeeInOrder([
                'role="alert"',
                '<option value="smtp" selected>SMTP</option>',
                'value="not-an-email"',
                'value="Rejected Sender"',
            ], false)
            ->assertDontSee('name="mentor_emails_enabled" value="1" checked', false)
            ->assertDontSee('rejected-password');
    }

    public function test_training_page_shows_the_read_only_limits_in_megabytes(): void
    {
        config([
            'training.week_four_mentor_interview_timezone' => 'Asia/Manila',
            'training.week_four_mentor_interview_duration_minutes' => 45,
            'training.evidence_max_kb' => 1024,
            'training.weekly_video_max_kb' => 2048,
        ]);

        $this->actingAs($this->admin())->get(route('admin.settings.training'))
            ->assertOk()
            ->assertSeeInOrder([
                'ADMIN SETTINGS · TRAINING',
                'Training settings',
                'APPLICATION TIMEZONE', 'Asia/Manila',
                'MENTOR INTERVIEW', '45 minutes',
                'PHP UPLOAD LIMIT', (string) ini_get('upload_max_filesize'),
                'Reusable AI Asset application limit:', '1 MB',
                'Weekly Video application limit:', '2 MB',
                'General evidence application limit:', '1 MB',
                'Current PHP post limit:', (string) ini_get('post_max_size'),
                'Effective Reusable Asset limit:', '1 MB',
                'Effective Weekly Video limit:', '2 MB',
                'Application settings do not edit php.ini or server runtime configuration.',
            ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'training_path' => null]);
    }

    private function configureGoogle(?string $clientId): void
    {
        config([
            'services.google_drive.client_id' => $clientId,
            'services.google_drive.client_secret' => $clientId ? 'super-secret' : null,
            'services.google_drive.redirect_uri' => 'https://example.test/profile/google-drive/callback',
            'services.google_drive.enabled' => true,
        ]);
    }
}
