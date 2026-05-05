<?php

declare(strict_types=1);

namespace Arafat\Brain\Context;

use Illuminate\Support\Collection;

/**
 * Immutable value object returned by ContextBuilder::build().
 *
 * The object carries four distinct entity groups that collectively describe
 * everything the system knows about a given keyword:
 *
 *   models             — Eloquent model entities (type = "model")
 *   tables             — database table entities (type = "table")
 *   routes             — route entities (type = "route")
 *   controllerMethods  — controller-method entities (type = "controller_method")
 *
 * Each group is a Collection of plain arrays so that callers can immediately
 * json_encode() the result or read individual fields without any Eloquent
 * coupling.
 */
final class ContextResult
{
    /**
     * @param  string  $keyword  The original search keyword
     * @param  Collection<int, array<string, mixed>>  $models  Matched + related model entities
     * @param  Collection<int, array<string, mixed>>  $tables  Matched + related table entities
     * @param  Collection<int, array<string, mixed>>  $routes  Route entities that call related controllers
     * @param  Collection<int, array<string, mixed>>  $controllerMethods  Controller-method entities that use related models
     * @param  float  $elapsedMs  Wall-clock time in milliseconds
     */
    public function __construct(
        public readonly string $keyword,
        public readonly Collection $models,
        public readonly Collection $tables,
        public readonly Collection $routes,
        public readonly Collection $controllerMethods,
        public readonly float $elapsedMs,
    ) {}

    /**
     * Build an empty result (no matches found).
     */
    public static function empty(string $keyword, float $elapsedMs): self
    {
        return new self(
            keyword: $keyword,
            models: collect(),
            tables: collect(),
            routes: collect(),
            controllerMethods: collect(),
            elapsedMs: $elapsedMs,
        );
    }

    // ── Serialisation ──────────────────────────────────────────────────────────

    /**
     * Structured array — suitable for json_encode() or API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'keyword' => $this->keyword,
            'summary' => [
                'models' => $this->models->count(),
                'tables' => $this->tables->count(),
                'routes' => $this->routes->count(),
                'controller_methods' => $this->controllerMethods->count(),
            ],
            'models' => $this->models->values()->all(),
            'tables' => $this->tables->values()->all(),
            'routes' => $this->routes->values()->all(),
            'controller_methods' => $this->controllerMethods->values()->all(),
            'meta' => [
                'elapsed_ms' => round($this->elapsedMs, 2),
            ],
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function isEmpty(): bool
    {
        return $this->models->isEmpty()
            && $this->tables->isEmpty()
            && $this->routes->isEmpty()
            && $this->controllerMethods->isEmpty();
    }
}
