<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning;

/**
 * Immutable value object representing a single item discovered by a scanner.
 *
 * Scanners should subclass this (or use it as-is) to attach richer domain
 * context when business logic is implemented.
 */
final class Finding
{
    /**
     * @param  string  $type  Category of the finding, e.g. "class_name", "table"
     * @param  string  $value  The extracted value
     * @param  int|null  $line  Source line number if applicable
     * @param  array<string,mixed>  $context  Any additional key/value pairs
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly ?int $line = null,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'line' => $this->line,
            'context' => $this->context,
        ];
    }
}
