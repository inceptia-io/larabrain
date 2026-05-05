<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

use Arafat\Brain\Models\Entity;
use Arafat\Brain\Models\Snapshot;
use Arafat\Brain\Scanning\Parsers\ParsedMigration;

/**
 * Handles persistence of parsed migration data into app_brain_entities
 * and app_brain_snapshots.
 *
 * Key invariants
 * ──────────────
 * • Each database table is stored as one Entity with type="table".
 * • The entity key is "table.{table_name}" (globally unique).
 * • The SHA-256 hash of the source file is stored in metadata['source_hash'].
 * • A new Snapshot is written on every content change (hash mismatch).
 * • Soft-deleted entities are restored when the table is re-discovered.
 */
final class MigrationRepository
{
    private const ENTITY_TYPE = 'table';

    private const SCANNER_ACTOR = 'brain:migration-scanner';

    // ── Change detection ───────────────────────────────────────────────────────

    /**
     * Returns true when the given file hash differs from what was last stored.
     * A missing entity is treated as "changed" (i.e. new).
     */
    public function hasChanged(string $tableKey, string $hash): bool
    {
        $entity = Entity::withTrashed()
            ->where('type', self::ENTITY_TYPE)
            ->where('key', $tableKey)
            ->first();

        return FileHasher::entityNeedsRescan($entity, $hash);
    }

    // ── Persistence ────────────────────────────────────────────────────────────

    /**
     * Upsert the Entity for the given table and append a new Snapshot.
     *
     * @param  string  $filePath  Absolute path to the migration file
     * @param  string  $hash  SHA-256 hex digest of the file contents
     */
    public function persist(ParsedMigration $parsed, string $filePath, string $hash): Entity
    {
        $key = "table.{$parsed->tableName}";
        $metadata = $this->buildMetadata($parsed, $filePath, $hash);

        /** @var Entity $entity */
        $entity = Entity::withTrashed()->firstOrNew([
            'type' => self::ENTITY_TYPE,
            'key' => $key,
        ]);

        // Restore a previously soft-deleted entity that has reappeared
        if ($entity->exists && $entity->trashed()) {
            $entity->restore();
        }

        $entity->fill([
            'name' => $parsed->tableName,
            'description' => "Database table '{$parsed->tableName}' extracted from migration scan.",
            'metadata' => $metadata,
            'is_active' => true,
        ]);

        $entity->save();

        $this->writeSnapshot($entity, $parsed, $filePath, $metadata);

        return $entity;
    }

    // ── Snapshot ───────────────────────────────────────────────────────────────

    private function writeSnapshot(
        Entity $entity,
        ParsedMigration $parsed,
        string $filePath,
        array $metadata,
    ): void {
        Snapshot::create([
            'entity_id' => $entity->id,
            'version' => $this->nextVersion($entity),
            'payload' => $metadata,
            'created_by' => self::SCANNER_ACTOR,
            'metadata' => [
                'source_file' => $filePath,
                'operation' => $parsed->operation,
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
     * Builds the structured metadata payload stored on the Entity and inside
     * the Snapshot's payload column.
     *
     * Schema:
     * {
     *   "source_file":   "/absolute/path/to/migration.php",
     *   "source_hash":   "<sha256 hex>",
     *   "operation":     "create" | "alter",
     *   "columns": [
     *     { "name": "id", "type": "bigint", "nullable": false, ... },
     *     ...
     *   ],
     *   "relationships": [
     *     { "local_column": "user_id", "referenced_table": "users",
     *       "referenced_column": "id", "on_delete": "cascade", "on_update": "restrict" },
     *     ...
     *   ]
     * }
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(ParsedMigration $parsed, string $filePath, string $hash): array
    {
        return [
            'source_file' => $filePath,
            'source_hash' => $hash,
            'last_scanned_at' => now()->toIso8601String(),
            'operation' => $parsed->operation,
            'columns' => array_map(
                static fn ($c) => $c->toArray(),
                $parsed->columns,
            ),
            'relationships' => array_map(
                static fn ($f) => $f->toArray(),
                $parsed->foreignKeys,
            ),
        ];
    }
}
