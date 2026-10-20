<?php

namespace App\Services;

class OpenAiScoringSuggestionProvider extends OpenRouterScoringSuggestionProvider
{
    protected function providerName(): string
    {
        return 'openai';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.ai_scoring.openai_base_url', 'https://api.openai.com/v1'), '/');
    }
}
