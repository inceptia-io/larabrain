<?php

declare(strict_types=1);

namespace Arafat\Brain\CI4;

use Arafat\Brain\AI\Intent;
use Arafat\Brain\AI\IntentMap;
use Arafat\Brain\AI\PromptBuilder;
use Arafat\Brain\CI4\AI\AbstractCIProvider;
use Arafat\Brain\CI4\Config\AppBrain as AppBrainConfig;
use Arafat\Brain\CI4\Context\CIContextBuilder;
use Arafat\Brain\CI4\Context\CIContextResult;
use Arafat\Brain\Exceptions\AIException;

/**
 * AppBrainCI4Service
 *
 * CodeIgniter 4 equivalent of AppBrainService.
 * Same pipeline — zero Illuminate dependencies.
 *
 * Pipeline
 * ────────
 *   1. Extract keyword
 *   2. Detect intent via IntentMap
 *   3. Build context via CIContextBuilder (optional CI4 cache)
 *   4. Build prompt via PromptBuilder (shared, framework-agnostic)
 *   5. Call AI provider via AbstractCIProvider (Guzzle)
 *   6. Optional query logging via CI4 log_message()
 *   7. Return CIBrainResponse DTO
 *
 * Usage
 * ─────
 *   $brain   = \Arafat\Brain\CI4\Services\BrainServices::brain();
 *   $result  = $brain->ask('How does the checkout flow work?');
 *   echo $result['answer'];
 */
final class AppBrainCI4Service
{
    public function __construct(
        private readonly CIContextBuilder $contextBuilder,
        private readonly AbstractCIProvider $ai,
        private readonly AppBrainConfig $config,
        private readonly string $appUrl = '',
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * @return array{query: string, keyword: string, intent: string, driver: string, answer: string, elapsed_ms: float, context: array<string, mixed>}
     * @throws AIException
     */
    public function ask(string $question, ?string $keyword = null): array
    {
        $start = hrtime(true);

        $keyword  = $keyword ?? $this->extractKeyword($question);
        $intent   = IntentMap::resolve(strtolower($question));
        $context  = $this->resolveContext($keyword);
        $prompt   = $this->buildPrompt($question, $intent, $context);
        $answer   = $this->ai->ask($prompt);
        $driver   = $this->ai->driver();
        $elapsed  = (hrtime(true) - $start) / 1_000_000;

        $response = [
            'query'      => $question,
            'keyword'    => $keyword,
            'intent'     => $intent->value,
            'driver'     => $driver,
            'answer'     => $answer,
            'elapsed_ms' => $elapsed,
            'context'    => $context->toArray(),
        ];

        $this->logQuery($response);

        return $response;
    }

    // ── Context (with optional CI4 cache) ─────────────────────────────────────

    private function resolveContext(string $keyword): CIContextResult
    {
        if (!$this->config->askCacheContext || !$this->config->cacheEnabled) {
            return $this->contextBuilder->build($keyword);
        }

        $cache = \Config\Services::cache();
        $key   = $this->config->cachePrefix . ':context:' . hash('sha256', $keyword);
        $ttl   = $this->config->cacheContextTtl ?: $this->config->cacheTtl;

        $cached = $cache->get($key);

        if ($cached instanceof CIContextResult) {
            return $cached;
        }

        $result = $this->contextBuilder->build($keyword);
        $cache->save($key, $result, $ttl);

        return $result;
    }

    // ── Prompt assembly ────────────────────────────────────────────────────────

    private function buildPrompt(string $question, Intent $intent, CIContextResult $context): string
    {
        $intentHint = $this->intentHint($intent);

        $augmentedQuestion = $intentHint !== ''
            ? "{$intentHint}\n\n{$question}"
            : $question;

        $contextArray = array_merge(
            ['app_url' => rtrim($this->appUrl, '/')],
            $context->toArray(),
        );

        return PromptBuilder::make($augmentedQuestion, $contextArray)->build();
    }

    private function intentHint(Intent $intent): string
    {
        return match ($intent) {
            Intent::ExplainWorkflow   => 'Focus: explain the end-to-end workflow with step-by-step instructions and links.',
            Intent::ShowRoutes        => 'Focus: list all relevant HTTP routes/endpoints with their URLs and purposes.',
            Intent::DescribeModel     => 'Focus: describe the model schema, fields, types, casts, and relationships.',
            Intent::ListDependencies  => 'Focus: list all dependencies, relationships, and coupling between entities.',
            Intent::General           => '',
        };
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $response
     */
    private function logQuery(array $response): void
    {
        if (!$this->config->askLogQueries || $this->config->logLevel === null) {
            return;
        }

        $summary = $response['context']['summary'] ?? [];

        log_message(
            $this->config->logLevel,
            'app-brain: query answered | query={query} keyword={keyword} intent={intent} driver={driver} elapsed_ms={elapsed}',
            [
                'query'   => $response['query'],
                'keyword' => $response['keyword'],
                'intent'  => $response['intent'],
                'driver'  => $response['driver'],
                'elapsed' => round((float) $response['elapsed_ms'], 2),
            ],
        );
    }

    // ── Keyword extraction ─────────────────────────────────────────────────────

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

        $words = preg_split('/\s+/', strtolower((string) preg_replace('/[^\w\s]/', '', $question)));

        $candidates = array_filter(
            is_array($words) ? $words : [],
            static fn (string $w) => $w !== '' && !in_array($w, $stopWords, true),
        );

        if (empty($candidates)) {
            return trim($question);
        }

        usort($candidates, static fn ($a, $b) => strlen($b) - strlen($a));

        return (string) reset($candidates);
    }
}
