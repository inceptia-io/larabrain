<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

use ReflectionClass;
use ReflectionException;

/**
 * Two-pass parser for Eloquent model files.
 *
 * Pass 1 — Regex  (always runs, zero side-effects)
 * ────────────────────────────────────────────────
 * Extracts from the raw PHP source:
 *   • namespace + class name → FQCN
 *   • $table, $primaryKey, $fillable, $casts properties
 *   • Relationship method signatures + first argument
 *
 * Pass 2 — Reflection  (runs only when the class is already autoloaded)
 * ──────────────────────────────────────────────────────────────────────
 * Uses ReflectionClass + newInstanceWithoutConstructor() to read actual
 * property values.  This is more reliable for parent-class inheritance and
 * dynamic defaults but requires the class to be present in the autoloader.
 * We never force-require the file here to avoid unexpected side-effects.
 *
 * Table-name inference
 * ────────────────────
 * When no $table is declared, Laravel derives it via snake_case + plural.
 * We replicate that logic without pulling in the full Str helper so the
 * parser works even in isolation.
 */
final class ModelParser
{
    /**
     * All Eloquent relation method names we look for.
     */
    private const RELATION_TYPES = [
        'belongsTo',
        'hasMany',
        'hasOne',
        'belongsToMany',
        'hasManyThrough',
        'hasOneThrough',
        'morphTo',
        'morphMany',
        'morphOne',
        'morphToMany',
        'morphedByMany',
    ];

    // ── Public API ─────────────────────────────────────────────────────────────

    public function parse(string $source): ParsedModel
    {
        // Pass 1 — always
        $namespace = $this->extractNamespace($source);
        $className = $this->extractClassName($source);
        $fqcn = $namespace !== '' ? "{$namespace}\\{$className}" : $className;

        $tableName = $this->extractStringProperty('table', $source);
        $primaryKey = $this->extractStringProperty('primaryKey', $source) ?? 'id';
        $fillable = $this->extractArrayProperty('fillable', $source);
        $casts = $this->extractCastsProperty($source);

        // Pass 2 — reflection upgrade when class is already known to the autoloader
        [$tableName, $fillable, $casts] = $this->reflectionUpgrade(
            $fqcn, $className, $tableName, $fillable, $casts,
        );

        // Fall back to inferred table name if still null
        $tableName = $tableName ?? $this->inferTableName($className);

        $relationships = $this->extractRelationships($source, $namespace);

        return new ParsedModel(
            className: $className,
            fqcn: $fqcn,
            tableName: $tableName,
            primaryKey: $primaryKey,
            fillable: $fillable,
            casts: $casts,
            relationships: $relationships,
        );
    }

    // ── Pass 1 — Regex ─────────────────────────────────────────────────────────

