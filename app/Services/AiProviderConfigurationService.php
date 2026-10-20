<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Crypt;

class AiProviderConfigurationService
{
    public const ENABLED = 'ai_scoring.enabled';

    public const PROVIDER = 'ai_scoring.provider';

    public const MODEL = 'ai_scoring.model';

    public const API_KEY = 'ai_scoring.api_key';

    /** @var array<string,string> */
    public const PROVIDERS = [
        'openrouter' => 'OpenRouter',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'gemini' => 'Google Gemini',
    ];

    /** @return array{enabled:bool,provider:?string,model:?string,api_key:?string,source:string,configured:bool} */
    public function resolve(array $overrides = []): array
    {
        $adminProvider = $this->value(self::PROVIDER);
        $selectedProvider = $adminProvider ?? (string) config('services.ai_scoring.provider', 'openrouter');
        $adminModel = $this->modelForProvider($selectedProvider);
        $adminKey = $this->secretForProvider($selectedProvider);
        $hasAdmin = $adminProvider !== null || $adminModel !== null || $adminKey !== null || $this->value(self::ENABLED) !== null;
        $provider = strtolower(trim((string) ($overrides['provider'] ?? ($adminProvider ?? config('services.ai_scoring.provider')))));
        $model = $overrides['model'] ?? ($adminModel ?? config('services.ai_scoring.'.($provider === 'openrouter' ? 'model' : $provider.'_model')));
        $environmentKey = $this->environmentKey($provider);
        $key = array_key_exists('api_key', $overrides) && filled($overrides['api_key'])
            ? trim((string) $overrides['api_key'])
            : ($adminKey ?? config("services.ai_scoring.{$environmentKey}"));
        if (array_key_exists('api_key', $overrides) && blank($overrides['api_key'])) {
            $key = $this->secretForProvider($provider) ?? config("services.ai_scoring.{$environmentKey}");
        }
        $enabled = array_key_exists('enabled', $overrides)
            ? (bool) $overrides['enabled']
            : $this->boolean(self::ENABLED, (bool) config('services.ai_scoring.enabled'));

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'model' => $model,
            'api_key' => $key,
            'source' => $hasAdmin ? 'admin' : 'environment',
            'configured' => $enabled && isset(self::PROVIDERS[$provider]) && filled($model) && filled($key),
        ];
    }

    public function adminValues(): array
    {
        $resolved = $this->resolve();

        return [
            'enabled' => $resolved['enabled'],
            'provider' => $resolved['provider'] ?: 'openrouter',
            'model' => $resolved['model'] ?: '',
            'has_api_key' => filled($resolved['api_key']),
            'provider_label' => self::PROVIDERS[$resolved['provider'] ?? 'openrouter'] ?? 'OpenRouter',
            'configured_providers' => collect(self::PROVIDERS)->mapWithKeys(fn ($label, $provider): array => [$provider => filled($this->secretForProvider($provider)) && filled($this->modelForProvider($provider))])->all(),
            'source' => $resolved['source'],
            'status' => ! $resolved['enabled'] ? 'Disabled' : ($resolved['configured'] ? 'Configured' : 'Not Configured'),
        ];
    }

    public function save(array $values): void
    {
        $this->put(self::ENABLED, ! empty($values['enabled']) ? '1' : '0');
        $this->put(self::PROVIDER, strtolower(trim((string) $values['provider'])));
        $provider = strtolower(trim((string) $values['provider']));
        $model = trim((string) $values['model']);
        $this->put($this->modelKeyForProvider($provider), $model);
        if ($provider === 'openrouter') {
            $this->put(self::MODEL, $model);
        }
        if (filled($values['api_key'] ?? null)) {
            $encrypted = Crypt::encryptString(trim((string) $values['api_key']));
            $this->put($this->keyForProvider($provider), $encrypted, true);
            if ($provider === 'openrouter') {
                $this->put(self::API_KEY, $encrypted, true);
            }
        }
    }

    public function effectiveApiKey(): ?string
    {
        return $this->resolve()['api_key'];
    }

    private function secret(): ?string
    {
        return $this->secretForProvider('openrouter');
    }

    private function secretForProvider(string $provider): ?string
    {
        $setting = $this->setting($this->keyForProvider($provider));
        if (! $setting && $provider === 'openrouter') {
            $setting = $this->setting(self::API_KEY);
        }
        if (! $setting || blank($setting->value)) {
            return null;
        }

        return $setting->is_encrypted ? Crypt::decryptString($setting->value) : $setting->value;
    }

    private function modelForProvider(string $provider): ?string
    {
        return $this->value($this->modelKeyForProvider($provider)) ?? ($provider === 'openrouter' ? $this->value(self::MODEL) : null);
    }

    private function modelKeyForProvider(string $provider): string
    {
        return 'ai_scoring.model.'.($provider === 'gemini' ? 'gemini' : $provider);
    }

    private function keyForProvider(string $provider): string
    {
        return 'ai_scoring.api_key.'.($provider === 'gemini' ? 'gemini' : $provider);
    }

    private function environmentKey(string $provider): string
    {
        return match ($provider) {
            'openrouter' => 'api_key',
            'openai' => 'openai_api_key',
            'anthropic' => 'anthropic_api_key',
            'gemini' => 'gemini_api_key',
            default => 'api_key',
        };
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

    private function setting(string $key): ?ApplicationSetting
    {
        return ApplicationSetting::query()->where('key', $key)->first();
    }

    private function put(string $key, ?string $value, bool $encrypted = false): void
    {
        ApplicationSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'is_encrypted' => $encrypted]);
    }
}
