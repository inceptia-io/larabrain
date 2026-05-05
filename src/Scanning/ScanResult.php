<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

/**
 * Immutable value object that holds the outcome of one scanner run on one file.
 */
final class ScanResult
{
    /**
     * @param  string  $scanner  Scanner name, e.g. "migration"
     * @param  string  $file  Absolute file path that was scanned
     * @param  array<int, Finding>  $findings  Zero or more structured findings
     * @param  array<int, string>  $errors  Non-fatal error messages encountered
     */
    public function __construct(
        public readonly string $scanner,
        public readonly string $file,
        public readonly array $findings = [],
        public readonly array $errors = [],
    ) {}

    // ── Factory helpers ───────────────────────────────────────────────────────

    /**
     * Build an empty result (scanner ran, nothing to report).
     */
    public static function empty(string $scanner, string $file): self
    {
        return new self($scanner, $file);
    }

    /**
     * Build a result that carries a non-fatal error.
     */
    public static function withError(string $scanner, string $file, string $error): self
    {
        return new self($scanner, $file, [], [$error]);
    }

    // ── Derived state ─────────────────────────────────────────────────────────

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Merge another ScanResult's findings and errors into this one,
     * producing a new immutable instance.
     *
     * Both results must belong to the same scanner + file combination.
     */
    public function merge(self $other): self
    {
        return new self(
            $this->scanner,
            $this->file,
            array_merge($this->findings, $other->findings),
            array_merge($this->errors, $other->errors),
        );
    }

    /**
     * Return a plain-array representation suitable for logging or serialisation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scanner' => $this->scanner,
            'file' => $this->file,
            'findings' => array_map(
                static fn (Finding $f) => $f->toArray(),
                $this->findings,
            ),
            'errors' => $this->errors,
        ];
    }
}
