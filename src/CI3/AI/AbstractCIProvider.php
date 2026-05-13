<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\AI;

use Arafat\Brain\Exceptions\AIException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AbstractCIProvider
 *
 * Guzzle-based base class for all CodeIgniter 3 AI providers.
 * Uses GuzzleHttp\Client directly so there is no framework-coupled
 * HTTP client dependency.
 *
 * Subclass obligations
 * ────────────────────
 * • driver()        — return the config key (e.g. "openai")
 * • buildPayload()  — construct the provider-specific request body array
 * • parseResponse() — extract the answer string from the decoded JSON array
 * • apiUrl()        — return the fully-qualified endpoint URL
 * • authHeader()    — return ['Header-Name' => 'value']
 */
abstract class AbstractCIProvider
{
    /** @var array<string, mixed> Provider-specific config slice */
    protected $config;

    /** @param array<string, mixed> $fullConfig The full AppBrain config array */
    public function __construct(array $fullConfig)
    {
        $this->config = $fullConfig['providers'][$this->driver()] ?? [];
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    final public function ask(string $prompt): string
    {
        $this->guardApiKey();

        $payload = $this->buildPayload($prompt);

        $client = new Client([
            'timeout' => $this->config['timeout'] ?? 30,
        ]);

        try {
            $response = $client->post($this->apiUrl(), [
                'headers' => array_merge(
                    ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    $this->authHeader(),
                ),
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new AIException(
                'HTTP request failed: ' . $e->getMessage(),
                $this->driver(),
                0,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $body       = (string) $response->getBody();

        if ($statusCode >= 400) {
            throw new AIException(
                "API error {$statusCode}: {$body}",
                $this->driver(),
                $statusCode,
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new AIException(
                "Could not decode response: {$body}",
                $this->driver(),
                $statusCode,
            );
        }

        return $this->parseResponse($decoded, $statusCode, $body);
    }

    public function driver(): string
    {
        return 'unknown';
    }

    // ── Abstract contract ─────────────────────────────────────────────────────

    /** Provider-specific request body. */
    abstract protected function buildPayload(string $prompt): array;

    /**
     * Extract the plain-text answer from the decoded JSON response.
     *
     * @param array<string, mixed> $decoded
     */
    abstract protected function parseResponse(array $decoded, int $statusCode, string $rawBody): string;

    abstract protected function apiUrl(): string;

    /** @return array<string, string> */
    abstract protected function authHeader(): array;

    // ── Shared helpers ─────────────────────────────────────────────────────────

    protected function apiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    protected function model(): string
    {
        return (string) ($this->config['model'] ?? $this->defaultModel());
    }

    protected function defaultModel(): string
    {
        return '';
    }

    protected function guardApiKey(): void
    {
        if (trim($this->apiKey()) === '') {
            throw new AIException(
                "API key for driver '{$this->driver()}' is not configured.",
                $this->driver(),
            );
        }
    }
}
