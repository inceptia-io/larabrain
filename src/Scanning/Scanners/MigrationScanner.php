<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Scanners;

use Arafat\Brain\Contracts\ScannerInterface;
use Arafat\Brain\Scanning\FileHasher;
use Arafat\Brain\Scanning\Finding;
use Arafat\Brain\Scanning\MigrationRepository;
use Arafat\Brain\Scanning\Parsers\MigrationParser;
use Arafat\Brain\Scanning\ScanResult;
use SplFileInfo;

/**
 * Scans Laravel migration files and persists extracted schema knowledge
 * as Entity records (type="table") in app_brain_entities.
 *
 * Pipeline for each file
 * ──────────────────────
 * 1. Read file contents — return an error result if unreadable.
 * 2. Compute SHA-256 hash — skip if the file has not changed since last scan.
 * 3. Parse with MigrationParser — extract table name, columns, FK constraints.
 * 4. Persist via MigrationRepository — upsert Entity + write Snapshot.
 * 5. Return ScanResult populated with one Finding per table and per FK found.
 */
final class MigrationScanner implements ScannerInterface
{
    public function __construct(
        private readonly MigrationParser $parser,
        private readonly MigrationRepository $repository,
    ) {}

    // ── ScannerInterface ───────────────────────────────────────────────────────

    public function name(): string
    {
        return 'migration';
    }

    public function supports(SplFileInfo $file): bool
    {
        return str_contains($file->getPathname(), 'database/migrations')
            && $file->getExtension() === 'php';
    }

    public function scan(SplFileInfo $file): ScanResult
    {
        $path = (string) $file->getRealPath();

        // ── 1. Read ────────────────────────────────────────────────────────────
        $source = @file_get_contents($path);
        if ($source === false) {
            return ScanResult::withError($this->name(), $path, "Cannot read file: {$path}");
        }

        // ── 2. Hash & change detection ─────────────────────────────────────────
        $hash = FileHasher::content($source);
        $tableKey = $this->preliminaryTableKey($source);

        if ($tableKey !== null && !$this->repository->hasChanged($tableKey, $hash)) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 3. Parse ───────────────────────────────────────────────────────────
        try {
            $parsed = $this->parser->parse($source);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Parse error in {$file->getFilename()}: {$e->getMessage()}",
            );
        }

        // Skip files where no table name could be determined
        if ($parsed->tableName === 'unknown') {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 4. Persist ─────────────────────────────────────────────────────────
        try {
            $entity = $this->repository->persist($parsed, $path, $hash);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Storage error for table '{$parsed->tableName}': {$e->getMessage()}",
            );
        }

        // ── 5. Build findings ──────────────────────────────────────────────────
        $findings = [];

        // Primary finding: the table itself
        $findings[] = new Finding('table', $parsed->tableName, null, [
            'entity_ulid' => $entity->ulid,
            'operation' => $parsed->operation,
            'column_count' => count($parsed->columns),
            'fk_count' => count($parsed->foreignKeys),
        ]);

        // One finding per foreign-key constraint for traceability
        foreach ($parsed->foreignKeys as $fk) {
            $findings[] = new Finding('foreign_key', $fk->localColumn, null, [
                'references' => "{$fk->referencedTable}.{$fk->referencedColumn}",
                'on_delete' => $fk->onDelete,
                'on_update' => $fk->onUpdate,
            ]);
        }

        return new ScanResult($this->name(), $path, $findings);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Cheap pre-scan to derive the table key before full parsing, so we can
     * skip the (heavier) parse + DB hit when the file is unchanged.
     * Returns null if no table name can be found with a simple regex.
     */
    private function preliminaryTableKey(string $source): ?string
    {
        if (preg_match('/Schema\s*::\s*(?:create|table)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
            return "table.{$m[1]}";
        }

        return null;
    }
}
