<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing one registered Laravel route extracted from the
 * Route collection at runtime.
 */
final class ParsedRoute
{
    /**
     * @param  string  $uri  e.g. "api/users/{user}"
     * @param  string[]  $methods  e.g. ["GET", "HEAD"]
     * @param  string|null  $name  Named route, e.g. "users.show"
     * @param  string|null  $controllerFqcn  FQCN of the controller class, null for closures
     * @param  string|null  $controllerMethod  The action method name, e.g. "show"
     * @param  string[]  $middleware  e.g. ["auth", "throttle:60,1"]
     * @param  string  $routeKey  Stable key used as Brain entity key
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $methods,
        public readonly ?string $name,
        public readonly ?string $controllerFqcn,
        public readonly ?string $controllerMethod,
        public readonly array $middleware,
        public readonly string $routeKey,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'methods' => $this->methods,
            'name' => $this->name,
            'controller_fqcn' => $this->controllerFqcn,
            'controller_method' => $this->controllerMethod,
            'middleware' => $this->middleware,
            'route_key' => $this->routeKey,
        ];
    }
}
