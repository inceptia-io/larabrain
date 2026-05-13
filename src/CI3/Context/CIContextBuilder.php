<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\Context;

/**
 * CIContextBuilder
 *
 * Resolves a plain-language keyword into a structured context graph using
 * CodeIgniter 3's database layer (no Illuminate or CI4 dependencies).
 *
 * Uses the same app_brain_entities / app_brain_relations tables as the
 * Laravel and CI4 versions, so the schema is shared between frameworks.
 *
 * Query strategy
 * ──────────────
 *   1. Seed query   — match on name/key for all entity types.
 *   2. Relation expansion — walk one hop through app_brain_relations.
 *   3. Return CIContextResult with plain arrays.
 *
 * CI3 Active Record (Query Builder) differs from CI4:
 *   • from()         instead of table()
 *   • where_in()     instead of whereIn()
 *   • group_start()  instead of groupStart()
 *   • group_end()    instead of groupEnd()
 *   • or_like()      instead of orLike()
 *   • result_array() instead of getResultArray() on the result object
 */
final class CIContextBuilder
{
    private const SEED_TYPES = ['model', 'table', 'route', 'controller_method'];

    /**
     * CI3 database object (CI_DB_query_builder).
     *
     * @var object
     */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function build(string $keyword): CIContextResult
    {
        $start   = microtime(true);
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
            $keyword,
            array_values($allModels),
            array_values($tables),
            array_values($allRoutes),
            array_values($allMethods),
            $this->elapsed($start)
        );
    }

    // ── Queries ────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seedQuery(string $keyword): array
    {
        $this->db->from('app_brain_entities');
        $this->db->where_in('type', self::SEED_TYPES);
        $this->db->where('is_active', 1);
        $this->db->group_start();
        $this->db->like('name', $keyword);
        $this->db->or_like('key', $keyword);
        $this->db->group_end();
        $query = $this->db->get();
        $rows  = $query->result_array();

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

        $this->db->select('target_entity_id');
        $this->db->from('app_brain_relations');
        $this->db->where_in('source_entity_id', $modelIds);
        $this->db->where('relation_type', 'used_by');
        $query     = $this->db->get();
        $targetIds = array_column($query->result_array(), 'target_entity_id');

        if (empty($targetIds)) {
            return [];
        }

        $this->db->from('app_brain_entities');
        $this->db->where_in('id', $targetIds);
        $this->db->where('type', 'controller_method');
        $this->db->where('is_active', 1);
        $query = $this->db->get();
        $rows  = $query->result_array();

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

        $this->db->select('target_entity_id');
        $this->db->from('app_brain_relations');
        $this->db->where_in('source_entity_id', $methodIds);
        $this->db->where('relation_type', 'called_by');
        $query     = $this->db->get();
        $targetIds = array_column($query->result_array(), 'target_entity_id');

        if (empty($targetIds)) {
            return [];
        }

        $this->db->from('app_brain_entities');
        $this->db->where_in('id', $targetIds);
        $this->db->where('type', 'route');
        $this->db->where('is_active', 1);
        $query = $this->db->get();
        $rows  = $query->result_array();

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

        $this->db->select('source_entity_id');
        $this->db->from('app_brain_relations');
        $this->db->where_in('target_entity_id', $methodIds);
        $this->db->where('relation_type', 'used_by');
        $query     = $this->db->get();
        $sourceIds = array_column($query->result_array(), 'source_entity_id');

        if (empty($sourceIds)) {
            return [];
        }

        $this->db->from('app_brain_entities');
        $this->db->where_in('id', $sourceIds);
        $this->db->where('type', 'model');
        $this->db->where('is_active', 1);
        $query = $this->db->get();
        $rows  = $query->result_array();

        return array_map([$this, 'normaliseRow'], $rows);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $decoded = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  string  $type
     * @return array<int, array<string, mixed>>
     */
    private function whereType(array $rows, string $type): array
    {
        return array_values(
            array_filter($rows, static function (array $row) use ($type): bool {
                return ($row['type'] ?? '') === $type;
            })
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueById(array $rows): array
    {
        $seen   = [];
        $result = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ($id !== null && !isset($seen[$id])) {
                $seen[$id]  = true;
                $result[$id] = $row;
            }
        }

        return $result;
    }

    private function elapsed(float $start): float
    {
        return (microtime(true) - $start) * 1000;
    }
}
