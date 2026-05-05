<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Immutable DTO representing the complete parsed output of one Eloquent model file.
 */
final class ParsedModel
{
    /**
     * @param  string  $className  Short class name,  e.g. "User"
     * @param  string  $fqcn  FQCN, e.g. "App\Models\User"
     * @param  string  $tableName  Database table name
     * @param  string  $primaryKey  Primary key column (default "id")
     * @param  string[]  $fillable
     * @param  array<string, string>  $casts  column => cast type
     * @param  ParsedRelationship[]  $relationships
     */
    public function __construct(
        public readonly string $className,
        public readonly string $fqcn,
        public readonly string $tableName,
        public readonly string $primaryKey,
        public readonly array $fillable,
        public readonly array $casts,
        public readonly array $relationships,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class_name' => $this->className,
            'fqcn' => $this->fqcn,
            'table_name' => $this->tableName,
            'primary_key' => $this->primaryKey,
            'fillable' => $this->fillable,
            'casts' => $this->casts,
            'relationships' => array_map(
                static fn (ParsedRelationship $r) => $r->toArray(),
                $this->relationships,
            ),
        ];
    }
}
