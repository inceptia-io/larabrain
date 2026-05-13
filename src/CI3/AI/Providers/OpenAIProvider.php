<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\AI\Providers;

use Arafat\Brain\CI3\AI\AbstractCIProvider;
use Arafat\Brain\Exceptions\AIException;

final class OpenAIProvider extends AbstractCIProvider
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
        return ['Authorization' => 'Bearer ' . $this->apiKey()];
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
        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || $content === '') {
            throw new AIException(
                "Unexpected response shape: {$rawBody}",
                $this->driver(),
                $statusCode,
            );
        }

        return trim($content);
    }
}
