<?php

declare(strict_types=1);

namespace Arafat\Brain\Contracts;

use Arafat\Brain\AI\AppBrainResponse;
use Arafat\Brain\Context\ContextResult;
use Arafat\Brain\Exceptions\AIException;

interface BrainInterface
{
    /**
     * Build a context graph for the given keyword.
     *
     * @param  string  $keyword  Free-form search term, e.g. "product"
     */
    public function context(string $keyword): ContextResult;

    /**
     * Ask the configured AI provider a natural-language question.
     *
     * Low-level entry-point: context must be provided explicitly.
     * For the full auto-pipeline (keyword extraction, context lookup,
     * intent detection, caching, logging) use query() instead.
     *
     * @param  string  $question  Natural-language question
     * @param  array<string,mixed>  $context  Optional context payload
     * @return string Normalised plain-text / Markdown answer
     *
     * @throws AIException
     */
    public function ask(string $question, array $context = []): string;

    /**
     * Full pipeline entry-point.
     *
     * Automatically extracts the keyword, detects intent, resolves
     * and optionally caches context, builds the prompt, calls the AI,
     * and returns a rich AppBrainResponse with all diagnostic metadata.
     *
     * @param  string  $question  Natural-language question
     * @param  string|null  $keyword  Override auto-extracted keyword
     *
     * @throws AIException
     */
    public function query(string $question, ?string $keyword = null): AppBrainResponse;
}
