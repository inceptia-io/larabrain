<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3;

use Arafat\Brain\AI\Intent;
use Arafat\Brain\AI\IntentMap;
use Arafat\Brain\AI\PromptBuilder;
use Arafat\Brain\CI3\AI\AbstractCIProvider;
use Arafat\Brain\CI3\Config\AppBrain as AppBrainConfig;
use Arafat\Brain\CI3\Context\CIContextBuilder;
use Arafat\Brain\CI3\Context\CIContextResult;
use Arafat\Brain\Exceptions\AIException;

/**
 * AppBrainCI3Service
 *
 * CodeIgniter 3 main service that orchestrates the full ask() pipeline.
 * PHP 7.4+ compatible — no readonly, no match, no enum, no named arguments.
 *
 * Pipeline
 * ────────
 *   1. Extract keyword
 *   2. Detect intent via IntentMap
 *   3. Build context via CIContextBuilder (optional CI3 cache)
 *   4. Build prompt via PromptBuilder (shared, framework-agnostic)
 *   5. Call AI provider via AbstractCIProvider (Guzzle)
 *   6. Optional query logging via CI3 log_message()
 *   7. Return result array
 *
 * Usage
 * ─────
 *   $brain  = \Arafat\Brain\CI3\Services\BrainServices::brain();
 *   $result = $brain->ask('How does the checkout flow work?');
 *   echo $result['answer'];
 */
final class AppBrainCI3Service
{
    /** @var CIContextBuilder */
    private $contextBuilder;

    /** @var AbstractCIProvider */
    private $ai;

    /** @var AppBrainConfig */
    private $config;

    /** @var string */
    private $appUrl;

    public function __construct(
        CIContextBuilder $contextBuilder,
        AbstractCIProvider $ai,
        AppBrainConfig $config,
        string $appUrl = ''
    ) {
        $this->contextBuilder = $contextBuilder;
        $this->ai             = $ai;
        $this->config         = $config;
        $this->appUrl         = $appUrl;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * @param  string       $question
     * @param  string|null  $keyword   Override auto-extracted keyword.
     * @return array{query: string, keyword: string, intent: string, driver: string, answer: string, elapsed_ms: float, context: array}
     * @throws AIException
     */
    public function ask(string $question, ?string $keyword = null): array
    {
        $start = microtime(true);

        $keyword = $keyword !== null ? $keyword : $this->extractKeyword($question);
        $intent  = IntentMap::resolve(strtolower($question));
        $context = $this->resolveContext($keyword);
        $prompt  = $this->buildPrompt($question, $intent, $context);
        $answer  = $this->ai->ask($prompt);
        $driver  = $this->ai->driver();
        $elapsed = (microtime(true) - $start) * 1000;

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

    // ── Context (with optional CI3 cache) ─────────────────────────────────────

    private function resolveContext(string $keyword): CIContextResult
    {
        if (!$this->config->askCacheContext || !$this->config->cacheEnabled) {
            return $this->contextBuilder->build($keyword);
        }

        $CI  = &get_instance();
        $key = $this->config->cachePrefix . ':context:' . hash('sha256', $keyword);
        $ttl = $this->config->cacheContextTtl ?: $this->config->cacheTtl;

        $cached = $CI->cache->get($key);

        if ($cached instanceof CIContextResult) {
            return $cached;
        }

        $result = $this->contextBuilder->build($keyword);
        $CI->cache->save($key, $result, $ttl);

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
        $hints = [
            Intent::EXPLAIN_WORKFLOW  => 'Focus: explain the end-to-end workflow with step-by-step instructions and links.',
            Intent::SHOW_ROUTES       => 'Focus: list all relevant HTTP routes/endpoints with their URLs and purposes.',
            Intent::DESCRIBE_MODEL    => 'Focus: describe the model schema, fields, types, casts, and relationships.',
            Intent::LIST_DEPENDENCIES => 'Focus: list all dependencies, relationships, and coupling between entities.',
            Intent::GENERAL           => '',
        ];

        return $hints[$intent->value] ?? '';
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $response
     */
    private function logQuery(array $response): void
    {
        if (!$this->config->askLogQueries || $this->config->logLevel === null) {
            return;
        }

        $message = 'app-brain: query answered | '
            . 'query=' . $response['query']
            . ' keyword=' . $response['keyword']
            . ' intent=' . $response['intent']
            . ' driver=' . $response['driver']
            . ' elapsed_ms=' . round((float) $response['elapsed_ms'], 2);

        log_message($this->config->logLevel, $message);
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
            static function (string $w) use ($stopWords): bool {
                return $w !== '' && !in_array($w, $stopWords, true);
            }
        );

        if (empty($candidates)) {
            return trim($question);
        }

        usort($candidates, static function ($a, $b): int {
            return strlen($b) - strlen($a);
        });

        return (string) reset($candidates);
    }
}
