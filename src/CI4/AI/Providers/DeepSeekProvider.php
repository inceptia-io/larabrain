<?php

declare(strict_types=1);

namespace Arafat\Brain\CI4\AI\Providers;

use Arafat\Brain\CI4\AI\AbstractCIProvider;
use Arafat\Brain\Exceptions\AIException;

final class DeepSeekProvider extends AbstractCIProvider
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
                "Unexpected DeepSeek response shape: {$rawBody}",
                $this->driver(),
                $statusCode,
            );
        }

        return trim($content);
    }
}
