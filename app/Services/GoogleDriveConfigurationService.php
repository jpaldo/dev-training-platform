<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Crypt;

class GoogleDriveConfigurationService
{
    public const CLIENT_ID = 'google_drive.client_id';

    public const CLIENT_SECRET = 'google_drive.client_secret';

    public const REDIRECT_URI = 'google_drive.redirect_uri';

    public const ENABLED = 'google_drive.enabled';

    public const AUTOMATIC_EXPORT = 'google_drive.automatic_export';

    public const PICKER_API_KEY = 'google_drive.picker_api_key';

    public const PROJECT_NUMBER = 'google_drive.project_number';

    /** @var array<int, string> */
    public const BASE_SCOPES = ['openid', 'email', 'profile', GoogleDriveConnectionService::DRIVE_FILE_SCOPE];

    public function getClientId(): ?string
    {
        return $this->value(self::CLIENT_ID) ?? config('services.google_drive.client_id');
    }

    public function getClientSecret(): ?string
    {
        $setting = $this->setting(self::CLIENT_SECRET);
        if ($setting) {
            return $setting->is_encrypted && filled($setting->value)
                ? Crypt::decryptString($setting->value)
                : $setting->value;
        }

        return config('services.google_drive.client_secret');
    }

    public function getRedirectUri(): ?string
    {
        return $this->value(self::REDIRECT_URI) ?? config('services.google_drive.redirect_uri');
    }

    public function getPickerApiKey(): ?string
    {
        $setting = $this->setting(self::PICKER_API_KEY);
        if ($setting) {
            return $setting->is_encrypted && filled($setting->value)
                ? Crypt::decryptString($setting->value)
                : $setting->value;
        }

        return config('services.google_drive.picker_api_key');
    }

    public function getProjectNumber(): ?string
    {
        return $this->value(self::PROJECT_NUMBER) ?? config('services.google_drive.project_number');
    }

    /** @return array<int, string> */
    public function getScopes(): array
    {
        return config('services.google_drive.scopes', self::BASE_SCOPES);
    }

    public function isEnabled(): bool
    {
        return $this->boolean(self::ENABLED, (bool) config('services.google_drive.enabled'));
    }

    public function isOAuthConfigured(): bool
    {
        return filled($this->getClientId())
            && filled($this->getClientSecret())
            && filter_var($this->getRedirectUri(), FILTER_VALIDATE_URL) !== false;
    }

    public function canConnect(): bool
    {
        return $this->isEnabled() && $this->isOAuthConfigured();
    }

    public function isAutomaticExportEnabled(): bool
    {
        return $this->boolean(self::AUTOMATIC_EXPORT);
    }

    public function adminValues(): array
    {
        return [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'enabled' => $this->isEnabled(),
            'automatic_export' => $this->isAutomaticExportEnabled(),
            'scopes' => $this->getScopes(),
            'has_client_secret' => filled($this->getClientSecret()),
            'has_picker_api_key' => filled($this->getPickerApiKey()),
            'picker_api_key_source' => $this->setting(self::PICKER_API_KEY)
                ? 'admin'
                : (filled(config('services.google_drive.picker_api_key')) ? 'environment' : null),
            'project_number' => $this->getProjectNumber(),
            'project_number_source' => $this->setting(self::PROJECT_NUMBER)
                ? 'admin'
                : (filled(config('services.google_drive.project_number')) ? 'environment' : null),
        ];
    }

    public function save(array $values): void
    {
        $this->put(self::CLIENT_ID, trim($values['client_id']));
        $this->put(self::REDIRECT_URI, trim($values['redirect_uri']));
        $this->put(self::ENABLED, $values['enabled'] ? '1' : '0');
        $this->put(self::AUTOMATIC_EXPORT, $values['automatic_export'] ? '1' : '0');

        if (filled($values['client_secret'] ?? null)) {
            $this->put(self::CLIENT_SECRET, Crypt::encryptString(trim($values['client_secret'])), true);
        }

        if (filled($values['picker_api_key'] ?? null)) {
            $this->put(self::PICKER_API_KEY, Crypt::encryptString(trim($values['picker_api_key'])), true);
        }

        if (filled($values['project_number'] ?? null)) {
            $this->put(self::PROJECT_NUMBER, trim($values['project_number']));
        }
    }

    /**
     * What a participant page needs to open the Google Picker for a `folder` (the profile) or a `video` (the weekly
     * video page): the browser API key, the project number as app ID, the URL of the short-lived token, and why the
     * Picker cannot open (null when it can): it is not configured, or Google Drive cannot connect.
     *
     * @return array{api_key: ?string, app_id: ?string, token_url: string, unavailable_message: ?string}
     */
    public function pickerSettings(string $selection): array
    {
        $apiKey = $this->getPickerApiKey();
        $appId = $this->getProjectNumber();

        return [
            'api_key' => $apiKey,
            'app_id' => $appId,
            'token_url' => route('profile.google-drive.picker-token'),
            'unavailable_message' => match (true) {
                blank($apiKey) || blank($appId) => "Google Drive {$selection} selection is not configured. Please contact an administrator.",
                ! $this->canConnect() => "Google Drive {$selection} selection is unavailable because Google Drive cannot connect right now. Please contact an administrator.",
                default => null,
            },
        ];
    }

    public function validateConfiguration(): array
    {
        $missing = [];
        foreach (['client_id' => $this->getClientId(), 'client_secret' => $this->getClientSecret(), 'redirect_uri' => $this->getRedirectUri()] as $key => $value) {
            if (blank($value)) {
                $missing[] = $key;
            }
        }

        $redirect = $this->getRedirectUri();
        if (filled($redirect) && ! filter_var($redirect, FILTER_VALIDATE_URL)) {
            $missing[] = 'redirect_uri';
        }

        return ['valid' => $missing === [], 'missing' => array_values(array_unique($missing))];
    }

    private function setting(string $key): ?ApplicationSetting
    {
        return ApplicationSetting::query()->where('key', $key)->first();
    }

    private function value(string $key): ?string
    {
        return $this->setting($key)?->value;
    }

    private function boolean(string $key, bool $fallback = false): bool
    {
        $value = $this->value($key);

        return $value === null ? $fallback : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function put(string $key, ?string $value, bool $encrypted = false): void
    {
        ApplicationSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'is_encrypted' => $encrypted],
        );
    }
}
