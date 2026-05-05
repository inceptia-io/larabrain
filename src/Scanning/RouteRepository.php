<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

use Arafat\Brain\Models\Entity;
use Arafat\Brain\Models\Relation as BrainRelation;
use Arafat\Brain\Models\Snapshot;
use Arafat\Brain\Scanning\Parsers\ParsedRoute;
use Illuminate\Support\Facades\DB;

/**
 * Handles persistence of route data into:
 *   app_brain_entities   (type = "route")
 *   app_brain_relations  (route ──[calls]──▶ controller entity)
 *   app_brain_snapshots
 *
 * Key invariants
 * ──────────────
 * • Entity key  = "route.{routeKey}"  where routeKey = "{METHOD}:{uri}"
 *   e.g. "route.GET:/api/users/{user}"
 * • Routes with no controller (closures) are stored but have no Relation.
 * • Controller Entity keys = "controller.{fqcn}" — created as placeholders
 *   if the ControllerScanner has not yet run.
 * • A global snapshot (entity_id = null) records the full route inventory
 *   each time the collection hash changes.
 * • Soft-deleted route entities are restored when the route reappears.
 */
final class RouteRepository
{
    private const ROUTE_TYPE = 'route';

    private const CONTROLLER_TYPE = 'controller';

    private const RELATION_TYPE = 'calls';

    private const SCANNER_ACTOR = 'brain:route-scanner';

    // ── Change detection ───────────────────────────────────────────────────────

    /**
     * The RouteScanner stores a single global sentinel Entity
     * (key = "route_collection.hash") to track whether the full set of
     * registered routes has changed since the last scan.
     */
    public function collectionHashChanged(string $hash): bool
    {
        $sentinel = Entity::withTrashed()
            ->where('type', 'route_meta')
            ->where('key', 'route_collection.hash')
            ->first();

        // The sentinel stores its hash under 'hash', not 'source_hash'.
        // We inline the comparison here rather than delegating to FileHasher
        // so as not to misrepresent the sentinel's schema.
        if ($sentinel === null) {
            return true;
        }

        return ($sentinel->metadata?->get('hash') ?? '') !== $hash;
    }

    // ── Persistence ────────────────────────────────────────────────────────────

    /**
     * Persist the full route collection.
     *
     * @param  ParsedRoute[]  $routes
     */
    public function persistAll(array $routes, string $collectionHash): void
    {
        DB::transaction(function () use ($routes, $collectionHash): void {
            foreach ($routes as $route) {
                $this->persistOne($route);
            }

            $this->updateCollectionSentinel($collectionHash, count($routes));
            $this->writeGlobalSnapshot($routes, $collectionHash);
        });
    }

    // ── Single route ───────────────────────────────────────────────────────────

    private function persistOne(ParsedRoute $route): void
    {
        $entity = $this->upsertRouteEntity($route);

        // Soft-delete any previous outgoing "calls" relations from this route
        BrainRelation::where('source_entity_id', $entity->id)
            ->where('type', self::RELATION_TYPE)
            ->delete();

        if ($route->controllerFqcn !== null) {
            $controllerEntity = $this->resolveOrCreateControllerEntity($route->controllerFqcn);

            BrainRelation::create([
                'source_entity_id' => $entity->id,
                'target_entity_id' => $controllerEntity->id,
                'type' => self::RELATION_TYPE,
                'weight' => 1.0,
                'metadata' => [
                    'method' => $route->controllerMethod,
                ],
            ]);
        }
    }

    private function upsertRouteEntity(ParsedRoute $route): Entity
    {
        $key = "route.{$route->routeKey}";
        $metadata = $this->buildRouteMetadata($route);

        /** @var Entity $entity */
        $entity = Entity::withTrashed()->firstOrNew([
            'type' => self::ROUTE_TYPE,
            'key' => $key,
        ]);

        if ($entity->exists && $entity->trashed()) {
            $entity->restore();
        }

        // Friendly display name: "GET /api/users/{user}" or route name if present
        $displayName = $route->name
            ?? (implode('|', $route->methods).' /'.ltrim($route->uri, '/'));

        $entity->fill([
            'name' => $displayName,
            'description' => $this->buildDescription($route),
            'metadata' => $metadata,
            'is_active' => true,
        ]);

        $entity->save();

        return $entity;
    }

    // ── Controller entity ──────────────────────────────────────────────────────

    /**
     * Find an existing controller entity or create a lightweight placeholder.
     * The ControllerScanner will enrich it when it runs.
     */
    private function resolveOrCreateControllerEntity(string $fqcn): Entity
    {
        $key = "controller.{$fqcn}";

        /** @var Entity|null $entity */
        $entity = Entity::withTrashed()
            ->where('type', self::CONTROLLER_TYPE)
            ->where('key', $key)
            ->first();

        if ($entity !== null) {
            if ($entity->trashed()) {
                $entity->restore();
            }

            return $entity;
        }

        $shortName = ltrim((string) strrchr($fqcn, '\\'), '\\') ?: $fqcn;

        return Entity::create([
            'type' => self::CONTROLLER_TYPE,
            'key' => $key,
            'name' => $shortName,
            'description' => "Placeholder for controller '{$fqcn}' (not yet scanned).",
            'metadata' => ['fqcn' => $fqcn, 'placeholder' => true],
            'is_active' => false,
        ]);
    }

    // ── Collection sentinel ────────────────────────────────────────────────────

    private function updateCollectionSentinel(string $hash, int $routeCount): void
    {
        $sentinel = Entity::withTrashed()->firstOrNew([
            'type' => 'route_meta',
            'key' => 'route_collection.hash',
        ]);

        if ($sentinel->exists && $sentinel->trashed()) {
            $sentinel->restore();
        }

        $sentinel->fill([
            'name' => 'Route collection hash',
            'metadata' => [
                'hash' => $hash,
                'route_count' => $routeCount,
                'last_scanned_at' => now()->toIso8601String(),
            ],
            'is_active' => true,
        ]);

        $sentinel->save();
    }

    // ── Global snapshot ────────────────────────────────────────────────────────

    private function writeGlobalSnapshot(array $routes, string $hash): void
    {
        $payload = [
            'hash' => $hash,
            'route_count' => count($routes),
            'routes' => array_map(
                static fn (ParsedRoute $r) => $r->toArray(),
                $routes,
            ),
        ];

        Snapshot::create([
            'entity_id' => null,            // global snapshot — not tied to one entity
            'version' => $this->nextGlobalVersion(),
            'payload' => $payload,
            'created_by' => self::SCANNER_ACTOR,
            'metadata' => ['type' => 'route_collection'],
        ]);
    }

    private function nextGlobalVersion(): int
    {
        $latest = Snapshot::whereNull('entity_id')->max('version');

        return (int) ($latest ?? 0) + 1;
    }

    // ── Metadata helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildRouteMetadata(ParsedRoute $route): array
    {
        return [
            'uri' => $route->uri,
            'methods' => $route->methods,
            'name' => $route->name,
            'controller_fqcn' => $route->controllerFqcn,
            'controller_method' => $route->controllerMethod,
            'middleware' => $route->middleware,
            'last_scanned_at' => now()->toIso8601String(),
        ];
    }

    private function buildDescription(ParsedRoute $route): string
    {
        $methods = implode(', ', $route->methods);
        $action = $route->controllerFqcn
            ? "{$route->controllerFqcn}@{$route->controllerMethod}"
            : 'closure';

        return "[{$methods}] /{$route->uri} → {$action}";
    }
}
