<?php

declare(strict_types=1);

namespace Arafat\Brain\Console\Commands;

use Arafat\Brain\Scanning\ScannerManager;
use Arafat\Brain\Scanning\ScanResult;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Artisan command: php artisan app-brain:scan
 *
 * Drives the scanning pipeline:
 *   1. Resolves the base path (--path option or config default)
 *   2. Optionally limits the run to a single scanner (--scanner option)
 *   3. Iterates project files via ScannerManager
 *   4. Presents a summary table in the console output
 */
class ScanCommand extends Command
{
    protected $signature = 'app-brain:scan
                            {--path=      : Base directory to scan (defaults to base_path())}
                            {--scanner=   : Run only the named scanner (migration|model|route|controller)}
                            {--dry-run    : List discovered files without storing any results}';

    protected $description = 'Scan the project and feed results into the Brain knowledge base.';

    public function __construct(private readonly ScannerManager $manager)
    {
        parent::__construct();
    }

    // ── Entry point ───────────────────────────────────────────────────────────

    public function handle(): int
    {
        $basePath = $this->resolveBasePath();
        $scannerFilter = $this->option('scanner') ?: null;
        $isDryRun = (bool) $this->option('dry-run');

        $this->printHeader($basePath, $scannerFilter, $isDryRun);

        // Validate the scanner name before starting the pipeline.
        if ($scannerFilter !== null && !$this->validateScannerName($scannerFilter)) {
            return self::FAILURE;
        }

        // ── Run pipeline ──────────────────────────────────────────────────────
        $results = $this->runPipeline($basePath, $scannerFilter, $isDryRun);

        // ── Output results ────────────────────────────────────────────────────
        $this->printSummary($results);

        $errorCount = $results->filter(fn (ScanResult $r) => $r->hasErrors())->count();

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Pipeline ──────────────────────────────────────────────────────────────

    /**
     * @return Collection<int, ScanResult>
     */
    private function runPipeline(string $basePath, ?string $filter, bool $isDryRun): Collection
    {
        if ($isDryRun) {
            return $this->dryRun($basePath, $filter);
        }

        $files = iterator_to_array($this->manager->files($basePath), false);
        $total = count($files);

        $this->line("  Found <info>{$total}</info> PHP file(s) to process.");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $results = new Collection;

        foreach ($this->manager->files($basePath) as $file) {
            foreach ($this->manager->scanners($filter) as $scanner) {
                if ($scanner->supports($file)) {
                    $result = $scanner->scan($file);
                    $results->push($result);

                    if ($this->output->isVerbose()) {
                        $this->line(
                            "  [{$result->scanner}] <comment>{$file->getRelativePath()}/{$file->getFilename()}</comment>"
                            .' — '.($result->hasFindings() ? '<info>'.count($result->findings).' finding(s)</info>' : 'no findings'),
                        );
                    }
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $results;
    }

    /**
     * Dry-run: list files only, no scanner invocations.
     *
     * @return Collection<int, ScanResult>
     */
    private function dryRun(string $basePath, ?string $filter): Collection
    {
        $this->warn('  [dry-run] Files that would be scanned:');
        $this->newLine();

        $rows = [];

        foreach ($this->manager->files($basePath) as $file) {
            $matchedScanners = [];

            foreach ($this->manager->scanners($filter) as $scanner) {
                if ($scanner->supports($file)) {
                    $matchedScanners[] = $scanner->name();
                }
            }

            if ($matchedScanners !== []) {
                $rows[] = [
                    $file->getRelativePath().'/'.$file->getFilename(),
                    implode(', ', $matchedScanners),
                ];
            }
        }

        $this->table(['File', 'Scanner(s)'], $rows);

        return new Collection; // nothing actually ran
    }

    // ── Output helpers ────────────────────────────────────────────────────────

    private function printHeader(string $basePath, ?string $filter, bool $isDryRun): void
    {
        $this->newLine();
        $this->line('  <fg=blue;options=bold>Brain Scanner</>');
        $this->line("  Path    : <comment>{$basePath}</comment>");
        $this->line('  Scanner : <comment>'.($filter ?? 'all').'</comment>');

        if ($isDryRun) {
            $this->line('  Mode    : <fg=yellow>dry-run</>');
        }

        $this->newLine();
    }

    private function printSummary(Collection $results): void
    {
        if ($results->isEmpty()) {
            $this->line('  <comment>No results to display.</comment>');

            return;
        }

        $summary = $this->manager->summary($results);
        $rows = [];

        foreach ($summary as $scannerName => $scannerResults) {
            /** @var Collection<int, ScanResult> $scannerResults */
            $findingCount = $scannerResults->sum(fn (ScanResult $r) => count($r->findings));
            $errorCount = $scannerResults->filter(fn (ScanResult $r) => $r->hasErrors())->count();

            $rows[] = [
                $scannerName,
                $scannerResults->count(),
                $findingCount,
                $errorCount > 0 ? "<fg=red>{$errorCount}</>" : '<fg=green>0</>',
            ];
        }

        $this->table(
            ['Scanner', 'Files processed', 'Findings', 'Errors'],
            $rows,
        );
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validateScannerName(string $name): bool
    {
        try {
            $this->manager->scanners($name);

            return true;
        } catch (\Throwable $e) {
            $this->error("  {$e->getMessage()}");
            $registered = implode(', ', array_keys($this->manager->scanners()));
            $this->line("  Available scanners: <comment>{$registered}</comment>");

            return false;
        }
    }

    // ── Path resolution ───────────────────────────────────────────────────────

    private function resolveBasePath(): string
    {
        $option = $this->option('path');

        if (is_string($option) && $option !== '') {
            $path = rtrim($option, '/');

            if (!is_dir($path)) {
                $this->error("  The path \"{$path}\" does not exist or is not a directory.");
                exit(self::FAILURE);
            }

            return $path;
        }

        return base_path();
    }
}
