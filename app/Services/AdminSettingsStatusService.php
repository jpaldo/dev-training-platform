<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use Illuminate\Support\Str;

class AdminSettingsStatusService
{
    public function __construct(
        private readonly GoogleDriveConfigurationService $google,
        private readonly AiProviderConfigurationService $ai,
        private readonly EmailConfigurationService $emailConfiguration,
    ) {}

    /** @return array<string, mixed> */
    public function google(): array
    {
        $settings = $this->google->adminValues();
        $oauthConfigured = $this->google->isOAuthConfigured();
        $calendarConfigured = $settings['calendar_scope'] === GoogleCalendarService::CALENDAR_SCOPE;
        $connections = GoogleDriveConnection::query()->get(['scopes']);
        $connectionsMissingCalendar = $connections->contains(fn (GoogleDriveConnection $connection): bool => ! in_array(GoogleCalendarService::CALENDAR_SCOPE, $connection->scopes ?? [], true));
        $authorizationStatus = $connections->isEmpty()
            ? 'Unknown — no participant authorization to inspect'
            : ($connectionsMissingCalendar ? 'Reauthorization required for some connections' : 'Calendar scope granted to connected accounts');
        $verificationConnection = auth()->user()?->googleDriveConnection;
        $verificationStatus = $verificationConnection && in_array(GoogleCalendarService::CALENDAR_SCOPE, $verificationConnection->scopes ?? [], true)
            ? 'Granted'
            : 'Not granted';

        return [
            'settings' => $settings,
            'oauth_configured' => $oauthConfigured,
            'calendar_configured' => $calendarConfigured,
            'calendar_scope' => GoogleCalendarService::CALENDAR_SCOPE,
            // Application configuration cannot prove Google Cloud consent or
            // sensitive-scope verification. Keep this false until a real
            // user authorization/API check exists.
            'calendar_ready' => false,
            'calendar_application_configured' => $oauthConfigured && $this->google->isEnabled() && $calendarConfigured,
            'calendar_status' => $oauthConfigured && $this->google->isEnabled() && $calendarConfigured
                ? 'Application configured — authorization required'
                : 'Needs application configuration',
            'authorization_status' => $authorizationStatus,
            'cloud_verification_status' => 'Requires manual Google Cloud Console verification',
            'connections_missing_calendar' => $connectionsMissingCalendar,
            'participant_calendar_enabled' => $settings['participant_calendar_enabled'],
            'verification_status' => $verificationStatus,
            'drive_ready' => $oauthConfigured && $this->google->isEnabled(),
            'redirect_uri' => $settings['redirect_uri'] ?: route('profile.google-drive.callback'),
            'masked_client_id' => $this->maskClientId($settings['client_id']),
            'reauthorization_warning' => $calendarConfigured,
        ];
    }

    /** @return array<string, mixed> */
    public function email(): array
    {
        $settings = $this->emailConfiguration->current();
        $readiness = $this->emailConfiguration->readiness();

        return [
            'mailer' => $settings['mailer'],
            'from_address' => $settings['from_address'],
            'from_name' => $settings['from_name'],
            'development_only' => $readiness['development_only'],
            'ready' => $readiness['ready'],
            'status' => $readiness['status'],
            'mentor_emails_enabled' => $settings['mentor_emails_enabled'],
            'smtp_host' => $settings['smtp_host'],
            'smtp_port' => $settings['smtp_port'],
            'smtp_scheme' => $settings['smtp_scheme'],
            'smtp_username' => $settings['smtp_username'],
            'has_smtp_password' => $settings['has_smtp_password'],
        ];
    }

    /** @return array<string, mixed> */
    public function training(): array
    {
        $applicationEvidence = (int) config('training.evidence_max_kb', 307200) * 1024;
        $applicationVideo = (int) config('training.weekly_video_max_kb', 358400) * 1024;
        $phpUpload = $this->parseIniBytes((string) ini_get('upload_max_filesize'));
        $phpPost = $this->parseIniBytes((string) ini_get('post_max_size'));
        $phpLimit = min(array_filter([$phpUpload, $phpPost], fn (?int $value): bool => $value > 0) ?: [$applicationVideo]);

        return [
            'timezone' => config('training.week_four_mentor_interview_timezone', config('app.timezone')),
            'mentor_duration' => (int) config('training.week_four_mentor_interview_duration_minutes', 60),
            'evidence_limit' => $applicationEvidence,
            'asset_limit' => $applicationEvidence,
            'video_limit' => $applicationVideo,
            'php_upload_limit' => $phpLimit,
            'php_upload_display' => ini_get('upload_max_filesize') ?: 'Unavailable',
            'php_post_display' => ini_get('post_max_size') ?: 'Unavailable',
            'effective_asset_limit' => min($applicationEvidence, $phpLimit),
            'effective_video_limit' => min($applicationVideo, $phpLimit),
        ];
    }

    /** @return array<string, mixed> */
    public function ai(): array
    {
        return $this->ai->adminValues();
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $google = $this->google();
        $email = $this->email();
        $ai = $this->ai();
        $training = $this->training();

        return compact('google', 'email', 'ai', 'training');
    }

    private function maskClientId(?string $clientId): string
    {
        if (blank($clientId)) {
            return 'Not configured';
        }

        return Str::mask($clientId, '•', 0, max(0, mb_strlen($clientId) - 4));
    }

    private function parseIniBytes(string $value): int
    {
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (float) $value;
        $unit = strtolower(substr(trim($value), -1));

        return (int) ($number * match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        });
    }
}
