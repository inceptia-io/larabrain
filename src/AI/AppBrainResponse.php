<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

use Arafat\Brain\Context\ContextResult;

/**
 * AppBrainResponse
 *
 * Immutable value object returned by AppBrainService::ask().
 * Carries the full answer plus diagnostic metadata so callers can
 * log, cache, or display intermediate details without re-running the pipeline.
 */
final class AppBrainResponse
{
    public function __construct(
        /** The original user query, unchanged. */
        public readonly string $query,
        /** The keyword extracted from the query used for context lookup. */
        public readonly string $keyword,
        /** Detected intent used to select the prompt strategy. */
        public readonly Intent $intent,
        /** Context graph resolved from the knowledge store. */
        public readonly ContextResult $context,
        /** The fully assembled prompt that was sent to the AI. */
        public readonly string $prompt,
        /** The AI provider driver that handled the request (e.g. "openai"). */
        public readonly string $driver,
        /** Normalised plain-text / Markdown answer from the AI. */
        public readonly string $answer,
        /** Total wall-clock time for the full pipeline in milliseconds. */
        public readonly float $elapsedMs,
    ) {}

    // ── Serialisation ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'keyword' => $this->keyword,
            'intent' => $this->intent->value,
            'driver' => $this->driver,
            'answer' => $this->answer,
            'elapsed_ms' => $this->elapsedMs,
            'context' => $this->context->toArray(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
