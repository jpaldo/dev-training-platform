<?php

namespace App\Services;

class AdminSettingsPageService
{
    private const BYTES_PER_MEGABYTE = 1048576;

    public function __construct(private readonly AdminSettingsStatusService $status) {}

    /**
     * The settings overview: one section per settings area with its current status and the link to manage it.
     * The page has no single main action, so every link is secondary.
     *
     * @return array{sections: list<array{key: string, eyebrow: string, status: string, description: string, url: string, linkLabel: string}>}
     */
    public function indexData(): array
    {
        $overview = $this->status->overview();

        return [
            'sections' => [
                [
                    'key' => 'ai',
                    'eyebrow' => 'AI CONFIGURATION',
                    'status' => (string) $overview['ai']['status'],
                    'description' => 'Configure AI providers, models, and credentials.',
                    'url' => route('admin.settings.ai'),
                    'linkLabel' => 'Manage AI Settings',
                ],
                [
                    'key' => 'google',
                    'eyebrow' => 'GOOGLE INTEGRATION',
                    'status' => (string) $overview['google']['calendar_status'],
                    'description' => 'Drive, Picker, Calendar, and Google Meet scheduling configuration.',
                    'url' => route('admin.settings.google'),
                    'linkLabel' => 'Manage Google Settings',
                ],
                [
                    'key' => 'email',
                    'eyebrow' => 'EMAIL',
                    'status' => (string) $overview['email']['status'],
                    'description' => 'Current mail transport and Mentor scheduling email readiness.',
                    'url' => route('admin.settings.email'),
                    'linkLabel' => 'Manage Email Settings',
                ],
                [
                    'key' => 'training',
                    'eyebrow' => 'TRAINING SETTINGS',
                    'status' => (string) $overview['training']['timezone'],
                    'description' => 'Mentor interview duration and application/server upload limits.',
                    'url' => route('admin.settings.training'),
                    'linkLabel' => 'Manage Training Settings',
                ],
                [
                    'key' => 'interview-scheduling',
                    'eyebrow' => 'INTERVIEW SCHEDULING',
                    'status' => 'Public booking links',
                    'description' => 'Manage Mock Interview and Final Re-assessment interviewers and the public scheduling page.',
                    'url' => route('admin.settings.interview-scheduling'),
                    'linkLabel' => 'Manage Interview Scheduling',
                ],
            ],
        ];
    }

    /**
     * The Google integration page: the overall statuses (with a badge tone), the masked OAuth configuration (the
     * client secret only as configured / not configured), the Calendar settings and the Calendar readiness checks.
     *
     * @return array{statuses: list<array{label: string, value: string, tone: string}>, clientId: string, clientSecretStatus: string, redirectUri: string, scopes: list<string>, legacyCalendarSetting: string, calendarScope: string, readinessChecks: list<array{icon: string, text: string, tone: string}>, participantCalendarIntegration: string, verificationStatus: string}
     */
    public function googleData(): array
    {
        $google = $this->status->google();
        $calendarSetting = $google['participant_calendar_enabled'] ? 'Enabled' : 'Disabled';

        return [
            'statuses' => [
                $this->configurationStatus('OAUTH', $google['oauth_configured']),
                $this->configurationStatus('GOOGLE DRIVE', $google['drive_ready']),
                ['label' => 'CALENDAR / MEET', 'value' => $google['calendar_status'], 'tone' => 'warning'],
            ],
            'clientId' => $google['masked_client_id'],
            'clientSecretStatus' => $google['settings']['has_client_secret'] ? '✓ Configured' : 'Not configured',
            'redirectUri' => $google['redirect_uri'],
            'scopes' => array_values($google['settings']['scopes']),
            'legacyCalendarSetting' => $calendarSetting,
            'calendarScope' => $google['calendar_scope'],
            'readinessChecks' => [
                $google['oauth_configured']
                    ? ['icon' => '✓', 'text' => 'OAuth client configured', 'tone' => 'success']
                    : ['icon' => '✗', 'text' => 'OAuth client configuration required', 'tone' => 'danger'],
                $google['calendar_application_configured']
                    ? ['icon' => '✓', 'text' => 'Calendar integration code configured', 'tone' => 'success']
                    : ['icon' => '✗', 'text' => 'Calendar application configuration required', 'tone' => 'danger'],
                ['icon' => '⚠', 'text' => 'Google Cloud consent / verification: '.$google['cloud_verification_status'], 'tone' => 'warning'],
                ['icon' => '⚠', 'text' => 'Current participant authorization: '.$google['authorization_status'], 'tone' => 'warning'],
                ['icon' => '✓', 'text' => 'Mentor interview duration and application timezone configured', 'tone' => 'success'],
            ],
            'participantCalendarIntegration' => $calendarSetting,
            'verificationStatus' => $google['verification_status'],
        ];
    }

