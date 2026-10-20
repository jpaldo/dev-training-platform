<?php

namespace App\Services;

use App\Contracts\ScoringSuggestionProvider;

class AiScoringProviderResolver
{
    public function __construct(private readonly AiProviderConfigurationService $configuration) {}

    public function resolve(?array $overrides = null): ScoringSuggestionProvider
    {
        $settings = $this->configuration->resolve($overrides ?? []);
        if (! $settings['configured']) {
            return app(UnavailableScoringSuggestionProvider::class);
        }

        return match ($settings['provider']) {
            'openrouter' => app(OpenRouterScoringSuggestionProvider::class),
            'openai' => app(OpenAiScoringSuggestionProvider::class),
            'anthropic' => app(AnthropicScoringSuggestionProvider::class),
            'gemini' => app(GeminiScoringSuggestionProvider::class),
            default => app(UnavailableScoringSuggestionProvider::class),
        };
    }

    /** @return array{ok:bool,message:string} */
    public function test(array $overrides = []): array
    {
        $provider = $this->resolve($overrides);
        if (! method_exists($provider, 'testConnection')) {
            return ['ok' => false, 'message' => 'The selected AI provider is not supported.'];
        }

        return $provider->testConnection($overrides);
    }
}
