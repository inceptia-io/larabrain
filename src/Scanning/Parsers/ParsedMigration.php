<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing the complete parsed output of one migration file.
 */
final class ParsedMigration
{
    /**
     * @param  string  $tableName  Extracted table name, or "unknown"
     * @param  string  $operation  "create" | "alter"
     * @param  ParsedColumn[]  $columns
     * @param  ParsedForeignKey[]  $foreignKeys
     */
    public function __construct(
        public readonly string $tableName,
        public readonly string $operation,
        public readonly array $columns,
        public readonly array $foreignKeys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table_name' => $this->tableName,
            'operation' => $this->operation,
            'columns' => array_map(static fn (ParsedColumn $c) => $c->toArray(), $this->columns),
            'foreign_keys' => array_map(static fn (ParsedForeignKey $f) => $f->toArray(), $this->foreignKeys),
        ];
    }
}
