<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Regex-based parser for Laravel migration files.
 *
 * Strategy
 * ────────
 * 1. Collapse all whitespace runs into a single space so that multi-line
 *    chained calls become one logical string.
 * 2. Split on semicolons to get individual statements.
 * 3. For each statement that contains a $table-> call, route it to the
 *    appropriate extraction method.
 *
 * No external AST or tokeniser libraries are used.
 */
final class MigrationParser
{
    // ── Blueprint method → canonical SQL type ────────────────────────────────

    private const COLUMN_TYPE_MAP = [
        // Auto-increment PKs
        'id' => 'bigint',
        'bigIncrements' => 'bigint',
        'increments' => 'int',
        'tinyIncrements' => 'tinyint',
        'smallIncrements' => 'smallint',
        'mediumIncrements' => 'mediumint',
        // Integers
        'bigInteger' => 'bigint',
        'integer' => 'int',
        'tinyInteger' => 'tinyint',
        'smallInteger' => 'smallint',
        'mediumInteger' => 'mediumint',
        'unsignedBigInteger' => 'bigint unsigned',
        'unsignedInteger' => 'int unsigned',
        'unsignedTinyInteger' => 'tinyint unsigned',
        'unsignedSmallInteger' => 'smallint unsigned',
        'unsignedMediumInteger' => 'mediumint unsigned',
        // Decimals
        'float' => 'float',
        'unsignedFloat' => 'float unsigned',
        'double' => 'double',
        'unsignedDouble' => 'double unsigned',
        'decimal' => 'decimal',
        'unsignedDecimal' => 'decimal unsigned',
        // Strings
        'string' => 'varchar',
        'char' => 'char',
        'text' => 'text',
        'tinyText' => 'tinytext',
        'mediumText' => 'mediumtext',
        'longText' => 'longtext',
        // Booleans
        'boolean' => 'boolean',
        // Dates & times
        'date' => 'date',
        'dateTime' => 'datetime',
        'dateTimeTz' => 'datetime',
        'time' => 'time',
        'timeTz' => 'time',
        'timestamp' => 'timestamp',
        'timestampTz' => 'timestamp',
        'year' => 'year',
        // Binary / blobs
        'binary' => 'blob',
        // JSON
        'json' => 'json',
        'jsonb' => 'json',
        // UUIDs / ULIDs
        'uuid' => 'uuid',
        'ulid' => 'ulid',
        // Network
        'ipAddress' => 'varchar',
        'macAddress' => 'varchar',
        // Enums / sets
        'enum' => 'enum',
        'set' => 'set',
        // FK shorthand columns (the constraint is handled separately)
        'foreignId' => 'bigint unsigned',
        'foreignUuid' => 'uuid',
        'foreignUlid' => 'ulid',
    ];

    /**
     * Methods that declare index/constraint operations but are not columns.
     * Statements containing only these methods are skipped during column parsing.
     */
    private const NON_COLUMN_METHODS = [
        'primary', 'unique', 'index', 'spatialIndex', 'fullText',
        'dropColumn', 'renameColumn', 'dropIndex', 'dropUnique',
        'dropForeign', 'dropPrimary', 'dropMorphs', 'dropRememberToken',
        'dropTimestamps', 'dropSoftDeletes', 'dropFullText',
    ];

    // ── Public API ─────────────────────────────────────────────────────────────

    public function parse(string $source): ParsedMigration
    {
        $normalized = $this->normalize($source);
        $operation = $this->extractOperation($normalized);
        $tableName = $this->extractTableName($normalized);
        $columns = $this->extractColumns($normalized);
        $foreignKeys = $this->extractForeignKeys($normalized);

        return new ParsedMigration(
            tableName: $tableName,
            operation: $operation,
            columns: $columns,
            foreignKeys: $foreignKeys,
        );
    }

    // ── Normalization ──────────────────────────────────────────────────────────

    /**
     * Strip comments and collapse all whitespace into a single space so that
     * multi-line method chains become a single searchable string.
     */
    private function normalize(string $source): string
    {
        // Remove single-line comments
        $source = preg_replace('/\/\/[^\n]*/', ' ', $source) ?? $source;
        // Remove block comments
        $source = preg_replace('/\/\*.*?\*\//s', ' ', $source) ?? $source;

        // Collapse whitespace
        return preg_replace('/\s+/', ' ', $source) ?? $source;
    }

    // ── Table / operation extraction ───────────────────────────────────────────

    private function extractOperation(string $src): string
    {
        return preg_match('/Schema\s*::\s*create\s*\(/', $src) ? 'create' : 'alter';
    }

