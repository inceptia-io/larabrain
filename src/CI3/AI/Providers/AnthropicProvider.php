<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\AI\Providers;

use Arafat\Brain\CI3\AI\AbstractCIProvider;
use Arafat\Brain\Exceptions\AIException;

final class AnthropicProvider extends AbstractCIProvider
{
    private const API_URL     = 'https://api.anthropic.com/v1/messages';
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
        return self::API_URL;
    }

    protected function authHeader(): array
    {
        return [
            'x-api-key'         => $this->apiKey(),
            'anthropic-version' => self::API_VERSION,
        ];
    }

    protected function buildPayload(string $prompt): array
    {
        return [
            'model'      => $this->model(),
            'max_tokens' => (int) ($this->config['max_tokens'] ?? 2048),
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
    }

    protected function parseResponse(array $decoded, int $statusCode, string $rawBody): string
    {
        $text = $decoded['content'][0]['text'] ?? null;

        if (!is_string($text) || $text === '') {
            throw new AIException(
                "Unexpected Anthropic response shape: {$rawBody}",
                $this->driver(),
                $statusCode,
            );
        }

        return trim($text);
    }
}
