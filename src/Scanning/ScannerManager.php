<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

use Arafat\Brain\Contracts\ScannerInterface;
use Arafat\Brain\Exceptions\BrainException;
use Illuminate\Support\Collection;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo as SymfonySplFileInfo;

/**
 * Orchestrates the scanning pipeline.
 *
 * Responsibilities:
 *  - Maintain the registry of concrete scanners
 *  - Discover files under a given base path (honouring config exclusions)
 *  - Feed each file to every scanner that supports it
 *  - Aggregate and return all results
 */
final class ScannerManager
{
    /** @var array<string, ScannerInterface> Keyed by scanner name. */
    private array $scanners = [];

    public function __construct(private readonly array $config = []) {}

    // ── Registry ──────────────────────────────────────────────────────────────

    /**
     * Register a scanner.  Duplicate names are overwritten (last-in wins).
     */
    public function register(ScannerInterface $scanner): static
    {
        $this->scanners[$scanner->name()] = $scanner;

        return $this;
    }

    /**
     * Return all registered scanners, optionally filtered to a single name.
     *
     * @return array<string, ScannerInterface>
     */
    public function scanners(?string $filter = null): array
    {
        if ($filter === null) {
            return $this->scanners;
        }

        if (!isset($this->scanners[$filter])) {
            throw new BrainException("No scanner registered with name \"{$filter}\".");
        }

        return [$filter => $this->scanners[$filter]];
    }

    // ── Pipeline ──────────────────────────────────────────────────────────────

    /**
     * Discover all files under $basePath and run each applicable scanner.
     *
     * @param  string  $basePath  Root directory to walk
     * @param  string|null  $only  When set, run only the named scanner
     * @return Collection<int, ScanResult>
     */
    public function run(string $basePath, ?string $only = null): Collection
    {
        $activeScanners = $this->scanners($only);
        $results = new Collection;

        foreach ($this->files($basePath) as $file) {
            foreach ($activeScanners as $scanner) {
                if (!$scanner->supports($file)) {
                    continue;
                }

                $results->push($scanner->scan($file));
            }
        }

        return $results;
    }

    /**
     * Return a summary grouped by scanner name.
     *
     * @param  Collection<int, ScanResult>  $results
     * @return Collection<string, Collection<int, ScanResult>>
     */
    public function summary(Collection $results): Collection
    {
        /** @var Collection<string, Collection<int, ScanResult>> $grouped */
        $grouped = $results->groupBy(fn (ScanResult $r) => $r->scanner);

        return $grouped;
    }

    // ── File discovery ────────────────────────────────────────────────────────

    /**
     * Yield SplFileInfo objects for every PHP file under $basePath,
     * respecting the excluded paths defined in config.
     *
     * @return iterable<SymfonySplFileInfo>
     */
    public function files(string $basePath): iterable
    {
        $excluded = $this->config['scan']['exclude'] ?? [
            'vendor',
            'node_modules',
            'storage',
            'bootstrap/cache',
        ];

        $finder = Finder::create()
            ->files()
            ->in($basePath)
            ->name('*.php')
            ->notPath($excluded)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->sortByName();

        return $finder;
    }
}
