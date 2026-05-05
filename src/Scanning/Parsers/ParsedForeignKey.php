<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing a foreign-key constraint extracted from a migration.
 */
final class ParsedForeignKey
{
    public function __construct(
        public readonly string $localColumn,
        public readonly string $referencedTable,
        public readonly string $referencedColumn,
        public readonly string $onDelete = 'restrict',
        public readonly string $onUpdate = 'restrict',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'local_column' => $this->localColumn,
            'referenced_table' => $this->referencedTable,
            'referenced_column' => $this->referencedColumn,
            'on_delete' => $this->onDelete,
            'on_update' => $this->onUpdate,
        ];
    }
}
