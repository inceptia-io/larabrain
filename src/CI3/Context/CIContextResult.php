<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\Context;

/**
 * CIContextResult
 *
 * Immutable value object returned by CIContextBuilder::build().
 * Uses plain PHP arrays for lightweight, framework-neutral data handling.
 * PHP 7.4+ compatible (no readonly properties).
 */
final class CIContextResult
{
    /** @var string */
    public $keyword;

    /** @var array<int, array<string, mixed>> */
    public $models;

    /** @var array<int, array<string, mixed>> */
    public $tables;

    /** @var array<int, array<string, mixed>> */
    public $routes;

    /** @var array<int, array<string, mixed>> */
    public $controllerMethods;

    /** @var float */
    public $elapsedMs;

    /**
     * @param string $keyword
     * @param array<int, array<string, mixed>> $models
     * @param array<int, array<string, mixed>> $tables
     * @param array<int, array<string, mixed>> $routes
     * @param array<int, array<string, mixed>> $controllerMethods
     * @param float $elapsedMs
     */
    public function __construct(
        string $keyword,
        array $models,
        array $tables,
        array $routes,
        array $controllerMethods,
        float $elapsedMs
    ) {
        $this->keyword           = $keyword;
        $this->models            = $models;
        $this->tables            = $tables;
        $this->routes            = $routes;
        $this->controllerMethods = $controllerMethods;
        $this->elapsedMs         = $elapsedMs;
    }

    public static function empty(string $keyword, float $elapsedMs): self
    {
        return new self($keyword, [], [], [], [], $elapsedMs);
    }

    // ── Serialisation ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'keyword' => $this->keyword,
            'summary' => [
                'models'             => count($this->models),
                'tables'             => count($this->tables),
                'routes'             => count($this->routes),
                'controller_methods' => count($this->controllerMethods),
            ],
            'models'             => array_values($this->models),
            'tables'             => array_values($this->tables),
            'routes'             => array_values($this->routes),
            'controller_methods' => array_values($this->controllerMethods),
            'meta' => [
                'elapsed_ms' => round($this->elapsedMs, 2),
            ],
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public function isEmpty(): bool
    {
        return empty($this->models)
            && empty($this->tables)
            && empty($this->routes)
            && empty($this->controllerMethods);
    }
}
