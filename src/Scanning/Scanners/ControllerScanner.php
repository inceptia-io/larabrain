<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Scanners;

use Arafat\Brain\Contracts\ScannerInterface;
use Arafat\Brain\Scanning\ControllerRepository;
use Arafat\Brain\Scanning\FileHasher;
use Arafat\Brain\Scanning\Finding;
use Arafat\Brain\Scanning\Parsers\ControllerParser;
use Arafat\Brain\Scanning\Parsers\ParsedMethod;
use Arafat\Brain\Scanning\ScanResult;
use SplFileInfo;

/**
 * Scans Laravel controller files (app/Http/Controllers/**.php).
 *
 * Pipeline per file
 * ─────────────────
 * 1. Read source — return an error result if unreadable.
 * 2. Quick rejection: skip files with no public methods that look like actions.
 * 3. Hash source — skip unchanged files (compared against stored source_hash).
 * 4. Parse via ControllerParser (regex-only, no AST).
 * 5. Persist via ControllerRepository (Entity upsert, Relation sync, Snapshot).
 * 6. Return ScanResult with:
 *      • one Finding('controller_method', ...) per public action method
 *      • one Finding('validation', ...)        per method that declares rules
 *      • one Finding('model_usage', ...)       per model referenced in a method
 */
final class ControllerScanner implements ScannerInterface
{
    public function __construct(
        private readonly ControllerParser $parser,
        private readonly ControllerRepository $repository,
    ) {}

    // ── ScannerInterface ───────────────────────────────────────────────────────

    public function name(): string
    {
        return 'controller';
    }

    public function supports(SplFileInfo $file): bool
    {
        return str_contains($file->getPathname(), '/Http/Controllers/')
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

        // ── 2. Quick rejection ─────────────────────────────────────────────────
        if (!$this->looksLikeController($source)) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 3. Hash + change detection ─────────────────────────────────────────
        $hash = FileHasher::content($source);

        // Derive a preliminary controller key without full parsing
        $fqcn = $this->extractPreliminaryFqcn($source);
        $controllerKey = "controller.{$fqcn}";

        if (!$this->repository->hasChanged($controllerKey, $hash)) {
            return ScanResult::empty($this->name(), $path);
        }

        // ── 4. Parse ───────────────────────────────────────────────────────────
        try {
            $parsed = $this->parser->parse($source);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Parse error in {$path}: {$e->getMessage()}",
            );
        }

        // ── 5. Persist ─────────────────────────────────────────────────────────
        try {
            $this->repository->persist($parsed, $path, $hash);
        } catch (\Throwable $e) {
            return ScanResult::withError(
                $this->name(),
                $path,
                "Storage error for {$path}: {$e->getMessage()}",
            );
        }

        // ── 6. Build findings ──────────────────────────────────────────────────
        $findings = [];

        foreach ($parsed->methods as $method) {
            $findings[] = new Finding(
                'controller_method',
                "{$parsed->className}::{$method->name}",
                null,
                $this->methodContext($method),
            );

            if ($method->validationRules !== []) {
                $findings[] = new Finding(
                    'validation',
                    "{$parsed->className}::{$method->name}",
                    null,
                    ['rules' => $method->validationRules],
                );
            }

            foreach ($method->usedModels as $modelFqcn) {
                $findings[] = new Finding(
                    'model_usage',
                    $modelFqcn,
                    null,
                    ['in_method' => "{$parsed->className}::{$method->name}"],
                );
            }
        }

        return new ScanResult($this->name(), $path, $findings);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Cheap heuristic: the file must declare a class that has at least one
     * `public function` that is not a magic method.
     */
    private function looksLikeController(string $src): bool
    {
        return (bool) preg_match('/\bclass\s+\w+/', $src)
            && (bool) preg_match('/public\s+function\s+(?!__)\w+/', $src);
    }

    /**
     * Extract namespace + class name without full parsing, used solely to
     * compute the controller key for early change-detection.
     */
    private function extractPreliminaryFqcn(string $src): string
    {
        $namespace = '';
        $className = 'UnknownController';

        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $m)) {
            $namespace = $m[1];
        }

        if (preg_match('/\bclass\s+(\w+)/', $src, $m)) {
            $className = $m[1];
        }

        return $namespace !== '' ? "{$namespace}\\{$className}" : $className;
    }

    /** @return array<string, mixed> */
    private function methodContext(ParsedMethod $method): array
    {
        return [
            'params' => $method->params,
            'has_validation' => $method->validationRules !== [],
            'validation_fields' => array_keys($method->validationRules),
            'used_models' => $method->usedModels,
        ];
    }
}
