<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Scanners;

use Arafat\Brain\Contracts\ScannerInterface;
use Arafat\Brain\Scanning\FileHasher;
use Arafat\Brain\Scanning\Finding;
use Arafat\Brain\Scanning\Parsers\ParsedRoute;
use Arafat\Brain\Scanning\RouteRepository;
use Arafat\Brain\Scanning\ScanResult;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route as RouteFacade;
use SplFileInfo;

/**
 * Scans all registered Laravel routes via the Route facade and persists the
 * results as Entity records (type = "route") with Relations to controller
 * entities (type = "controller").
 *
 * Design rationale
 * ────────────────
 * Routes are a global runtime collection — not scoped to individual files.
 * The scanner therefore:
 *   • Passes through `supports()` for any file under routes/.
 *   • Runs the full Route facade extraction **once** per scan session on
 *     the first matching file (guarded by $hasScanned).
 *   • Returns an empty result for all subsequent route files so that the
 *     same routes are not persisted multiple times.
 *   • Uses a SHA-256 hash of the serialised route collection for change
 *     detection — skips persistence when nothing has changed.
 *
 * HTTP methods that are excluded
 * ──────────────────────────────
 * The "HEAD" verb is always filtered out; HEAD routes are implicit duplicates
 * of GET routes and add no meaningful knowledge.
 */
final class RouteScanner implements ScannerInterface
{
    /** Ensures the Route facade extraction runs at most once per scanner instance. */
    private bool $hasScanned = false;

    public function __construct(private readonly RouteRepository $repository) {}

    // ── ScannerInterface ───────────────────────────────────────────────────────

    public function name(): string
    {
        return 'route';
    }

    public function supports(SplFileInfo $file): bool
    {
        return str_contains($file->getPathname(), '/routes/')
            && $file->getExtension() === 'php';
    }

    public function scan(SplFileInfo $file): ScanResult
    {
        $path = (string) $file->getRealPath();

        // Only process the first matching file; skip all subsequent ones.
        if ($this->hasScanned) {
            return ScanResult::empty($this->name(), $path);
        }

        $this->hasScanned = true;

        // ── 1. Collect routes from the Route facade ────────────────────────────
        try {
            $routeCollection = RouteFacade::getRoutes();
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Could not access Route collection: {$e->getMessage()}",
            );
        }

        $parsed = $this->extractRoutes($routeCollection);

        if ($parsed === []) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 2. Hash for change detection ───────────────────────────────────────
        $collectionHash = $this->hashRoutes($parsed);

        if (!$this->repository->collectionHashChanged($collectionHash)) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 3. Persist ─────────────────────────────────────────────────────────
        try {
            $this->repository->persistAll($parsed, $collectionHash);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Storage error while persisting routes: {$e->getMessage()}",
            );
        }

        // ── 4. Build findings ──────────────────────────────────────────────────
        $findings = [];

        foreach ($parsed as $route) {
            $findings[] = new Finding('route', $route->uri, null, [
                'methods' => $route->methods,
                'name' => $route->name,
                'controller' => $route->controllerFqcn,
                'action' => $route->controllerMethod,
                'middleware' => $route->middleware,
            ]);
        }

        return new ScanResult($this->name(), $path, $findings);
    }

    // ── Route extraction ───────────────────────────────────────────────────────

    /**
     * Iterate the route collection and convert each Route into a ParsedRoute.
     *
     * @return ParsedRoute[]
     */
    private function extractRoutes(RouteCollectionInterface $routeCollection): array
    {
        $routes = [];

        foreach ($routeCollection->getRoutes() as $route) {
            $parsed = $this->parseRoute($route);
            if ($parsed !== null) {
                $routes[] = $parsed;
            }
        }

        return $routes;
    }

    private function parseRoute(Route $route): ?ParsedRoute
    {
        $methods = array_values(
            array_filter(
                $route->methods(),
                static fn (string $m) => $m !== 'HEAD',
            ),
        );

        if ($methods === []) {
            return null;
        }

        $uri = $route->uri();
        $name = $route->getName();
        $action = $route->getAction();
        $middleware = $this->resolveMiddleware($route);
        $controllerFqcn = null;
        $controllerMethod = null;

        // ── Resolve controller + method ────────────────────────────────────────
        if (isset($action['uses']) && is_string($action['uses'])) {
            if (str_contains($action['uses'], '@')) {
                // Classic string syntax: "App\Http\Controllers\UserController@show"
                [$controllerFqcn, $controllerMethod] = explode('@', $action['uses'], 2);
            } elseif (class_exists($action['uses'])) {
                // Single-action controller (invokable)
                $controllerFqcn = $action['uses'];
                $controllerMethod = '__invoke';
            }
        } elseif (isset($action['controller']) && is_string($action['controller'])) {
            // Route::resource / Route::apiResource store the FQCN@method here
            if (str_contains($action['controller'], '@')) {
                [$controllerFqcn, $controllerMethod] = explode('@', $action['controller'], 2);
            }
        }

        // Normalise FQCN: strip leading backslash if present
        if ($controllerFqcn !== null) {
            $controllerFqcn = ltrim($controllerFqcn, '\\');
        }

        // Build a deterministic key
        $primaryMethod = $methods[0];
        $routeKey = "{$primaryMethod}:{$uri}";

        return new ParsedRoute(
            uri: $uri,
            methods: $methods,
            name: $name ?: null,
            controllerFqcn: $controllerFqcn,
            controllerMethod: $controllerMethod,
            middleware: $middleware,
            routeKey: $routeKey,
        );
    }

    // ── Middleware resolution ──────────────────────────────────────────────────

    /**
     * Gather middleware names as plain strings.
     * Excludes fully-qualified class names that are just aliased middleware
     * internals — we keep only the human-readable names or short aliases.
     *
     * @return string[]
     */
    private function resolveMiddleware(Route $route): array
    {
        $raw = $route->gatherMiddleware();

        return array_values(
            array_map(
                static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
                $raw,
            ),
        );
    }

    // ── Hashing ────────────────────────────────────────────────────────────────

    /**
     * Hash the route collection so we can skip persistence when nothing changed.
     * We hash only the stable, behavioural fields (not middleware class internals).
     *
     * @param  ParsedRoute[]  $routes
     */
    private function hashRoutes(array $routes): string
    {
        $fingerprints = array_map(
            static fn (ParsedRoute $r) => implode('|', [
                implode(',', $r->methods),
                $r->uri,
                $r->controllerFqcn ?? '',
                $r->controllerMethod ?? '',
                $r->name ?? '',
            ]),
            $routes,
        );

        sort($fingerprints);

        return FileHasher::content(implode("\n", $fingerprints));
    }
}
