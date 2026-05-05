<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Scanners;

use Arafat\Brain\Contracts\ScannerInterface;
use Arafat\Brain\Scanning\FileHasher;
use Arafat\Brain\Scanning\Finding;
use Arafat\Brain\Scanning\ModelRepository;
use Arafat\Brain\Scanning\Parsers\ModelParser;
use Arafat\Brain\Scanning\Parsers\ParsedRelationship;
use Arafat\Brain\Scanning\ScanResult;
use SplFileInfo;

/**
 * Scans Eloquent model files and persists extracted knowledge as Entity records
 * (type="model") plus Relation records (model-to-model edges) in the Brain tables.
 *
 * Pipeline per file
 * ─────────────────
 * 1. Read source — return an error result if unreadable.
 * 2. Reject non-Eloquent files early (must extend Model somewhere in source).
 * 3. Hash source — skip unchanged files.
 * 4. Parse via ModelParser (regex pass + optional reflection upgrade).
 * 5. Persist via ModelRepository (Entity upsert, Relation sync, Snapshot).
 * 6. Return ScanResult with one Finding per model and one per relationship.
 */
final class ModelScanner implements ScannerInterface
{
    public function __construct(
        private readonly ModelParser $parser,
        private readonly ModelRepository $repository,
    ) {}

    // ── ScannerInterface ───────────────────────────────────────────────────────

    public function name(): string
    {
        return 'model';
    }

    public function supports(SplFileInfo $file): bool
    {
        return str_contains($file->getPathname(), '/Models/')
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

        // ── 2. Reject non-Eloquent files ───────────────────────────────────────
        if (!$this->looksLikeEloquentModel($source)) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 3. Hash & change detection ─────────────────────────────────────────
        $hash = FileHasher::content($source);
        $modelKey = $this->preliminaryModelKey($source);

        if ($modelKey !== null && !$this->repository->hasChanged($modelKey, $hash)) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 4. Parse ───────────────────────────────────────────────────────────
        try {
            $parsed = $this->parser->parse($source);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Parse error in {$file->getFilename()}: {$e->getMessage()}",
            );
        }

        if ($parsed->className === 'UnknownModel') {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 5. Persist ─────────────────────────────────────────────────────────
        try {
            $entity = $this->repository->persist($parsed, $path, $hash);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Storage error for model '{$parsed->fqcn}': {$e->getMessage()}",
            );
        }

        // ── 6. Build findings ──────────────────────────────────────────────────
        $findings = [];

        $findings[] = new Finding('model', $parsed->fqcn, null, [
            'entity_ulid' => $entity->ulid,
            'table_name' => $parsed->tableName,
            'fillable_count' => count($parsed->fillable),
            'casts_count' => count($parsed->casts),
            'relationship_count' => count($parsed->relationships),
        ]);

        foreach ($parsed->relationships as $rel) {
            /** @var ParsedRelationship $rel */
            $findings[] = new Finding('relationship', $rel->method, null, [
                'type' => $rel->type,
                'related_class' => $rel->relatedClass,
                'foreign_key' => $rel->foreignKey,
            ]);
        }

        return new ScanResult($this->name(), $path, $findings);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Fast check: does the source file look like an Eloquent model?
     * Accepts classes that extend Model (with or without a namespace prefix).
     */
    private function looksLikeEloquentModel(string $source): bool
    {
        return (bool) preg_match('/\bclass\s+\w+\s+extends\s+(?:[\w\\\\]*\s*)?Model\b/', $source);
    }

    /**
     * Cheap pre-scan to derive the model key before full parsing,
     * used for the early hash-match skip.
     */
    private function preliminaryModelKey(string $source): ?string
    {
        $namespace = '';
        $className = null;

        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $m)) {
            $namespace = $m[1];
        }
        if (preg_match('/\bclass\s+(\w+)\s/', $source, $m)) {
            $className = $m[1];
        }

        if ($className === null) {
            return null;
        }

        $fqcn = $namespace !== '' ? "{$namespace}\\{$className}" : $className;

        return "model.{$fqcn}";
    }
}
