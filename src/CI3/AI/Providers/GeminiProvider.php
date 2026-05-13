<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\AI\Providers;

use Arafat\Brain\CI3\AI\AbstractCIProvider;
use Arafat\Brain\Exceptions\AIException;

final class GeminiProvider extends AbstractCIProvider
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
        $model  = $this->model();
        $apiKey = $this->apiKey();

        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    }

    protected function authHeader(): array
    {
        // Gemini uses the API key in the URL, not an auth header
        return [];
    }

    protected function buildPayload(string $prompt): array
    {
        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];
    }

    protected function parseResponse(array $decoded, int $statusCode, string $rawBody): string
    {
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text) || $text === '') {
            throw new AIException(
                "Unexpected Gemini response shape: {$rawBody}",
                $this->driver(),
                $statusCode,
            );
        }

        return trim($text);
    }
}
