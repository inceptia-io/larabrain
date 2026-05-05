<?php

declare(strict_types=1);

namespace Arafat\Brain\Context;

use Arafat\Brain\Models\Entity;
use Arafat\Brain\Models\Relation as BrainRelation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a plain-language keyword into a structured context graph that
 * surfaces everything the Brain knowledge store knows about that concept.
 *
 * Query strategy
 * ──────────────
 * The builder executes a small, fixed number of DB round-trips so that the
 * result is O(1) in query count regardless of how many entities are stored:
 *
 *   1. Seed query   — full-text match on `name` and `key` for all relevant
 *                     entity types (model, table, route, controller_method).
 *                     One query, four entity types, no N+1.
 *
 *   2. Relation expansion (two queries) — for each seed model/table entity,
 *      walk one hop through app_brain_relations to pull in:
 *        • controller_method entities that [use] those models
 *        • route entities that [calls] controller entities linked to those models
 *
 *   3. Hydration     — targets already loaded via eager-load on step 2.
 *
 * All queries respect soft-deletes (only active / non-trashed rows).
 *
 * Entity shape in each collection
 * ────────────────────────────────
 * Each item is a plain array derived from the Entity model.  The metadata
 * Collection is serialised to a plain array so the result is immediately
 * json_encode()-able.
 *
 * {
 *   "id":          1,
 *   "ulid":        "01JXXXXXXXXXXXXXXXXXXXXXXX",
 *   "type":        "model",
 *   "key":         "model.App\\Models\\Product",
 *   "name":        "Product",
 *   "description": "...",
 *   "is_active":   true,
 *   "metadata":    { ... }
 * }
 */
final class ContextBuilder
{
    /** Entity types searched in the seed query. */
    private const SEED_TYPES = ['model', 'table', 'route', 'controller_method'];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Build a context for the given keyword.
     *
     * @param  string  $keyword  Free-form search term, e.g. "product"
     */
    public function build(string $keyword): ContextResult
    {
        $start = hrtime(true);

        $keyword = trim($keyword);

        if ($keyword === '') {
            return ContextResult::empty('', $this->elapsed($start));
        }

        // ── 1. Seed match ──────────────────────────────────────────────────────
        $seeds = $this->seedQuery($keyword);

        // ── 2. Expand via relation graph ───────────────────────────────────────
        $models = $seeds->where('type', 'model');
        $tables = $seeds->where('type', 'table');
        $routes = $seeds->where('type', 'route');
        $methods = $seeds->where('type', 'controller_method');

        // 2a. From matched models → find controller_methods that [use] them
        $expandedMethods = $this->expandMethodsFromModels($models);

        // 2b. From matched models → find routes that call controllers that use them
        $expandedRoutes = $this->expandRoutesFromMethods(
            $methods->merge($expandedMethods),
        );

        // 2c. From matched routes or methods → pull in any models they reference
        $expandedModels = $this->expandModelsFromMethods(
            $methods->merge($expandedMethods),
        );

        // Merge seed and expanded sets, de-duplicate by entity id
        $allModels = $models->merge($expandedModels)->unique('id');
        $allRoutes = $routes->merge($expandedRoutes)->unique('id');
        $allMethods = $methods->merge($expandedMethods)->unique('id');

        return new ContextResult(
            keyword: $keyword,
            models: $allModels->map(fn (Entity $e) => $this->toArray($e)),
            tables: $tables->map(fn (Entity $e) => $this->toArray($e)),
            routes: $allRoutes->map(fn (Entity $e) => $this->toArray($e)),
            controllerMethods: $allMethods->map(fn (Entity $e) => $this->toArray($e)),
            elapsedMs: $this->elapsed($start),
        );
    }

    // ── Query helpers ──────────────────────────────────────────────────────────

