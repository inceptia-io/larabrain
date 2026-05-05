<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

use Arafat\Brain\Exceptions\AIException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared foundation for every AI provider.
 *
 * Responsibilities
 * ────────────────
 * • Reads provider config from the `app-brain.ai.providers.{driver}` key.
 * • Builds the HTTP client with auth header and timeout.
 * • Formats the context payload into a human-readable block that is injected
 *   before the user's question.
 * • Normalises transport-level errors into AIException.
 * • Delegates response parsing to the concrete subclass via parseResponse().
 *
 * Subclass obligations
 * ────────────────────
 * • Implement driver()        — return the config key name.
 * • Implement buildPayload()  — construct the provider-specific request body.
 * • Implement parseResponse() — extract the answer string from the HTTP response.
 * • Implement apiUrl()        — return the fully-qualified endpoint URL.
 * • Implement authHeader()    — return ['Header-Name' => 'Bearer …'].
 */
abstract class AbstractAIProvider implements AppBrainAIInterface
{
    /** @var array<string, mixed> */
    protected readonly array $config;

    public function __construct(array $fullConfig)
    {
        $this->config = $fullConfig['ai']['providers'][$this->driver()] ?? [];
    }

    // ── AppBrainAIInterface ───────────────────────────────────────────────────

    final public function ask(string $question, array $context = []): string
    {
        $this->guardApiKey();

        $prompt = $this->buildPrompt($question, $context);
        $payload = $this->buildPayload($prompt);

        try {
            $response = Http::withHeaders($this->authHeader())
                ->timeout($this->config['timeout'] ?? 30)
                ->post($this->apiUrl(), $payload);
        } catch (\Throwable $e) {
            throw new AIException(
                "HTTP request failed: {$e->getMessage()}",
                $this->driver(),
                0,
                $e,
            );
        }

        if ($response->failed()) {
            throw new AIException(
                "API error {$response->status()}: {$response->body()}",
                $this->driver(),
                $response->status(),
            );
        }

        return $this->parseResponse($response);
    }

    // ── Abstract contract ─────────────────────────────────────────────────────

    /** Provider-specific request body (without the prompt — that comes from buildPayload). */
    abstract protected function buildPayload(string $prompt): array;

    /** Extract the plain-text answer from the successful HTTP response. */
    abstract protected function parseResponse(Response $response): string;

    /** The provider's chat / completion endpoint URL. */
    abstract protected function apiUrl(): string;

    /** HTTP headers used for authentication, e.g. ['Authorization' => 'Bearer sk-…']. */
    abstract protected function authHeader(): array;

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Build the final prompt string via PromptBuilder.
     *
     * Delegates all prompt assembly — context injection, strict grounding
     * rules, step-by-step format enforcement, and dependency listing — to
     * the dedicated PromptBuilder service.
     *
     * @param  array<string, mixed>  $context
     */
    protected function buildPrompt(string $question, array $context): string
    {
        return PromptBuilder::make($question, $context)->build();
    }

    /**
     * Read the API key from config (falls back to empty string so the guard
     * below gives a clear error rather than a cryptic 401 from the provider).
     */
    protected function apiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    protected function model(): string
    {
        return (string) ($this->config['model'] ?? $this->defaultModel());
    }

    /** Fallback model name used when none is set in config. */
    protected function defaultModel(): string
    {
        return '';
    }

    private function guardApiKey(): void
    {
        if ($this->apiKey() === '') {
            throw new AIException(
                "API key is not configured. Set app-brain.ai.providers.{$this->driver()}.api_key.",
                $this->driver(),
            );
        }
    }
}
