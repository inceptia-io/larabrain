<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

use Arafat\Brain\Context\ContextBuilder;
use Arafat\Brain\Context\ContextResult;
use Arafat\Brain\Exceptions\AIException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * AppBrainService
 *
 * The single entry-point for answering natural-language questions about a
 * Laravel application's codebase.
 *
 * Pipeline
 * ────────
 *   1. Extract keyword   — strip stop-words to get a searchable term.
 *   2. Detect intent     — keyword-based classification via IntentMap.
 *   3. Build context     — resolve related entities (cached when enabled).
 *   4. Build prompt      — assemble a strict, grounded prompt via PromptBuilder.
 *   5. Call AI provider  — send prompt through the active AppBrainAIInterface driver.
 *   6. Log query         — record metadata to the configured log channel (optional).
 *   7. Return response   — wrap everything in an AppBrainResponse DTO.
 *
 * Optional features (all null-safe / config-gated)
 * ─────────────────────────────────────────────────
 *   Context caching  — enabled via config('app-brain.ask.cache_context')
 *   Query logging    — enabled via config('app-brain.ask.log_queries')
 *
 * Usage
 * ─────
 *   $response = app(AppBrainService::class)->ask('How does the checkout flow work?');
 *   echo $response->answer;
 *
 *   // Override keyword detection:
 *   $response = app(AppBrainService::class)->ask('Explain order placement', keyword: 'order');
 */
final class AppBrainService
{
    public function __construct(
        private readonly ContextBuilder $contextBuilder,
        private readonly AppBrainAIInterface $ai,
        private readonly array $config = [],
        private readonly ?CacheRepository $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Answer a natural-language question about the application.
     *
     * @param  string  $question  Free-form user query.
     * @param  string|null  $keyword  Optional override; auto-extracted when null.
     *
     * @throws AIException When the AI provider fails.
     */
    public function ask(string $question, ?string $keyword = null): AppBrainResponse
    {
        $start = hrtime(true);

        $keyword = $keyword ?? $this->extractKeyword($question);
        $intent = IntentMap::resolve(strtolower($question));
        $context = $this->resolveContext($keyword);
        $prompt = $this->buildPrompt($question, $intent, $context);
        $answer = $this->ai->ask($prompt);
        $driver = $this->ai->driver();

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $response = new AppBrainResponse(
            query: $question,
            keyword: $keyword,
            intent: $intent,
            context: $context,
            prompt: $prompt,
            driver: $driver,
            answer: $answer,
            elapsedMs: $elapsedMs,
        );

        $this->logQuery($response);

        return $response;
    }

    // ── Context (with optional caching) ───────────────────────────────────────

    /**
     * Resolve the context graph for the given keyword.
     *
     * When caching is enabled (config ask.cache_context = true) the result is
     * stored under a deterministic cache key for the configured TTL so that
     * repeated questions about the same keyword do not re-query the DB.
     */
    private function resolveContext(string $keyword): ContextResult
    {
        if (!$this->cacheEnabled() || $this->cache === null) {
            return $this->contextBuilder->build($keyword);
        }

        $key = $this->cacheKey($keyword);
        $ttl = (int) ($this->config['cache']['context_ttl']
            ?? $this->config['cache']['ttl']
            ?? 3600);

        return $this->cache->remember($key, $ttl, fn () => $this->contextBuilder->build($keyword));
    }

    private function cacheEnabled(): bool
    {
        // Respects both the global cache.enabled flag and the ask-specific toggle.
        return (bool) ($this->config['cache']['enabled'] ?? false)
            && (bool) ($this->config['ask']['cache_context'] ?? false);
    }

    private function cacheKey(string $keyword): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'brain';

        return "{$prefix}:context:".hash('xxh3', $keyword);
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    /**
     * Log metadata about the completed query.
     *
     * Only runs when ask.log_queries is true and a logger is injected.
     * The log entry is written at DEBUG level so it does not pollute
     * production logs unless the channel threshold is set accordingly.
     */
    private function logQuery(AppBrainResponse $response): void
    {
        if (!$this->loggingEnabled() || $this->logger === null) {
            return;
        }

        $this->logger->debug('app-brain: query answered', [
            'query' => $response->query,
            'keyword' => $response->keyword,
            'intent' => $response->intent->value,
            'driver' => $response->driver,
            'elapsed_ms' => round($response->elapsedMs, 2),
            'context' => [
                'models' => $response->context->models->count(),
                'tables' => $response->context->tables->count(),
                'routes' => $response->context->routes->count(),
                'controller_methods' => $response->context->controllerMethods->count(),
            ],
        ]);
    }

    private function loggingEnabled(): bool
    {
        return (bool) ($this->config['ask']['log_queries'] ?? false);
    }

    // ── Keyword extraction ─────────────────────────────────────────────────────

    /**
     * Extract the most meaningful single keyword from the question.
     *
     * Strategy:
     *   1. Strip punctuation.
     *   2. Remove common stop-words and intent signal words.
     *   3. Return the longest surviving token (most specific noun).
     *
     * Falls back to the full trimmed question if nothing survives filtering.
     */
    private function extractKeyword(string $question): string
    {
        static $stopWords = [
            'how', 'does', 'what', 'is', 'are', 'the', 'a', 'an', 'of',
            'in', 'to', 'for', 'with', 'and', 'or', 'that', 'this', 'it',
            'me', 'through', 'explain', 'describe', 'list', 'show', 'give',
            'walk', 'tell', 'please', 'can', 'you', 'do', 'i', 'my',
            'flow', 'work', 'works', 'working', 'route', 'routes',
            'model', 'schema', 'controller', 'endpoint', 'endpoints',
        ];

        $words = preg_split('/\s+/', strtolower(preg_replace('/[^\w\s]/', '', $question)));

        $candidates = array_filter(
            $words,
            static fn (string $w) => $w !== '' && !in_array($w, $stopWords, true),
        );

        if (empty($candidates)) {
            return trim($question);
        }

        usort($candidates, static fn ($a, $b) => strlen($b) - strlen($a));

        return reset($candidates);
    }

    // ── Prompt assembly ────────────────────────────────────────────────────────

    private function buildPrompt(string $question, Intent $intent, ContextResult $context): string
    {
        $intentHint = $this->intentHint($intent);

        $augmentedQuestion = $intentHint !== ''
            ? "{$intentHint}\n\n{$question}"
            : $question;

        $contextArray = array_merge(
            ['app_url' => rtrim((string) config('app.url', ''), '/')],
            $context->toArray(),
        );

        return PromptBuilder::make($augmentedQuestion, $contextArray)->build();
    }

    private function intentHint(Intent $intent): string
    {
        return match ($intent) {
            Intent::ExplainWorkflow => '[Focus: explain the end-to-end workflow with numbered steps. Include clickable links for every relevant page/route.]',
            Intent::ShowRoutes     => '[Focus: list all relevant routes/pages with their full clickable links and a brief description of each.]',
            Intent::DescribeModel  => '[Focus: describe the model — its fields, relationships, and backing table — and link to related pages (list, create, edit).]',
            Intent::ListDependencies => '[Focus: map which models, tables, routes, and controllers are involved, and explain what must exist before this action can be performed.]',
            Intent::General        => '',
        };
    }
}
