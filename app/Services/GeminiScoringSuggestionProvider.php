<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiScoringSuggestionProvider extends OpenRouterScoringSuggestionProvider
{
    protected function providerName(): string
    {
        return 'gemini';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.ai_scoring.gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    protected function request(array $settings, array $payload, string $phase): Response
    {
        $messages = $payload['messages'] ?? [];
        $parts = [];
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $parts[] = ['text' => $content];

                continue;
            }
            foreach ($content as $part) {
                if (($part['type'] ?? null) === 'text') {
                    $parts[] = ['text' => (string) ($part['text'] ?? '')];
                } elseif (($part['type'] ?? null) === 'image_url') {
                    $url = $part['image_url']['url'] ?? null;
                    if (is_string($url) && preg_match('/^data:(image\/[a-z0-9.+-]+);base64,(.+)$/i', $url, $matches)) {
                        $parts[] = ['inlineData' => ['mimeType' => $matches[1], 'data' => $matches[2]]];
                    }
                }
            }
        }
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $settings['api_key']])->asJson()->acceptJson()->connectTimeout(15)->timeout(60)->post($this->baseUrl().'/models/'.rawurlencode((string) $settings['model']).':generateContent', [
                'contents' => [['parts' => $parts]],
                'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('AI scoring provider connection failed.', ['phase' => $phase, 'provider' => $this->providerName(), 'model' => $settings['model'], 'exception_class' => $exception::class]);
            throw new RuntimeException('AI scoring request timed out. Please try again.', 0, $exception);
        }
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                400, 404 => 'Configured AI scoring model unavailable.',
                401, 403 => 'AI scoring authentication failed. Please ask an administrator to verify the AI provider configuration.',
                429 => 'The AI scoring provider is temporarily rate limited. Please try again.',
                default => 'The AI scoring provider is temporarily unavailable.',
            });
        }

        return $response;
    }

    protected function extractContent(Response $response): mixed
    {
        return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
}