    /**
     * The email configuration page: the current delivery summary, the saved form values and whether an SMTP
     * password is stored. The password itself is never returned.
     *
     * @return array{summary: list<array{label: string, value: string, tone: ?string}>, details: list<array{label: string, value: string}>, settings: array{mailer: string, smtp_host: string, smtp_port: int, smtp_scheme: ?string, smtp_username: string, from_address: string, from_name: string, mentor_emails_enabled: bool}, passwordStatus: string}
     */
    public function emailData(): array
    {
        $email = $this->status->email();

        return [
            'summary' => [
                ['label' => 'MAILER', 'value' => strtoupper($email['mailer']), 'tone' => null],
                ['label' => 'STATUS', 'value' => $email['status'], 'tone' => $email['ready'] ? 'success' : 'danger'],
                ['label' => 'MENTOR INTERVIEW EMAILS', 'value' => $email['mentor_emails_enabled'] ? 'Enabled' : 'Disabled', 'tone' => null],
            ],
            'details' => [
                ['label' => 'From address:', 'value' => $email['from_address'] ?: 'Not configured'],
                ['label' => 'From name:', 'value' => $email['from_name'] ?: 'Not configured'],
                ['label' => 'Delivery readiness:', 'value' => $email['status']],
            ],
            'settings' => [
                'mailer' => $email['mailer'],
                'smtp_host' => $email['smtp_host'],
                'smtp_port' => $email['smtp_port'],
                'smtp_scheme' => $email['smtp_scheme'],
                'smtp_username' => $email['smtp_username'],
                'from_address' => $email['from_address'],
                'from_name' => $email['from_name'],
                'mentor_emails_enabled' => $email['mentor_emails_enabled'],
            ],
            'passwordStatus' => $email['has_smtp_password'] ? '✓ Password configured.' : 'No password configured.',
        ];
    }

    /**
     * The read-only training settings: the timezone, Mentor interview duration and PHP upload limit, then the
     * application and effective upload limits in whole megabytes.
     *
     * @return array{stats: list<array{label: string, value: string}>, limits: list<array{label: string, value: string}>}
     */
    public function trainingData(): array
    {
        $training = $this->status->training();

        return [
            'stats' => [
                ['label' => 'APPLICATION TIMEZONE', 'value' => (string) $training['timezone']],
                ['label' => 'MENTOR INTERVIEW', 'value' => $training['mentor_duration'].' minutes'],
                ['label' => 'PHP UPLOAD LIMIT', 'value' => (string) $training['php_upload_display']],
            ],
            'limits' => [
                ['label' => 'Reusable AI Asset application limit:', 'value' => $this->megabytes($training['asset_limit'])],
                ['label' => 'Weekly Video application limit:', 'value' => $this->megabytes($training['video_limit'])],
                ['label' => 'General evidence application limit:', 'value' => $this->megabytes($training['evidence_limit'])],
                ['label' => 'Current PHP post limit:', 'value' => (string) $training['php_post_display']],
                ['label' => 'Effective Reusable Asset limit:', 'value' => $this->megabytes($training['effective_asset_limit'])],
                ['label' => 'Effective Weekly Video limit:', 'value' => $this->megabytes($training['effective_video_limit'])],
            ],
        ];
    }

    /**
     * @return array{label: string, value: string, tone: string}
     */
    private function configurationStatus(string $label, bool $isConfigured): array
    {
        return [
            'label' => $label,
            'value' => $isConfigured ? 'Configured' : 'Needs Configuration',
            'tone' => $isConfigured ? 'success' : 'danger',
        ];
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / self::BYTES_PER_MEGABYTE, 0).' MB';
    }
}