    /**
     * Seed query: find entities whose name or key contains the keyword
     * (case-insensitive LIKE), restricted to the four relevant types.
     *
     * Returns a keyed Collection<int id, Entity>.
     */
    private function seedQuery(string $keyword): Collection
    {
        $term = '%'.$this->escapeLike($keyword).'%';

        return Entity::query()
            ->whereIn('type', self::SEED_TYPES)
            ->where(static function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('key', 'like', $term);
            })
            ->get()
            ->keyBy('id');
    }

    /**
     * Given a collection of model entities, find controller_method entities
     * that have an outgoing [uses] relation whose target is one of those models.
     *
     * One query: join entities ← relations → entities.
     *
     * @param  Collection<int, Entity>  $modelEntities
     * @return Collection<int, Entity>
     */
    private function expandMethodsFromModels(Collection $modelEntities): Collection
    {
        if ($modelEntities->isEmpty()) {
            return collect();
        }

        $modelIds = $modelEntities->keys()->all();

        // Find controller_method entities that [uses] one of our model entities
        $methodIds = BrainRelation::query()
            ->where('type', 'uses')
            ->whereIn('target_entity_id', $modelIds)
            ->pluck('source_entity_id')
            ->unique()
            ->all();

        if (empty($methodIds)) {
            return collect();
        }

        return Entity::query()
            ->where('type', 'controller_method')
            ->whereIn('id', $methodIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Given controller_method entities, find route entities that [calls] the
     * parent controller of those methods.
     *
     * Two-hop: method → controller (via metadata.fqcn) → route [calls] controller
     *
     * Rather than parsing metadata JSON per-row, we collect the controller FQCNs
     * from the already-loaded method entities (no extra DB round-trip for that
     * step), then do one targeted query.
     *
     * @param  Collection<int, Entity>  $methodEntities
     * @return Collection<int, Entity>
     */
    private function expandRoutesFromMethods(Collection $methodEntities): Collection
    {
        if ($methodEntities->isEmpty()) {
            return collect();
        }

        // Gather unique controller FQCNs referenced by the method entities
        $controllerFqcns = $methodEntities
            ->map(static fn (Entity $e) => $e->metadata?->get('fqcn'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($controllerFqcns)) {
            return collect();
        }

        // Resolve controller entity ids from the FQCN keys
        $controllerIds = Entity::query()
            ->where('type', 'controller')
            ->whereIn('key', array_map(
                static fn (string $fqcn) => "controller.{$fqcn}",
                $controllerFqcns,
            ))
            ->pluck('id')
            ->all();

        if (empty($controllerIds)) {
            return collect();
        }

        // Route entities that [calls] one of those controllers
        $routeIds = BrainRelation::query()
            ->where('type', 'calls')
            ->whereIn('target_entity_id', $controllerIds)
            ->pluck('source_entity_id')
            ->unique()
            ->all();

        if (empty($routeIds)) {
            return collect();
        }

        return Entity::query()
            ->where('type', 'route')
            ->whereIn('id', $routeIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Given controller_method entities, pull back the model entities they
     * reference — the inverse expansion of expandMethodsFromModels().
     *
     * One query: find [uses] relations originating from these methods.
     *
     * @param  Collection<int, Entity>  $methodEntities
     * @return Collection<int, Entity>
     */
    private function expandModelsFromMethods(Collection $methodEntities): Collection
    {
        if ($methodEntities->isEmpty()) {
            return collect();
        }

        $methodIds = $methodEntities->keys()->all();

        $modelIds = BrainRelation::query()
            ->where('type', 'uses')
            ->whereIn('source_entity_id', $methodIds)
            ->pluck('target_entity_id')
            ->unique()
            ->all();

        if (empty($modelIds)) {
            return collect();
        }

        return Entity::query()
            ->where('type', 'model')
            ->whereIn('id', $modelIds)
            ->get()
            ->keyBy('id');
    }

    // ── Serialisation helper ───────────────────────────────────────────────────

    /**
     * Convert an Entity Eloquent model to a plain array.
     *
     * metadata is an Illuminate Collection — we call toArray() so the result
     * is a plain PHP array and remains JSON-serialisable without Eloquent.
     *
     * @return array<string, mixed>
     */
    private function toArray(Entity $entity): array
    {
        return [
            'id' => $entity->id,
            'ulid' => $entity->ulid,
            'type' => $entity->type,
            'key' => $entity->key,
            'name' => $entity->name,
            'description' => $entity->description,
            'is_active' => $entity->is_active,
            'metadata' => $entity->metadata?->toArray() ?? [],
        ];
    }

    // ── Utility ────────────────────────────────────────────────────────────────

    /**
     * Escape characters that have special meaning in SQL LIKE patterns.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Return elapsed milliseconds since the hrtime $start nanosecond timestamp.
     */
    private function elapsed(int $start): float
    {
        return (hrtime(true) - $start) / 1_000_000;
    }
}
