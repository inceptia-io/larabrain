<?php

declare(strict_types=1);

namespace Arafat\Brain\CI4\Context;

/**
 * CIContextBuilder
 *
 * Resolves a plain-language keyword into a structured context graph using
 * CodeIgniter 4's database layer (no Illuminate dependencies).
 *
 * Uses the same app_brain_entities / app_brain_relations tables as the
 * Laravel version, so the schema is shared between frameworks.
 *
 * Query strategy (identical to the Laravel version)
 * ──────────────────────────────────────────────────
 *   1. Seed query   — match on name/key for all entity types.
 *   2. Relation expansion — walk one hop through app_brain_relations.
 *   3. Return CIContextResult with plain arrays.
 */
final class CIContextBuilder
{
    private const SEED_TYPES = ['model', 'table', 'route', 'controller_method'];

    public function __construct(
        private readonly \CodeIgniter\Database\ConnectionInterface $db,
    ) {}

    public function build(string $keyword): CIContextResult
    {
        $start = hrtime(true);
        $keyword = trim($keyword);

        if ($keyword === '') {
            return CIContextResult::empty('', $this->elapsed($start));
        }

        // 1. Seed match
        $seeds = $this->seedQuery($keyword);

        // Partition by type
        $models  = $this->whereType($seeds, 'model');
        $tables  = $this->whereType($seeds, 'table');
        $routes  = $this->whereType($seeds, 'route');
        $methods = $this->whereType($seeds, 'controller_method');

        // 2. Expand
        $expandedMethods = $this->expandMethodsFromModels($models);
        $expandedRoutes  = $this->expandRoutesFromMethods(array_merge($methods, $expandedMethods));
        $expandedModels  = $this->expandModelsFromMethods(array_merge($methods, $expandedMethods));

        // Merge + deduplicate
        $allModels  = $this->uniqueById(array_merge($models, $expandedModels));
        $allRoutes  = $this->uniqueById(array_merge($routes, $expandedRoutes));
        $allMethods = $this->uniqueById(array_merge($methods, $expandedMethods));

        return new CIContextResult(
            keyword: $keyword,
            models: array_values($allModels),
            tables: array_values($tables),
            routes: array_values($allRoutes),
            controllerMethods: array_values($allMethods),
            elapsedMs: $this->elapsed($start),
        );
    }

    // ── Queries ────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seedQuery(string $keyword): array
    {
        $like = "%{$keyword}%";

        $rows = $this->db->table('app_brain_entities')
            ->whereIn('type', self::SEED_TYPES)
            ->where('is_active', 1)
            ->groupStart()
                ->like('name', $keyword)
                ->orLike('key', $keyword)
            ->groupEnd()
            ->get()
            ->getResultArray();

        return array_map([$this, 'normaliseRow'], $rows);
    }

    /**
     * From matched models → controller_methods that [use] them.
     *
     * @param  array<int, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    private function expandMethodsFromModels(array $models): array
    {
        if (empty($models)) {
            return [];
        }

        $modelIds = array_column($models, 'id');

        $targetIds = $this->db->table('app_brain_relations')
            ->select('target_entity_id')
            ->whereIn('source_entity_id', $modelIds)
            ->where('relation_type', 'used_by')
            ->get()
            ->getResultArray();

        $targetIds = array_column($targetIds, 'target_entity_id');

        if (empty($targetIds)) {
            return [];
        }

        $rows = $this->db->table('app_brain_entities')
            ->whereIn('id', $targetIds)
            ->where('type', 'controller_method')
            ->where('is_active', 1)
            ->get()
            ->getResultArray();

        return array_map([$this, 'normaliseRow'], $rows);
    }

    /**
     * From controller_methods → route entities that [calls] them.
     *
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<int, array<string, mixed>>
     */
    private function expandRoutesFromMethods(array $methods): array
    {
        if (empty($methods)) {
            return [];
        }

        $methodIds = array_column($methods, 'id');

        $targetIds = $this->db->table('app_brain_relations')
            ->select('target_entity_id')
            ->whereIn('source_entity_id', $methodIds)
            ->where('relation_type', 'called_by')
            ->get()
            ->getResultArray();

        $targetIds = array_column($targetIds, 'target_entity_id');

        if (empty($targetIds)) {
            return [];
        }

        $rows = $this->db->table('app_brain_entities')
            ->whereIn('id', $targetIds)
            ->where('type', 'route')
            ->where('is_active', 1)
            ->get()
            ->getResultArray();

        return array_map([$this, 'normaliseRow'], $rows);
    }

    /**
     * From controller_methods → model entities they reference.
     *
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<int, array<string, mixed>>
     */
    private function expandModelsFromMethods(array $methods): array
    {
        if (empty($methods)) {
            return [];
        }

        $methodIds = array_column($methods, 'id');

        $sourceIds = $this->db->table('app_brain_relations')
            ->select('source_entity_id')
            ->whereIn('target_entity_id', $methodIds)
            ->where('relation_type', 'used_by')
            ->get()
            ->getResultArray();

        $sourceIds = array_column($sourceIds, 'source_entity_id');

        if (empty($sourceIds)) {
            return [];
        }

        $rows = $this->db->table('app_brain_entities')
            ->whereIn('id', $sourceIds)
            ->where('type', 'model')
            ->where('is_active', 1)
            ->get()
            ->getResultArray();

        return array_map([$this, 'normaliseRow'], $rows);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        // Decode JSON metadata if stored as a string
        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $decoded = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entities
     * @param  string  $type
     * @return array<int, array<string, mixed>>
     */
    private function whereType(array $entities, string $type): array
    {
        return array_values(array_filter($entities, fn ($e) => ($e['type'] ?? '') === $type));
    }

    /**
     * Deduplicate by the entity id field.
     *
     * @param  array<int, array<string, mixed>>  $entities
     * @return array<int, array<string, mixed>>
     */
    private function uniqueById(array $entities): array
    {
        $seen = [];
        $result = [];

        foreach ($entities as $entity) {
            $id = $entity['id'] ?? null;

            if ($id !== null && !isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $entity;
            }
        }

        return $result;
    }

    private function elapsed(int $start): float
    {
        return (hrtime(true) - $start) / 1_000_000;
    }
}
