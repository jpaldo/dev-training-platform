<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AnthropicScoringSuggestionProvider extends OpenRouterScoringSuggestionProvider
{
    protected function providerName(): string
    {
        return 'anthropic';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.ai_scoring.anthropic_base_url', 'https://api.anthropic.com/v1'), '/');
    }

    protected function request(array $settings, array $payload, string $phase): Response
    {
        $messages = $payload['messages'] ?? [];
        $system = (string) ($messages[0]['content'] ?? '');
        $user = $this->messageContent($messages[1]['content'] ?? '');
        try {
            $response = Http::withHeaders([
                'x-api-key' => $settings['api_key'],
                'anthropic-version' => '2023-06-01',
            ])->asJson()->acceptJson()->connectTimeout(15)->timeout(60)->post($this->baseUrl().'/messages', [
                'model' => $settings['model'],
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('AI scoring provider connection failed.', ['phase' => $phase, 'provider' => $this->providerName(), 'model' => $settings['model'], 'exception_class' => $exception::class]);
            throw new RuntimeException('AI scoring request timed out. Please try again.', 0, $exception);
        }
        $this->throwProviderResponse($response, $settings['model']);

        return $response;
    }

    protected function extractContent(Response $response): mixed
    {
        $json = $response->json();

        return $json['content'][0]['text'] ?? $response->json('content.0.text');
    }

    private function throwProviderResponse(Response $response, string $model): void
    {
        if ($response->successful()) {
            return;
        }
        throw new RuntimeException(match ($response->status()) {
            401, 403 => 'AI scoring authentication failed. Please ask an administrator to verify the AI provider configuration.',
            404 => 'Configured AI scoring model unavailable.',
            408, 504 => 'AI scoring request timed out. Please try again.',
            429 => 'The AI scoring provider is temporarily rate limited. Please try again.',
            default => 'The AI scoring provider is temporarily unavailable.',
        });
    }

    private function messageContent(mixed $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }
        $parts = [];
        foreach ((array) $content as $part) {
            if (($part['type'] ?? null) === 'text') {
                $parts[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];

                continue;
            }
            $url = $part['image_url']['url'] ?? null;
            if (is_string($url) && preg_match('/^data:(image\/[a-z0-9.+-]+);base64,(.+)$/i', $url, $matches)) {
                $parts[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $matches[1], 'data' => $matches[2]]];
            }
        }

        return $parts;
    }
}
