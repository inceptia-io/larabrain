<?php

declare(strict_types=1);

namespace Arafat\Brain\Contracts;

use Arafat\Brain\Scanning\ScanResult;
use SplFileInfo;

interface ScannerInterface
{
    /**
     * A short, unique machine-readable name for this scanner.
     * Used as a filter key in the CLI (--scanner=migration).
     *
     * @return string e.g. "migration", "model", "route", "controller"
     */
    public function name(): string;

    /**
     * Return true when this scanner is able to handle the given file.
     * The manager will call scan() only when this returns true.
     */
    public function supports(SplFileInfo $file): bool;

    /**
     * Analyse the file and return a populated ScanResult.
     * Must not throw — capture errors inside the ScanResult instead.
     */
    public function scan(SplFileInfo $file): ScanResult;
}
