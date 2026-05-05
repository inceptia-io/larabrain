<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing a single column extracted from a migration.
 */
final class ParsedColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
        public readonly bool $unique = false,
        public readonly bool $indexed = false,
        public readonly bool $primary = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'unique' => $this->unique,
            'indexed' => $this->indexed,
            'primary' => $this->primary,
        ];
    }
}
