<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

use Arafat\Brain\Models\Entity;
use Arafat\Brain\Models\Relation as BrainRelation;
use Arafat\Brain\Models\Snapshot;
use Arafat\Brain\Scanning\Parsers\ParsedController;
use Arafat\Brain\Scanning\Parsers\ParsedMethod;
use Illuminate\Support\Facades\DB;

/**
 * Handles persistence of parsed controller data into:
 *   app_brain_entities   (type = "controller")         — one per class file
 *   app_brain_entities   (type = "controller_method")  — one per public method
 *   app_brain_relations  (controller_method ──[uses]──▶ model entity)
 *   app_brain_snapshots
 *
 * Key invariants
 * ──────────────
 * • Controller entity key  = "controller.{fqcn}"
 * • Method entity key      = "controller_method.{fqcn}::{methodName}"
 * • source_hash is stored on the controller entity for change detection
 * • On change, all existing controller_method entities for that controller
 *   are soft-deleted, then the current set is inserted / restored
 * • "uses" relations link each method entity to the model entities it touches
 * • Model entity placeholders (type="model") are created when a model has not
 *   yet been scanned by the ModelScanner
 * • Soft-deleted entities are restored on rediscovery
 */
final class ControllerRepository
{
    private const CONTROLLER_TYPE = 'controller';

    private const CONTROLLER_METHOD_TYPE = 'controller_method';

    private const MODEL_TYPE = 'model';

    private const USES_RELATION = 'uses';

    private const SCANNER_ACTOR = 'brain:controller-scanner';

    // ── Change detection ───────────────────────────────────────────────────────

    public function hasChanged(string $controllerKey, string $hash): bool
    {
        $entity = Entity::withTrashed()
            ->where('type', self::CONTROLLER_TYPE)
            ->where('key', $controllerKey)
            ->first();

        return FileHasher::entityNeedsRescan($entity, $hash);
    }

    // ── Persistence ────────────────────────────────────────────────────────────

    public function persist(ParsedController $parsed, string $filePath, string $hash): Entity
    {
        return DB::transaction(function () use ($parsed, $filePath, $hash): Entity {
            $controllerEntity = $this->upsertControllerEntity($parsed, $filePath, $hash);

            // Retire methods from a previous scan of this file
            $this->retireStaleMethodEntities($controllerEntity->id, $parsed->methods);

            // Persist each method and its relations
            foreach ($parsed->methods as $method) {
                $methodEntity = $this->upsertMethodEntity($controllerEntity, $method);
                $this->syncUsesRelations($methodEntity, $method->usedModels);
            }

            $this->writeSnapshot($controllerEntity, $parsed, $filePath, $hash);

            return $controllerEntity;
        });
    }

    // ── Controller entity ──────────────────────────────────────────────────────

    private function upsertControllerEntity(
        ParsedController $parsed,
        string $filePath,
        string $hash,
    ): Entity {
        $key = "controller.{$parsed->fqcn}";

        /** @var Entity $entity */
        $entity = Entity::withTrashed()->firstOrNew([
            'type' => self::CONTROLLER_TYPE,
            'key' => $key,
        ]);

        if ($entity->exists && $entity->trashed()) {
            $entity->restore();
        }

        $shortName = ltrim((string) strrchr($parsed->fqcn, '\\'), '\\') ?: $parsed->className;

        $entity->fill([
            'name' => $shortName,
            'description' => "Controller {$parsed->fqcn}",
            'metadata' => [
                'fqcn' => $parsed->fqcn,
                'source_file' => $filePath,
                'source_hash' => $hash,
                'last_scanned_at' => now()->toIso8601String(),
                'method_count' => count($parsed->methods),
                'placeholder' => false,
            ],
            'is_active' => true,
        ]);

        $entity->save();

        return $entity;
    }

    // ── Method entities ────────────────────────────────────────────────────────

