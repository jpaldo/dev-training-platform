<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class EmailConfigurationService
{
    public const MAILER = 'mail.mailer';

    public const SMTP_HOST = 'mail.smtp.host';

    public const SMTP_PORT = 'mail.smtp.port';

    public const SMTP_SCHEME = 'mail.smtp.scheme';

    public const SMTP_USERNAME = 'mail.smtp.username';

    public const SMTP_PASSWORD = 'mail.smtp.password';

    public const FROM_ADDRESS = 'mail.from.address';

    public const FROM_NAME = 'mail.from.name';

    public const MENTOR_EMAILS_ENABLED = 'mail.mentor_emails_enabled';

    /** @return array<string, mixed> */
    public function current(): array
    {
        return [
            'mailer' => (string) ($this->value(self::MAILER) ?? config('mail.default', 'log')),
            'smtp_host' => (string) ($this->value(self::SMTP_HOST) ?? config('mail.mailers.smtp.host', '')),
            'smtp_port' => (int) ($this->value(self::SMTP_PORT) ?? config('mail.mailers.smtp.port', 2525)),
            'smtp_scheme' => $this->value(self::SMTP_SCHEME) ?? config('mail.mailers.smtp.scheme'),
            'smtp_username' => (string) ($this->value(self::SMTP_USERNAME) ?? config('mail.mailers.smtp.username', '')),
            'has_smtp_password' => filled($this->secret(self::SMTP_PASSWORD) ?? config('mail.mailers.smtp.password')),
            'from_address' => (string) ($this->value(self::FROM_ADDRESS) ?? config('mail.from.address', '')),
            'from_name' => (string) ($this->value(self::FROM_NAME) ?? config('mail.from.name', '')),
            'mentor_emails_enabled' => $this->boolean(self::MENTOR_EMAILS_ENABLED, true),
        ];
    }

    public function applyRuntimeConfiguration(): void
    {
        $settings = $this->current();

        config([
            'mail.default' => $settings['mailer'],
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
            'mail.mailers.smtp.scheme' => $settings['smtp_scheme'],
            'mail.mailers.smtp.host' => $settings['smtp_host'],
            'mail.mailers.smtp.port' => $settings['smtp_port'],
            'mail.mailers.smtp.username' => $settings['smtp_username'],
            'mail.mailers.smtp.password' => $this->secret(self::SMTP_PASSWORD) ?? config('mail.mailers.smtp.password'),
        ]);
    }

    public function isMentorInterviewEmailEnabled(): bool
    {
        return $this->boolean(self::MENTOR_EMAILS_ENABLED, true);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        $this->put(self::MAILER, $values['mailer']);
        $this->put(self::FROM_ADDRESS, trim($values['from_address']));
        $this->put(self::FROM_NAME, trim($values['from_name']));
        $this->put(self::MENTOR_EMAILS_ENABLED, $values['mentor_emails_enabled'] ? '1' : '0');

        if ($values['mailer'] === 'smtp') {
            $this->put(self::SMTP_HOST, trim($values['smtp_host'] ?? ''));
            $this->put(self::SMTP_PORT, (string) $values['smtp_port']);
            $this->put(self::SMTP_SCHEME, $values['smtp_scheme'] ?: null);
            $this->put(self::SMTP_USERNAME, trim($values['smtp_username'] ?? ''));

            if (($values['clear_smtp_password'] ?? false) === true) {
                $this->put(self::SMTP_PASSWORD, null, true);
            } elseif (filled($values['smtp_password'] ?? null)) {
                $this->put(self::SMTP_PASSWORD, Crypt::encryptString($values['smtp_password']), true);
            }
        }

        $this->applyRuntimeConfiguration();
    }

    /** @return array{ready: bool, status: string, development_only: bool} */
    public function readiness(): array
    {
        $settings = $this->current();
        $developmentOnly = in_array($settings['mailer'], ['log', 'array'], true);
        $ready = match ($settings['mailer']) {
            'smtp' => filled($settings['smtp_host']) && $settings['smtp_port'] > 0,
            'sendmail' => true,
            default => false,
        };

        return [
            'ready' => $ready,
            'development_only' => $developmentOnly,
            'status' => $developmentOnly ? 'Development Only / Needs Configuration' : ($ready ? 'Configured' : 'Needs Configuration'),
        ];
    }

    private function secret(string $key): ?string
    {
        $setting = $this->setting($key);
        if (! $setting || blank($setting->value)) {
            return null;
        }

        return $setting->is_encrypted ? Crypt::decryptString($setting->value) : $setting->value;
    }

    private function value(string $key): ?string
    {
        return $this->setting($key)?->value;
    }

    private function boolean(string $key, bool $fallback): bool
    {
        $value = $this->value($key);

        return $value === null ? $fallback : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function put(string $key, ?string $value, bool $encrypted = false): void
    {
        $this->ensureSettingsTable();
        ApplicationSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'is_encrypted' => $encrypted]);
    }

    private function setting(string $key): ?ApplicationSetting
    {
        if (! $this->settingsTableExists()) {
            return null;
        }

        return ApplicationSetting::query()->where('key', $key)->first();
    }

    private function settingsTableExists(): bool
    {
        return Schema::hasTable('application_settings');
    }

    private function ensureSettingsTable(): void
    {
        if (! $this->settingsTableExists()) {
            throw new \RuntimeException('Application settings storage is not available.');
        }
    }
}
