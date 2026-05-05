<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

/**
 * IntentMap
 *
 * Single source of truth for the keyword → Intent mapping table.
 *
 * Externalising this from AppBrainService makes the vocabulary easy to audit,
 * extend, or override at the application level without touching service logic.
 *
 * Priority is determined by the array order (first match wins).
 *
 * To add custom patterns in your application:
 *   IntentMap::extend(Intent::ExplainWorkflow, ['pipeline', 'lifecycle']);
 */
final class IntentMap
{
    /** @var array<string, list<string>>  Extra patterns merged at runtime. */
    private static array $extensions = [];

    // ── Static map ─────────────────────────────────────────────────────────────

    /**
     * The canonical keyword patterns for each Intent.
     *
     * Keyed by Intent->value (string) to be PHPStan-safe.
     * Patterns are lowercased substrings; multi-word phrases are supported.
     *
     * @return array<string, list<string>>
     */
    public static function patterns(): array
    {
        $base = [
            Intent::ExplainWorkflow->value => [
                'how does', 'walk me through', 'explain the', 'explain how',
                'workflow', 'flow', 'step by step', 'step-by-step', 'trace',
                'process', 'lifecycle', 'pipeline',
            ],

            Intent::ShowRoutes->value => [
                'route', 'routes', 'endpoint', 'endpoints', 'url', 'uri',
                'http', 'api path', 'api endpoint', 'web route', 'api route',
            ],

            Intent::DescribeModel->value => [
                'model', 'schema', 'fields', 'columns', 'table structure',
                'eloquent', 'describe', 'attributes', 'fillable', 'casts',
                'relationships', 'belongs to', 'has many',
            ],

            Intent::ListDependencies->value => [
                'depend', 'dependencies', 'uses', 'calls', 'relationship',
                'relies on', 'imports', 'coupled', 'references', 'connected to',
            ],
        ];

        // Merge runtime extensions (added via IntentMap::extend()).
        foreach (self::$extensions as $intentValue => $extra) {
            $base[$intentValue] = array_unique(array_merge($base[$intentValue] ?? [], $extra));
        }

        return $base;
    }

    /**
     * Resolve the Intent for a given lowercased question string.
     *
     * Runs in O(I × K) where I = intent count (fixed at 4) and
     * K = keyword count per intent (small fixed set) — effectively O(1).
     */
    public static function resolve(string $lowercasedQuestion): Intent
    {
        foreach (self::patterns() as $intentValue => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lowercasedQuestion, $kw)) {
                    return Intent::from($intentValue);
                }
            }
        }

        return Intent::General;
    }

    // ── Extension point ────────────────────────────────────────────────────────

    /**
     * Merge additional keywords into an Intent's pattern list at runtime.
     *
     * Intended for application-level customisation in a ServiceProvider:
     *
     *   IntentMap::extend(Intent::ExplainWorkflow, ['saga', 'saga pattern']);
     *
     * @param  list<string>  $keywords  Lowercase substrings to match against.
     */
    public static function extend(Intent $intent, array $keywords): void
    {
        $existing = self::$extensions[$intent->value] ?? [];
        self::$extensions[$intent->value] = array_unique(array_merge($existing, $keywords));
    }

    /**
     * Reset all runtime extensions (useful in tests).
     */
    public static function resetExtensions(): void
    {
        self::$extensions = [];
    }
}
