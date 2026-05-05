<?php

declare(strict_types=1);

namespace Arafat\Brain\AI\Providers;

use Arafat\Brain\AI\AbstractAIProvider;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Http\Client\Response;

/**
 * Google Gemini provider — targets the generateContent REST endpoint.
 *
 * Config keys (under app-brain.ai.providers.gemini):
 *   api_key    — AIza…  (required, sent as ?key= query param)
 *   model      — e.g. "gemini-1.5-pro", "gemini-1.5-flash"
 *   max_tokens — integer (default 2048, maps to maxOutputTokens)
 *   timeout    — seconds (default 30)
 */
final class GeminiProvider extends AbstractAIProvider
{
    public function driver(): string
    {
        return 'gemini';
    }

    protected function defaultModel(): string
    {
        return 'gemini-1.5-pro';
    }

    protected function apiUrl(): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/');
        $model = $this->model();
        $key = $this->apiKey();

        // Gemini uses an API key query param, not an Authorization header.
        return "{$base}/v1beta/models/{$model}:generateContent?key={$key}";
    }

    protected function authHeader(): array
    {
        // Auth is handled via the ?key= query param in apiUrl().
        return [];
    }

    protected function buildPayload(string $prompt): array
    {
        return [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => (int) ($this->config['max_tokens'] ?? 2048),
            ],
        ];
    }

    protected function parseResponse(Response $response): string
    {
        $data = $response->json();

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text) || $text === '') {
            throw new AIException(
                'Unexpected response shape: '.$response->body(),
                $this->driver(),
                $response->status(),
            );
        }

        return trim($text);
    }
}