    /**
     * Soft-delete any existing controller_method entities whose method name is
     * no longer present in the current parse result (renamed or removed methods).
     *
     * @param  ParsedMethod[]  $currentMethods
     */
    private function retireStaleMethodEntities(int $controllerEntityId, array $currentMethods): void
    {
        $currentNames = array_map(static fn (ParsedMethod $m) => $m->name, $currentMethods);

        // Find method entities that belong to this controller
        Entity::where('type', self::CONTROLLER_METHOD_TYPE)
            ->whereJsonContains('metadata->controller_entity_id', $controllerEntityId)
            ->whereNotIn('name', $currentNames)
            ->delete();
    }

    private function upsertMethodEntity(Entity $controllerEntity, ParsedMethod $method): Entity
    {
        $key = "controller_method.{$controllerEntity->metadata->get('fqcn')}::{$method->name}";

        /** @var Entity $entity */
        $entity = Entity::withTrashed()->firstOrNew([
            'type' => self::CONTROLLER_METHOD_TYPE,
            'key' => $key,
        ]);

        if ($entity->exists && $entity->trashed()) {
            $entity->restore();
        }

        $entity->fill([
            'name' => "{$controllerEntity->name}::{$method->name}",
            'description' => "Method {$method->name} on {$controllerEntity->metadata->get('fqcn')}",
            'metadata' => [
                'controller_entity_id' => $controllerEntity->id,
                'fqcn' => $controllerEntity->metadata->get('fqcn'),
                'method_name' => $method->name,
                'params' => $method->params,
                'validation_rules' => $method->validationRules,
                'used_models' => $method->usedModels,
                'last_scanned_at' => now()->toIso8601String(),
            ],
            'is_active' => true,
        ]);

        $entity->save();

        return $entity;
    }

    // ── "uses" relations ───────────────────────────────────────────────────────

    /**
     * Replace all outgoing "uses" relations from a method entity with the
     * current set of referenced model FQCNs.
     *
     * @param  string[]  $modelFqcns
     */
    private function syncUsesRelations(Entity $methodEntity, array $modelFqcns): void
    {
        // Soft-delete all previous "uses" relations from this method
        BrainRelation::where('source_entity_id', $methodEntity->id)
            ->where('type', self::USES_RELATION)
            ->delete();

        foreach ($modelFqcns as $fqcn) {
            $modelEntity = $this->resolveOrCreateModelEntity($fqcn);

            BrainRelation::create([
                'source_entity_id' => $methodEntity->id,
                'target_entity_id' => $modelEntity->id,
                'type' => self::USES_RELATION,
                'weight' => 1.0,
                'metadata' => ['model_fqcn' => $fqcn],
            ]);
        }
    }

    /**
     * Find an existing model entity or create a lightweight placeholder.
     * The ModelScanner will enrich it when it runs.
     */
    private function resolveOrCreateModelEntity(string $fqcn): Entity
    {
        $key = "model.{$fqcn}";

        /** @var Entity|null $entity */
        $entity = Entity::withTrashed()
            ->where('type', self::MODEL_TYPE)
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
            'type' => self::MODEL_TYPE,
            'key' => $key,
            'name' => $shortName,
            'description' => "Placeholder for model '{$fqcn}' (not yet scanned).",
            'metadata' => ['fqcn' => $fqcn, 'placeholder' => true],
            'is_active' => false,
        ]);
    }

    // ── Snapshot ───────────────────────────────────────────────────────────────

    private function writeSnapshot(
        Entity $controllerEntity,
        ParsedController $parsed,
        string $filePath,
        string $hash,
    ): void {
        $latest = Snapshot::where('entity_id', $controllerEntity->id)->max('version');
        $version = (int) ($latest ?? 0) + 1;

        Snapshot::create([
            'entity_id' => $controllerEntity->id,
            'version' => $version,
            'payload' => $parsed->toArray(),
            'created_by' => self::SCANNER_ACTOR,
            'metadata' => [
                'source_file' => $filePath,
                'source_hash' => $hash,
            ],
        ]);
    }
}