    private function extractTableName(string $src): string
    {
        if (preg_match('/Schema\s*::\s*(?:create|table)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
            return $m[1];
        }

        return 'unknown';
    }

    // ── Column extraction ──────────────────────────────────────────────────────

    /**
     * Split the normalized source into semicolon-delimited statements, then
     * parse each statement that contains a $table-> call.
     *
     * @return ParsedColumn[]
     */
    private function extractColumns(string $src): array
    {
        $columns = [];
        $statements = explode(';', $src);

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);

            if (!$this->isBlueprintStatement($stmt)) {
                continue;
            }

            $this->parseColumnStatement($stmt, $columns);
        }

        return $columns;
    }

    private function isBlueprintStatement(string $stmt): bool
    {
        return (bool) preg_match('/\$\w+\s*->\s*\w+/', $stmt);
    }

    /**
     * @param  ParsedColumn[]  $columns
     */
    private function parseColumnStatement(string $stmt, array &$columns): void
    {
        // Extract the first method call: $table->methodName(args)
        if (!preg_match('/\$\w+\s*->\s*(\w+)\s*\(([^)]*)\)/', $stmt, $m)) {
            return;
        }

        $method = $m[1];
        $args = $m[2];

        // Skip non-column methods
        if (in_array($method, self::NON_COLUMN_METHODS, true)) {
            return;
        }

        // ── Macro expansions ───────────────────────────────────────────────────

        // timestamps() / timestampsTz() → created_at + updated_at
        if (in_array($method, ['timestamps', 'timestampsTz', 'nullableTimestamps'], true)) {
            $nullable = str_contains($method, 'nullable');
            $columns[] = new ParsedColumn('created_at', 'timestamp', $nullable);
            $columns[] = new ParsedColumn('updated_at', 'timestamp', true);

            return;
        }

        // softDeletes() / softDeletesTz() → deleted_at
        if (in_array($method, ['softDeletes', 'softDeletesTz'], true)) {
            $columns[] = new ParsedColumn('deleted_at', 'timestamp', true);

            return;
        }

        // rememberToken()
        if ($method === 'rememberToken') {
            $columns[] = new ParsedColumn('remember_token', 'varchar', true);

            return;
        }

        // morphs('name') → name_type varchar + name_id bigint unsigned
        if (in_array($method, ['morphs', 'nullableMorphs', 'ulidMorphs', 'uuidMorphs'], true)) {
            $name = $this->firstStringArg($args);
            $nullable = str_contains($method, 'nullable') || str_contains($stmt, '->nullable()');
            if ($name !== null) {
                $columns[] = new ParsedColumn("{$name}_type", 'varchar', $nullable);
                $columns[] = new ParsedColumn("{$name}_id", 'bigint unsigned', $nullable);
            }

            return;
        }

        // id() with no column name argument → primary 'id' column
        if ($method === 'id' && trim($args) === '') {
            $columns[] = new ParsedColumn('id', 'bigint', false, null, false, false, true);

            return;
        }

        // ── Regular column ─────────────────────────────────────────────────────

        if (!isset(self::COLUMN_TYPE_MAP[$method])) {
            return;
        }

        $columnName = $this->firstStringArg($args);
        if ($columnName === null) {
            return;
        }

        $type = self::COLUMN_TYPE_MAP[$method];
        $primary = in_array($method, [
            'id', 'bigIncrements', 'increments',
            'tinyIncrements', 'smallIncrements', 'mediumIncrements',
        ], true);

        $nullable = (bool) preg_match('/->nullable\s*\(\s*\)/', $stmt);
        $unique = (bool) preg_match('/->unique\s*\(\s*\)/', $stmt);
        $indexed = (bool) preg_match('/->index\s*\(\s*\)/', $stmt);
        $default = $this->extractDefault($stmt);

        $columns[] = new ParsedColumn(
            name: $columnName,
            type: $type,
            nullable: $nullable,
            default: $default,
            unique: $unique,
            indexed: $indexed,
            primary: $primary,
        );
    }

    // ── Foreign-key extraction ─────────────────────────────────────────────────

    /**
     * @return ParsedForeignKey[]
     */
    private function extractForeignKeys(string $src): array
    {
        $fks = [];

        $this->extractExplicitForeignKeys($src, $fks);
        $this->extractImplicitForeignKeys($src, $fks);

        return $fks;
    }

    /**
     * Handle: ->foreign('col')->references('ref')->on('table')
     *
     * @param  ParsedForeignKey[]  $fks
     */
    private function extractExplicitForeignKeys(string $src, array &$fks): void
    {
        $pattern = '/->foreign\s*\(\s*[\'"](\w+)[\'"]\s*\).*?->references\s*\(\s*[\'"](\w+)[\'"]\s*\).*?->on\s*\(\s*[\'"](\w+)[\'"]\s*\)/';

        if (!preg_match_all($pattern, $src, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $chain = $m[0];
            $onDelete = $this->extractActionKeyword('onDelete', $chain)
                ?? $this->extractCascadeShorthand('Delete', $chain)
                ?? 'restrict';
            $onUpdate = $this->extractActionKeyword('onUpdate', $chain)
                ?? $this->extractCascadeShorthand('Update', $chain)
                ?? 'restrict';

            $fks[] = new ParsedForeignKey(
                localColumn: $m[1],
                referencedTable: $m[3],
                referencedColumn: $m[2],
                onDelete: $onDelete,
                onUpdate: $onUpdate,
            );
        }
    }

    /**
     * Handle: ->foreignId('col_id')->constrained('table') or ->constrained()
     *
     * @param  ParsedForeignKey[]  $fks
     */
    private function extractImplicitForeignKeys(string $src, array &$fks): void
    {
        // Match the entire statement for each foreignId/foreignUuid/foreignUlid call
        $pattern = '/\$\w+\s*->\s*(?:foreignId|foreignUuid|foreignUlid)\s*\(\s*[\'"](\w+)[\'"]\s*\)([^;]*)/';

        if (!preg_match_all($pattern, $src, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $colName = $m[1];
            $chain = $m[2];

            // Must have ->constrained(...) to be a real FK
            if (!str_contains($chain, 'constrained')) {
                continue;
            }

            // Explicit table name in constrained('table')
            if (preg_match('/->constrained\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $chain, $cm)) {
                $refTable = $cm[1];
            } else {
                // Infer from column name: strip _id / _uuid / _ulid suffix
                $refTable = (string) preg_replace('/_(id|uuid|ulid)$/', '', $colName);
                // Naive pluralisation (sufficient for table names that follow convention)
                if (!str_ends_with($refTable, 's')) {
                    $refTable .= 's';
                }
            }

            // Explicit reference column override
            if (preg_match('/->references\s*\(\s*[\'"](\w+)[\'"]\s*\)/', $chain, $rc)) {
                $refCol = $rc[1];
            } else {
                $refCol = 'id';
            }

            $onDelete = $this->extractActionKeyword('onDelete', $chain)
                ?? $this->extractCascadeShorthand('Delete', $chain)
                ?? 'restrict';
            $onUpdate = $this->extractActionKeyword('onUpdate', $chain)
                ?? $this->extractCascadeShorthand('Update', $chain)
                ?? 'restrict';

            $fks[] = new ParsedForeignKey(
                localColumn: $colName,
                referencedTable: $refTable,
                referencedColumn: $refCol,
                onDelete: $onDelete,
                onUpdate: $onUpdate,
            );
        }
    }

    // ── Utility helpers ────────────────────────────────────────────────────────

    private function firstStringArg(string $args): ?string
    {
        if (preg_match('/[\'"]([^\'"]+)[\'"]/', $args, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractDefault(string $stmt): mixed
    {
        if (!preg_match('/->default\s*\(([^)]+)\)/', $stmt, $m)) {
            return null;
        }

        $raw = trim($m[1]);

        // Boolean / null literals
        if (strtolower($raw) === 'true') {
            return true;
        }
        if (strtolower($raw) === 'false') {
            return false;
        }
        if (strtolower($raw) === 'null') {
            return null;
        }

        // Numeric
        if (is_numeric($raw)) {
            return $raw + 0;
        }

        // String literal — strip surrounding quotes
        return trim($raw, "'\"");
    }

    private function extractActionKeyword(string $event, string $chain): ?string
    {
        if (preg_match('/->'.preg_quote($event, '/').'\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $chain, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Handle Laravel cascade shorthand methods:
     *  ->cascadeOnDelete()  →  "cascade"
     *  ->nullOnDelete()     →  "set null"
     *  ->restrictOnDelete() →  "restrict"
     */
    private function extractCascadeShorthand(string $event, string $chain): ?string
    {
        if (preg_match('/->cascade'.$event.'\s*\(\s*\)/', $chain)) {
            return 'cascade';
        }
        if (preg_match('/->null'.$event.'\s*\(\s*\)/', $chain)) {
            return 'set null';
        }
        if (preg_match('/->restrict'.$event.'\s*\(\s*\)/', $chain)) {
            return 'restrict';
        }

        return null;
    }
}
