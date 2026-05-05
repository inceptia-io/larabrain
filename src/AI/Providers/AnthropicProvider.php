<?php

declare(strict_types=1);

namespace Arafat\Brain\AI\Providers;

use Arafat\Brain\AI\AbstractAIProvider;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Http\Client\Response;

/**
 * Anthropic (Claude) provider — targets the /v1/messages endpoint.
 *
 * Config keys (under app-brain.ai.providers.anthropic):
 *   api_key    — sk-ant-…  (required)
 *   model      — e.g. "claude-3-5-sonnet-20241022", "claude-3-opus-20240229"
 *   max_tokens — integer (default 2048, required by the Messages API)
 *   timeout    — seconds (default 30)
 *
 * Reference: https://docs.anthropic.com/en/api/messages
 */
final class AnthropicProvider extends AbstractAIProvider
{
    /** Anthropic-Version header value. */
    private const API_VERSION = '2023-06-01';

    public function driver(): string
    {
        return 'anthropic';
    }

    protected function defaultModel(): string
    {
        return 'claude-3-5-sonnet-20241022';
    }

    protected function apiUrl(): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? 'https://api.anthropic.com'), '/');

        return "{$base}/v1/messages";
    }

    protected function authHeader(): array
    {
        return [
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => self::API_VERSION,
        ];
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

        // The Messages API returns content as an array of blocks.
        $text = $data['content'][0]['text'] ?? null;

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