    private function extractNamespace(string $src): string
    {
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $m)) {
            return $m[1];
        }

        return '';
    }

    private function extractClassName(string $src): string
    {
        // Match: class ClassName (optionally extends / implements something)
        if (preg_match('/\bclass\s+(\w+)(?:\s+extends|\s+implements|\s*\{)/', $src, $m)) {
            return $m[1];
        }

        return 'UnknownModel';
    }

    /**
     * Extract a simple protected/public string property.
     *
     * Handles single quotes, double quotes, and optional "static" keyword.
     */
    private function extractStringProperty(string $property, string $src): ?string
    {
        $pattern = '/(?:protected|public)\s+(?:static\s+)?\$'
            .preg_quote($property, '/')
            .'\s*=\s*[\'"]([^\'"]+)[\'"]/';

        if (preg_match($pattern, $src, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract a protected/public array property, returning a flat list of
     * all quoted string values found inside the brackets.
     *
     * e.g. $fillable = ['name', 'email']  →  ['name', 'email']
     *
     * @return string[]
     */
    private function extractArrayProperty(string $property, string $src): array
    {
        $pattern = '/(?:protected|public)\s+(?:static\s+)?\$'
            .preg_quote($property, '/')
            .'\s*=\s*\[([^\]]*)\]/s';

        if (!preg_match($pattern, $src, $m)) {
            return [];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $items);

        // @phpstan-ignore-next-line nullCoalesce.offset (preg_match_all always sets $items[1])
        return $items[1] ?? [];
    }

    /**
     * Extract $casts as an associative column → type map.
     *
     * Handles:  'column' => 'type'  and  'column' => Type::class
     *
     * @return array<string, string>
     */
    private function extractCastsProperty(string $src): array
    {
        $pattern = '/(?:protected|public)\s+(?:static\s+)?\$casts\s*=\s*\[([^\]]*)\]/s';

        if (!preg_match($pattern, $src, $m)) {
            return [];
        }

        $casts = [];
        // Match both 'key' => 'value'  and  'key' => Value::class
        preg_match_all(
            '/[\'"]([^\'"]+)[\'"]\s*=>\s*(?:[\'"]([^\'"]+)[\'"]|(\w+(?:\\\\\w+)*)\s*::\s*class)/',
            $m[1],
            $rows,
            PREG_SET_ORDER,
        );

        foreach ($rows as $row) {
            $key = $row[1];
            // @phpstan-ignore-next-line notIdentical.alwaysTrue (preg_match_all with PREG_SET_ORDER can yield empty strings)
            $value = $row[2] !== '' ? $row[2] : ($row[3] ?? '');
            // @phpstan-ignore-next-line notIdentical.alwaysTrue
            if ($key !== '') {
                $casts[$key] = $value;
            }
        }

        return $casts;
    }

    // ── Pass 2 — Reflection ────────────────────────────────────────────────────

    /**
     * Attempt to upgrade regex results with actual reflection values.
     *
     * We use newInstanceWithoutConstructor() so no DB connections are opened.
     *
     * @param  string[]  $fillable
     * @param  array<string,string>  $casts
     * @return array{string|null, string[], array<string,string>}
     */
    private function reflectionUpgrade(
        string $fqcn,
        string $className,
        ?string $tableName,
        array $fillable,
        array $casts,
    ): array {
        if (!class_exists($fqcn, false)) {
            return [$tableName, $fillable, $casts];
        }

        try {
            $ref = new ReflectionClass($fqcn);
            $instance = $ref->newInstanceWithoutConstructor();

            // $table
            if ($ref->hasProperty('table')) {
                $prop = $ref->getProperty('table');
                $prop->setAccessible(true);
                $reflTable = $prop->isInitialized($instance) ? $prop->getValue($instance) : null;
                if (is_string($reflTable) && $reflTable !== '') {
                    $tableName = $reflTable;
                }
            }

            // $fillable — reflection captures parent-class values too
            if ($ref->hasProperty('fillable')) {
                $prop = $ref->getProperty('fillable');
                $prop->setAccessible(true);
                $reflFillable = $prop->isInitialized($instance) ? $prop->getValue($instance) : [];
                if (is_array($reflFillable) && $reflFillable !== []) {
                    $fillable = $reflFillable;
                }
            }

            // $casts
            if ($ref->hasProperty('casts')) {
                $prop = $ref->getProperty('casts');
                $prop->setAccessible(true);
                $reflCasts = $prop->isInitialized($instance) ? $prop->getValue($instance) : [];
                if (is_array($reflCasts) && $reflCasts !== []) {
                    // Merge: parent casts first, child casts override
                    $casts = array_merge($casts, array_map('strval', $reflCasts));
                }
            }
        } catch (ReflectionException) {
            // Reflection failed — silently continue with regex results
        }

        return [$tableName, $fillable, $casts];
    }

    // ── Relationship extraction ────────────────────────────────────────────────

    /**
     * Scan all public method bodies for Eloquent relation calls.
     *
     * Strategy:
     *   1. Split source into method blocks using a balanced-brace walker.
     *   2. For each block that starts with `public function name(...)`, check
     *      whether it contains `return $this->{relationType}(...)`.
     *   3. Extract the first argument as the related class name.
     *
     * @return ParsedRelationship[]
     */
    private function extractRelationships(string $src, string $namespace): array
    {
        $relationships = [];
        $typesPattern = implode('|', self::RELATION_TYPES);

        // Match entire public method blocks (handles single-line bodies too)
        // We use a simple two-step approach:
        //   Step A: find all method signatures
        //   Step B: find the body between the following { }
        $methodPattern = '/public\s+function\s+(\w+)\s*\([^)]*\)\s*(?::\s*[\w\\\\|?]+\s*)?\{/';

        if (!preg_match_all($methodPattern, $src, $sigMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($sigMatches[0] as $i => $sigMatch) {
            $methodName = $sigMatches[1][$i][0];
            $bodyStart = $sigMatch[1] + strlen($sigMatch[0]) - 1; // position of the opening {

            $body = $this->extractMethodBody($src, $bodyStart);

            if ($body === null) {
                continue;
            }

            // Check for a relation call inside the body
            $relPattern = '/\$this\s*->\s*('.$typesPattern.')\s*\(\s*([^)]+)/';

            if (!preg_match($relPattern, $body, $relMatch)) {
                continue;
            }

            $relType = $relMatch[1];
            $argsFragment = $relMatch[2];

            $relatedClass = $this->extractRelatedClass($argsFragment, $namespace);
            $foreignKey = $this->extractSecondStringArg($argsFragment);

            $relationships[] = new ParsedRelationship(
                method: $methodName,
                type: $relType,
                relatedClass: $relatedClass,
                foreignKey: $foreignKey,
            );
        }

        return $relationships;
    }

    /**
     * Walk the source from the opening `{` and find the matching `}`,
     * handling nested braces.  Returns the body content (without braces)
     * or null if the braces are unbalanced.
     */
    private function extractMethodBody(string $src, int $openBracePos): ?string
    {
        $depth = 0;
        $len = strlen($src);
        $start = $openBracePos;
        $end = null;

        for ($i = $openBracePos; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null) {
            return null;
        }

        return substr($src, $start + 1, $end - $start - 1);
    }

    /**
     * Extract the related class name from the first argument of a relation call.
     *
     * Handles:
     *   RelatedClass::class
     *   'RelatedClass'
     *   "App\\Models\\RelatedClass"
     */
    private function extractRelatedClass(string $argsFragment, string $namespace): string
    {
        // ::class reference — most common
        if (preg_match('/([\w\\\\]+)\s*::\s*class/', $argsFragment, $m)) {
            $raw = trim($m[1]);
            // If already FQCN (starts with \) strip leading slash
            if (str_starts_with($raw, '\\')) {
                return ltrim($raw, '\\');
            }
            // If no namespace separator, prepend the current namespace
            if (!str_contains($raw, '\\') && $namespace !== '') {
                return $namespace.'\\'.$raw;
            }

            return $raw;
        }

        // String literal
        if (preg_match('/[\'"]([^\'"]+)[\'"]/', $argsFragment, $m)) {
            return $m[1];
        }

        return 'unknown';
    }

    /**
     * Attempt to extract the second quoted string argument (often the foreign key).
     */
    private function extractSecondStringArg(string $argsFragment): ?string
    {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argsFragment, $m);

        return $m[1][1] ?? null;
    }

    // ── Table name inference ───────────────────────────────────────────────────

    /**
     * Replicate Laravel's default table-name derivation:
     *   User          → users
     *   OrderItem     → order_items
     *   ProductPhoto  → product_photos
     */
    private function inferTableName(string $className): string
    {
        // CamelCase → snake_case
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $className));

        // Naive pluralisation (covers the vast majority of English words)
        if (str_ends_with($snake, 'y') && !preg_match('/[aeiou]y$/', $snake)) {
            // e.g. category → categories
            return substr($snake, 0, -1).'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $snake)) {
            // e.g. status → statuses
            return $snake.'es';
        }

        return $snake.'s';
    }
}
