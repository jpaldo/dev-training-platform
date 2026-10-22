<?php

namespace App\Services;

use App\Enums\InterviewerApprovalStatus;
use App\Enums\InterviewType;
use App\Enums\UserRole;
use App\Models\GoogleDriveConnection;
use App\Models\InterviewScheduler;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdminSettingsPageService
{
    private const BYTES_PER_MEGABYTE = 1048576;

    /**
     * The model field label and hint for each AI provider, shown when the provider is selected.
     *
     * @var array<string, array{label: string, help: string}>
     */
    private const AI_MODEL_FIELDS = [
        'openrouter' => ['label' => 'OpenRouter model', 'help' => 'Enter the OpenRouter model slug.'],
        'openai' => ['label' => 'OpenAI model ID', 'help' => 'Enter the OpenAI model ID.'],
        'anthropic' => ['label' => 'Anthropic model ID', 'help' => 'Enter the Anthropic model ID.'],
        'gemini' => ['label' => 'Gemini model ID', 'help' => 'Enter the Gemini model ID.'],
    ];

    private const MASKED_SECRET_PLACEHOLDER = '••••••••••••';

    public function __construct(
        private readonly AdminSettingsStatusService $status,
        private readonly GoogleDriveConfigurationService $googleDrive,
        private readonly InterviewBookingLinkService $interviewBookingLinks,
    ) {}

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
     * The AI scoring settings page: the saved form values, each provider with its model field copy and whether it
     * has a stored API key and model, the API key placeholder and hint, and the status summary. The API key itself
     * is never returned.
     *
     * @return array{settings: array{enabled: bool, provider: string, model: string}, providers: list<array{value: string, label: string, modelLabel: string, modelHelp: string, hasStoredApiKey: bool}>, apiKeyPlaceholder: string, apiKeyHint: string, statusSummary: string}
     */
    public function aiData(): array
    {
        $ai = $this->status->ai();
        $providers = [];

        foreach (AiProviderConfigurationService::PROVIDERS as $provider => $label) {
            $providers[] = [
                'value' => $provider,
                'label' => $label,
                'modelLabel' => self::AI_MODEL_FIELDS[$provider]['label'],
                'modelHelp' => self::AI_MODEL_FIELDS[$provider]['help'],
                'hasStoredApiKey' => (bool) $ai['configured_providers'][$provider],
            ];
        }

        return [
            'settings' => [
                'enabled' => (bool) $ai['enabled'],
                'provider' => (string) $ai['provider'],
                'model' => (string) $ai['model'],
            ],
            'providers' => $providers,
            'apiKeyPlaceholder' => $ai['has_api_key'] ? 'Configured — enter a new key to replace it' : 'Enter API key',
            'apiKeyHint' => $ai['has_api_key'] ? 'Configured for this provider. Leave blank to retain it.' : 'Stored encrypted. It is never returned to the browser.',
            'statusSummary' => implode(' · ', [
                'Status: '.$ai['status'],
                'Provider: '.$ai['provider_label'],
                'Model: '.($ai['model'] ?: 'Not configured'),
                'Configuration source: '.ucfirst((string) $ai['source']),
                'API key: '.($ai['has_api_key'] ? 'Configured' : 'Not configured'),
            ]),
        ];
    }

    /**
     * The Google Drive integration page: the configuration, OAuth and participant connection statuses (participants'
     * connections only, not the Admin's or coaches' Calendar connections), the saved form values, the secret
     * placeholders (secrets only as configured / not configured), the Picker key and project number sources and the
     * configured scopes.
     *
     * @return array{statuses: list<array{label: string, value: string, tone: ?string}>, settings: array{client_id: ?string, project_number: ?string, redirect_uri: ?string, enabled: bool, automatic_export: bool}, clientSecretPlaceholder: string, pickerApiKeyPlaceholder: string, pickerApiKeyStatus: string, projectNumberStatus: string, scopes: string}
     */
    public function googleDriveData(): array
    {
        $settings = $this->googleDrive->adminValues();
        $isConfigurationValid = $this->googleDrive->validateConfiguration()['valid'];
        $isOAuthComplete = $settings['client_id'] && $settings['has_client_secret'] && $settings['redirect_uri'];

        return [
            'statuses' => [
                ['label' => 'STATUS', 'value' => $isConfigurationValid ? 'Configured' : 'Not Configured', 'tone' => $isConfigurationValid ? 'success' : 'danger'],
                ['label' => 'OAUTH', 'value' => $isOAuthComplete ? 'Configured' : 'Missing', 'tone' => $isOAuthComplete ? 'success' : 'danger'],
                ['label' => 'PARTICIPANT CONNECTIONS', 'value' => $this->participantDriveConnectionCount().' connected', 'tone' => null],
            ],
            'settings' => [
                'client_id' => $settings['client_id'],
                'project_number' => $settings['project_number'],
                'redirect_uri' => $settings['redirect_uri'],
                'enabled' => $settings['enabled'],
                'automatic_export' => $settings['automatic_export'],
            ],
            'clientSecretPlaceholder' => $settings['has_client_secret'] ? self::MASKED_SECRET_PLACEHOLDER : 'Enter client secret',
            'pickerApiKeyPlaceholder' => $settings['has_picker_api_key'] ? self::MASKED_SECRET_PLACEHOLDER : 'Enter Picker API key',
            'pickerApiKeyStatus' => $this->sourceStatus($settings['picker_api_key_source'], $settings['has_picker_api_key']),
            'projectNumberStatus' => $this->sourceStatus($settings['project_number_source'], (bool) $settings['project_number']),
            'scopes' => implode(', ', $settings['scopes']),
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
     * The interview scheduling page: the public page title and logo status, the interview type and platform user
     * options of the Add Interviewer form, the pending public requests (newest first) and the approved Mock Interview
     * and Final Re-assessment interviewers (an interviewer of both types is listed in both sections).
     *
     * @return array{publicTitle: string, logoConfigured: bool, logoStatus: string, interviewTypes: list<array{value: string, label: string, offers_mock: bool, offers_final: bool}>, users: list<array{id: int, label: string}>, pendingInterviewers: list<array{id: int, fullName: string, interviewType: string, mockBookingUrl: string, finalBookingUrl: string, submittedAt: ?string, updateUrl: string, approveUrl: string, rejectUrl: string}>, sections: list<array{key: string, title: string, emptyMessage: string, interviewers: list<array{id: int, fullName: string, email: ?string, interviewType: string, mockBookingUrl: string, finalBookingUrl: string, displayOrder: int, summary: string, toggleLabel: string, updateUrl: string, toggleUrl: string, destroyUrl: string}>}>}
     */
    public function interviewSchedulingData(): array
    {
        $logoConfigured = filled($this->interviewBookingLinks->logoPath());

        return [
            'publicTitle' => $this->interviewBookingLinks->publicTitle(),
            'logoConfigured' => $logoConfigured,
            'logoStatus' => $logoConfigured
                ? 'A public logo is configured.'
                : 'No logo configured; the public page uses the configured title as its branding fallback.',
            'interviewTypes' => InterviewType::options(),
            'users' => User::query()
                ->whereIn('role', [UserRole::Coach, UserRole::Admin])
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => ['id' => $user->id, 'label' => $user->name.' ('.$user->email.')'])
                ->all(),
            'pendingInterviewers' => InterviewScheduler::query()
                ->where('approval_status', InterviewerApprovalStatus::Pending)
                ->latest('created_at')
                ->get()
                ->map($this->pendingInterviewer(...))
                ->all(),
            'sections' => [
                [
                    'key' => InterviewType::Mock->value,
                    'title' => 'Mock Interview',
                    'emptyMessage' => 'No mock interview interviewers configured.',
                    'interviewers' => $this->approvedInterviewers(InterviewType::Mock),
                ],
                [
                    'key' => InterviewType::FinalReassessment->value,
                    'title' => 'Final Re-assessment Interview',
                    'emptyMessage' => 'No final re-assessment interview interviewers configured.',
                    'interviewers' => $this->approvedInterviewers(InterviewType::FinalReassessment),
                ],
            ],
        ];
    }

    /**
     * The number of participants with a Google Drive connection.
     */
    private function participantDriveConnectionCount(): int
    {
        return GoogleDriveConnection::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('role', UserRole::Participant))
            ->count();
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

    /**
     * "Configured via environment" when the value comes only from the environment, otherwise whether it is set.
     */
    private function sourceStatus(?string $source, bool $isConfigured): string
    {
        if ($source === 'environment') {
            return 'Configured via environment';
        }

        return $isConfigured ? 'Configured' : 'Not configured';
    }

    /**
     * The approved interviewers of one interview type, including those of both types, by display order then name.
     *
     * @return list<array{id: int, fullName: string, email: ?string, interviewType: string, mockBookingUrl: string, finalBookingUrl: string, displayOrder: int, summary: string, toggleLabel: string, updateUrl: string, toggleUrl: string, destroyUrl: string}>
     */
    private function approvedInterviewers(InterviewType $interviewType): array
    {
        return InterviewScheduler::query()
            ->whereIn('interview_type', InterviewType::offering($interviewType))
            ->where('approval_status', InterviewerApprovalStatus::Approved)
            ->orderBy('display_order')
            ->orderBy('full_name')
            ->get()
            ->map(fn (InterviewScheduler $interviewer): array => [
                'id' => $interviewer->id,
                'fullName' => $interviewer->full_name,
                'email' => $interviewer->email,
                'interviewType' => $interviewer->interview_type->value,
                'mockBookingUrl' => $this->mockBookingUrl($interviewer),
                'finalBookingUrl' => $this->finalBookingUrl($interviewer),
                'displayOrder' => $interviewer->display_order,
                'summary' => ($interviewer->user_id ? 'Registered platform user' : 'External interviewer').' · '.($interviewer->is_active ? 'Active' : 'Inactive'),
                'toggleLabel' => $interviewer->is_active ? 'Deactivate' : 'Activate',
                'updateUrl' => route('admin.settings.interview-scheduling.update', $interviewer),
                'toggleUrl' => route('admin.settings.interview-scheduling.toggle', $interviewer),
                'destroyUrl' => route('admin.settings.interview-scheduling.destroy', $interviewer),
            ])
            ->all();
    }

    /**
     * @return array{id: int, fullName: string, interviewType: string, mockBookingUrl: string, finalBookingUrl: string, submittedAt: ?string, updateUrl: string, approveUrl: string, rejectUrl: string}
     */
    private function pendingInterviewer(InterviewScheduler $interviewer): array
    {
        return [
            'id' => $interviewer->id,
            'fullName' => $interviewer->full_name,
            'interviewType' => $interviewer->interview_type->value,
            'mockBookingUrl' => $this->mockBookingUrl($interviewer),
            'finalBookingUrl' => $this->finalBookingUrl($interviewer),
            'submittedAt' => $interviewer->created_at?->format('F j, Y g:i A'),
            'updateUrl' => route('admin.settings.interview-scheduling.update', $interviewer),
            'approveUrl' => route('admin.settings.interview-scheduling.approve', $interviewer),
            'rejectUrl' => route('admin.settings.interview-scheduling.reject', $interviewer),
        ];
    }

    /**
     * The Mock Interview link, falling back to the legacy single link unless the interviewer is Final Re-assessment only.
     */
    private function mockBookingUrl(InterviewScheduler $interviewer): string
    {
        return (string) ($interviewer->mock_booking_url
            ?: ($interviewer->interview_type->offers(InterviewType::Mock) ? $interviewer->booking_url : ''));
    }

    /**
     * The Final Re-assessment link, falling back to the legacy single link unless the interviewer is Mock Interview only.
     */
    private function finalBookingUrl(InterviewScheduler $interviewer): string
    {
        return (string) ($interviewer->final_booking_url
            ?: ($interviewer->interview_type->offers(InterviewType::FinalReassessment) ? $interviewer->booking_url : ''));
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / self::BYTES_PER_MEGABYTE, 0).' MB';
    }
}
