<?php

declare(strict_types=1);

namespace Arafat\Brain\AI\Providers;

use Arafat\Brain\AI\AbstractAIProvider;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Http\Client\Response;

/**
 * OpenAI provider — targets the /v1/chat/completions endpoint.
 *
 * Config keys (under app-brain.ai.providers.openai):
 *   api_key    — sk-…  (required)
 *   model      — e.g. "gpt-4o", "gpt-4-turbo", "gpt-3.5-turbo"
 *   max_tokens — integer (default 2048)
 *   timeout    — seconds (default 30, inherited from AbstractAIProvider)
 */
final class OpenAIProvider extends AbstractAIProvider
{
    public function driver(): string
    {
        return 'openai';
    }

    protected function defaultModel(): string
    {
        return 'gpt-4o';
    }

    protected function apiUrl(): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? 'https://api.openai.com'), '/');

        return "{$base}/v1/chat/completions";
    }

    protected function authHeader(): array
    {
        return ['Authorization' => "Bearer {$this->apiKey()}"];
    }

    protected function buildPayload(string $prompt): array
    {
        return [
            'model' => $this->model(),
            'max_tokens' => (int) ($this->config['max_tokens'] ?? 2048),
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
    }

    protected function parseResponse(Response $response): string
    {
        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || $content === '') {
            throw new AIException(
                'Unexpected response shape: '.$response->body(),
                $this->driver(),
                $response->status(),
            );
        }

        return trim($content);
    }
}
