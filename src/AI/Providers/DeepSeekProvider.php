<?php

declare(strict_types=1);

namespace Arafat\Brain\AI\Providers;

use Arafat\Brain\AI\AbstractAIProvider;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Http\Client\Response;

/**
 * DeepSeek provider — OpenAI-compatible chat/completions endpoint.
 *
 * DeepSeek exposes the same request/response schema as OpenAI's chat API,
 * so the only differences are the base URL and the default model name.
 *
 * Config keys (under app-brain.ai.providers.deepseek):
 *   api_key    — sk-…  (required)
 *   model      — e.g. "deepseek-chat", "deepseek-coder"
 *   max_tokens — integer (default 2048)
 *   timeout    — seconds (default 30)
 */
final class DeepSeekProvider extends AbstractAIProvider
{
    public function driver(): string
    {
        return 'deepseek';
    }

    protected function defaultModel(): string
    {
        return 'deepseek-chat';
    }

    protected function apiUrl(): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? 'https://api.deepseek.com'), '/');

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

        // DeepSeek mirrors the OpenAI response envelope exactly.
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
