<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

use Arafat\Brain\Models\Entity;
use Arafat\Brain\Models\Relation as BrainRelation;
use Arafat\Brain\Models\Snapshot;
use Arafat\Brain\Scanning\Parsers\ParsedModel;
use Arafat\Brain\Scanning\Parsers\ParsedRelationship;
use Illuminate\Support\Facades\DB;

/**
 * Handles persistence of parsed model data into:
 *   app_brain_entities   (type = "model")
 *   app_brain_relations  (model ──[relType]──▶ related-model)
 *   app_brain_snapshots
 *
 * Key invariants
 * ──────────────
 * • Entity key = "model.{fqcn}"  (globally unique)
 * • metadata['source_hash'] drives change detection
 * • Relations are fully replaced on each change (delete + re-insert)
 * • Soft-deleted entities are restored when the model reappears
 * • When a related model entity does not yet exist, a placeholder is created
 *   so the Relation can be recorded immediately
 */
final class ModelRepository
{
    private const ENTITY_TYPE = 'model';

    private const SCANNER_ACTOR = 'brain:model-scanner';

    // ── Change detection ───────────────────────────────────────────────────────

    public function hasChanged(string $modelKey, string $hash): bool
    {
        $entity = Entity::withTrashed()
            ->where('type', self::ENTITY_TYPE)
            ->where('key', $modelKey)
            ->first();

        return FileHasher::entityNeedsRescan($entity, $hash);
    }

    // ── Persistence ────────────────────────────────────────────────────────────

    public function persist(ParsedModel $parsed, string $filePath, string $hash): Entity
    {
        return DB::transaction(function () use ($parsed, $filePath, $hash): Entity {
            $entity = $this->upsertEntity($parsed, $filePath, $hash);

            $this->syncRelations($entity, $parsed->relationships);
            $this->writeSnapshot($entity, $parsed, $filePath, $hash);

            return $entity;
        });
    }

    // ── Entity upsert ──────────────────────────────────────────────────────────

    private function upsertEntity(ParsedModel $parsed, string $filePath, string $hash): Entity
    {
        $key = "model.{$parsed->fqcn}";
        $metadata = $this->buildMetadata($parsed, $filePath, $hash);

        /** @var Entity $entity */
        $entity = Entity::withTrashed()->firstOrNew([
            'type' => self::ENTITY_TYPE,
            'key' => $key,
        ]);

        if ($entity->exists && $entity->trashed()) {
            $entity->restore();
        }

        $entity->fill([
            'name' => $parsed->className,
            'description' => "Eloquent model '{$parsed->fqcn}' mapped to table '{$parsed->tableName}'.",
            'metadata' => $metadata,
            'is_active' => true,
        ]);

        $entity->save();

        return $entity;
    }

    // ── Relation sync ──────────────────────────────────────────────────────────

    /**
     * Replace all model-to-model relations originating from $entity.
     *
     * We soft-delete any existing outgoing relations first, then re-insert
     * the current set — keeping history intact via soft-deletes.
     *
     * @param  ParsedRelationship[]  $relationships
     */
    private function syncRelations(Entity $entity, array $relationships): void
    {
        // Soft-delete all previous outgoing relations from this entity
        BrainRelation::where('source_entity_id', $entity->id)->delete();

        foreach ($relationships as $rel) {
            if ($rel->relatedClass === 'unknown') {
                continue;
            }

            $targetEntity = $this->resolveOrCreateModelEntity($rel->relatedClass);

            BrainRelation::create([
                'source_entity_id' => $entity->id,
                'target_entity_id' => $targetEntity->id,
                'type' => $rel->type,  // e.g. "hasMany", "belongsTo"
                'weight' => 1.0,
                'metadata' => [
                    'method' => $rel->method,
                    'foreign_key' => $rel->foreignKey,
                    'local_key' => $rel->localKey,
                ],
            ]);
        }
    }

    /**
     * Find an existing model entity by FQCN key, or create a lightweight
     * placeholder so the Relation can be recorded before that model is scanned.
     */
    private function resolveOrCreateModelEntity(string $fqcn): Entity
    {
        $key = "model.{$fqcn}";

        /** @var Entity|null $entity */
        $entity = Entity::withTrashed()
            ->where('type', self::ENTITY_TYPE)
            ->where('key', $key)
            ->first();

        if ($entity !== null) {
            if ($entity->trashed()) {
                $entity->restore();
            }

            return $entity;
        }

        // Placeholder — will be enriched when that file is scanned
        $shortName = class_basename(str_replace('\\', '/', $fqcn));

        $entity = Entity::create([
            'type' => self::ENTITY_TYPE,
            'key' => $key,
            'name' => $shortName,
            'description' => "Placeholder for model '{$fqcn}' (not yet scanned).",
            'metadata' => ['fqcn' => $fqcn, 'placeholder' => true],
            'is_active' => false,
        ]);

        return $entity;
    }

    // ── Snapshot ───────────────────────────────────────────────────────────────

    private function writeSnapshot(
        Entity $entity,
        ParsedModel $parsed,
        string $filePath,
        string $hash,
    ): void {
        Snapshot::create([
            'entity_id' => $entity->id,
            'version' => $this->nextVersion($entity),
            'payload' => $this->buildMetadata($parsed, $filePath, $hash),
            'created_by' => self::SCANNER_ACTOR,
            'metadata' => [
                'source_file' => $filePath,
                'fqcn' => $parsed->fqcn,
            ],
        ]);
    }

    private function nextVersion(Entity $entity): int
    {
        $latest = Snapshot::where('entity_id', $entity->id)->max('version');

        return (int) ($latest ?? 0) + 1;
    }

    // ── Metadata builder ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(ParsedModel $parsed, string $filePath, string $hash): array
    {
        return [
            'source_file' => $filePath,
            'source_hash' => $hash,
            'last_scanned_at' => now()->toIso8601String(),
            'fqcn' => $parsed->fqcn,
            'table_name' => $parsed->tableName,
            'primary_key' => $parsed->primaryKey,
            'fillable' => $parsed->fillable,
            'casts' => $parsed->casts,
            'relationships' => array_map(
                static fn (ParsedRelationship $r) => $r->toArray(),
                $parsed->relationships,
            ),
        ];
    }
}
