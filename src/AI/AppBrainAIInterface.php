<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

use Arafat\Brain\Exceptions\AIException;

/**
 * Contract every AI provider must fulfil.
 *
 * The interface is intentionally minimal so that providers remain
 * interchangeable and callers do not depend on driver-specific details.
 *
 * @example
 *   $ai = app(AppBrainAIInterface::class);
 *   $answer = $ai->ask('What does the Product model do?', $context->toArray());
 *
 *   // Via Facade
 *   $answer = Brain::ask('Explain the checkout flow', $context->toArray());
 */
interface AppBrainAIInterface
{
    /**
     * Ask a natural-language question, optionally enriched with structured
     * context data (e.g. the output of ContextBuilder::build()->toArray()).
     *
     * The returned string is the normalised, plain-text (or Markdown) answer
     * from the provider — stripped of any provider-specific envelope.
     *
     * @param  string  $question  Natural-language question
     * @param  array<string,mixed>  $context  Optional context payload
     * @return string Plain-text / Markdown answer
     *
     * @throws AIException On API or network failure
     */
    public function ask(string $question, array $context = []): string;

    /**
     * Return the driver name as registered in config (e.g. "openai").
     */
    public function driver(): string;
}
