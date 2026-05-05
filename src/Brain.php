<?php

declare(strict_types=1);

namespace Arafat\Brain;

use Arafat\Brain\AI\AppBrainAIInterface;
use Arafat\Brain\AI\AppBrainResponse;
use Arafat\Brain\AI\AppBrainService;
use Arafat\Brain\Context\ContextBuilder;
use Arafat\Brain\Context\ContextResult;
use Arafat\Brain\Contracts\BrainInterface;

class Brain implements BrainInterface
{
    public function __construct(
        protected readonly ContextBuilder $contextBuilder,
        protected readonly AppBrainAIInterface $ai,
        protected readonly AppBrainService $brainService,
        /** @var array<string, mixed> */
        protected readonly array $config = [],
    ) {}

    /**
     * Build a context graph for the given keyword.
     * Resolves related models, tables, routes, and controller methods.
     */
    public function context(string $keyword): ContextResult
    {
        return $this->contextBuilder->build($keyword);
    }

    /**
     * Low-level AI call — pass your own question + context array.
     * Returns the raw AI answer string.
     */
    public function ask(string $question, array $context = []): string
    {
        return $this->ai->ask($question, $context);
    }

    /**
     * Full pipeline — keyword extraction, intent detection, context lookup,
     * prompt building, AI call, caching, logging.
     * Returns a rich AppBrainResponse with all diagnostic metadata.
     */
    public function query(string $question, ?string $keyword = null): AppBrainResponse
    {
        return $this->brainService->ask($question, $keyword);
    }
}
