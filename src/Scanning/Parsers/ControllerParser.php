<?php

declare(strict_types=1);

namespace Arafat\Brain\Scanning\Parsers;

/**
 * Lightweight, regex-based parser for Laravel controller files.
 *
 * No AST or tokeniser is used. The parser operates in two logical passes:
 *
 * Pass A — File-level
 * ────────────────────
 *  • namespace + class name  →  FQCN
 *  • use-import map  →  [shortName => FQCN]  (used for model resolution)
 *
 * Pass B — Per public method (via balanced-brace walker)
 * ───────────────────────────────────────────────────────
 *  • Parameter names from the method signature
 *  • Validation rules from:
 *      →  $request->validate([...])
 *      →  $this->validate($request, [...])
 *      →  Validator::make($data, [...])
 *  • Model usage from:
 *      →  ClassName::   (static calls)
 *      →  new ClassName(   (instantiation)
 *      →  TypeHint $param   (method signature type hints)
 *      →  \Full\Class\Name::  (inline FQCNs)
 *    Cross-referenced with use-imports; only FQCNs containing \Models\ are kept.
 *
 * Magic methods (__construct, __invoke, etc.) and non-public methods are ignored.
 */
final class ControllerParser
{
    // ── Public API ─────────────────────────────────────────────────────────────

    public function parse(string $source): ParsedController
    {
        $namespace = $this->extractNamespace($source);
        $className = $this->extractClassName($source);
        $fqcn = $namespace !== '' ? "{$namespace}\\{$className}" : $className;
        $imports = $this->extractUseImports($source);
        $methods = $this->extractPublicMethods($source, $imports);

        return new ParsedController(
            className: $className,
            fqcn: $fqcn,
            methods: $methods,
        );
    }

    // ── File-level extraction ──────────────────────────────────────────────────

    private function extractNamespace(string $src): string
    {
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $m)) {
            return $m[1];
        }

        return '';
    }

    private function extractClassName(string $src): string
    {
        if (preg_match('/\bclass\s+(\w+)(?:\s+extends|\s+implements|\s*\{)/', $src, $m)) {
            return $m[1];
        }

        return 'UnknownController';
    }

    /**
     * Build a map of [shortName => FQCN] from all `use` statements.
     *
     * Handles:
     *   use App\Models\User;          → ['User' => 'App\Models\User']
     *   use App\Models\User as U;     → ['U'    => 'App\Models\User']
     *
     * @return array<string, string>
     */
    private function extractUseImports(string $src): array
    {
        $imports = [];

        if (!preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $src, $matches, PREG_SET_ORDER)) {
            return $imports;
        }

        foreach ($matches as $match) {
            $fqcn = trim($match[1], '\\');
            $alias = $match[2] ?? '';
            $shortName = $alias !== '' ? $alias : ltrim((string) strrchr($fqcn, '\\'), '\\');
            if ($shortName === '') {
                $shortName = $fqcn;
            }
            $imports[$shortName] = $fqcn;
        }

        return $imports;
    }

    // ── Method extraction ──────────────────────────────────────────────────────

    /**
     * Find all public, non-magic methods and parse each body.
     *
     * @param  array<string, string>  $imports
     * @return ParsedMethod[]
     */
    private function extractPublicMethods(string $src, array $imports): array
    {
        $methods = [];
        $methodPattern = '/public\s+function\s+(\w+)\s*(\([^)]*\))\s*(?::\s*[\w\\\\|?]+\s*)?\{/';

        if (!preg_match_all($methodPattern, $src, $sigMatches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($sigMatches[0] as $idx => $sigMatch) {
            $methodName = $sigMatches[1][$idx][0];
            $sigRaw = $sigMatches[2][$idx][0];   // "(type $param, ...)"

            // Skip magic methods (__construct, __destruct, etc.)
            if (str_starts_with($methodName, '__')) {
                continue;
            }

            $openBracePos = $sigMatch[1] + strlen($sigMatch[0]) - 1;
            $body = $this->extractMethodBody($src, $openBracePos);

            if ($body === null) {
                continue;
            }

            $params = $this->extractParamNames($sigRaw);
            $validationRules = $this->extractValidationRules($body);
            $usedModels = $this->extractUsedModels($body, $sigRaw, $imports);

            $methods[] = new ParsedMethod(
                name: $methodName,
                params: $params,
                validationRules: $validationRules,
                usedModels: $usedModels,
            );
        }

        return $methods;
    }

    /**
     * Extract `$paramName` entries from a method signature string.
     *
     * @return string[]
     */
    private function extractParamNames(string $signature): array
    {
        if (!preg_match_all('/\$(\w+)/', $signature, $m)) {
            return [];
        }

        return $m[1];
    }

    // ── Method body walker ─────────────────────────────────────────────────────

    /**
     * Walk from the opening `{` at $openBracePos and return the body content
     * (without the outer braces).  Returns null when braces are unbalanced.
     */
    private function extractMethodBody(string $src, int $openBracePos): ?string
    {
        $depth = 0;
        $len = strlen($src);
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

        return substr($src, $openBracePos + 1, $end - $openBracePos - 1);
    }

    // ── Validation rule extraction ─────────────────────────────────────────────

    /**
     * Extract validation rules declared in the method body.
     *
     * Detects three patterns:
     *   (a) $request->validate([...])
     *   (b) $this->validate($request, [...])
     *   (c) Validator::make($data, [...])
     *
     * @return array<string, string[]>
     */
    private function extractValidationRules(string $body): array
    {
        // Pattern (a): ->validate(\s*[
        if (preg_match('/->validate\s*\(\s*\[/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $arrayStart = $m[0][1] + strpos($m[0][0], '[');
            $rawArray = $this->extractBalancedBrackets($body, $arrayStart);
            if ($rawArray !== null) {
                return $this->parseRulesArray($rawArray);
            }
        }

        // Pattern (b): Validator::make($data, [
        if (preg_match('/Validator\s*::\s*make\s*\([^,]*,\s*\[/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $arrayStart = $m[0][1] + (int) strrpos($m[0][0], '[');
            $rawArray = $this->extractBalancedBrackets($body, $arrayStart);
            if ($rawArray !== null) {
                return $this->parseRulesArray($rawArray);
            }
        }

        return [];
    }

    /**
     * Walk from the opening `[` at $start and return the content within the
     * outermost brackets (without `[` and `]` themselves).
     */
    private function extractBalancedBrackets(string $src, int $start): ?string
    {
        $depth = 0;
        $len = strlen($src);
        $end = null;

        for ($i = $start; $i < $len; $i++) {
            if ($src[$i] === '[') {
                $depth++;
            } elseif ($src[$i] === ']') {
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
     * Parse a raw array literal string into a [field => [rule, ...]] map.
     *
     * Handles both string and array rule formats:
     *   'email' => 'required|email|max:255'
     *   'name'  => ['required', 'string', 'max:100']
     *
     * @return array<string, string[]>
     */
    private function parseRulesArray(string $raw): array
    {
        $rules = [];

        // Remove nested array values temporarily so they don't confuse the outer regex.
        // We process string rules first, then array rules.

        // String rules: 'field' => 'rule|rule|...'
        preg_match_all(
            '/[\'"]([^\'"]+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/',
            $raw,
            $stringMatches,
            PREG_SET_ORDER,
        );

        foreach ($stringMatches as $match) {
            $field = $match[1];
            $rules[$field] = array_filter(
                explode('|', $match[2]),
                static fn (string $r) => $r !== '',
            );
        }

        // Array rules: 'field' => ['rule', 'rule']
        if (preg_match_all(
            '/[\'"]([^\'"]+)[\'"]\s*=>\s*\[([^\]]+)\]/',
            $raw,
            $arrayMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($arrayMatches as $match) {
                $field = $match[1];
                $innerRules = [];
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[2], $ruleItems);
                foreach ($ruleItems[1] as $r) {
                    $innerRules[] = $r;
                }
                if ($innerRules !== []) {
                    $rules[$field] = $innerRules;
                }
            }
        }

        return $rules;
    }

    // ── Model usage extraction ─────────────────────────────────────────────────

    /**
     * Detect Eloquent model class names referenced in a method body or signature.
     *
     * Strategy:
     *   1. Collect candidate class names from static calls (Foo::), new expressions
     *      (new Foo), and type hints (Foo $param) in the method signature.
     *   2. Also detect inline FQCNs (\App\Models\Foo::).
     *   3. Cross-reference short names against the use-import map.
     *   4. Keep only FQCNs that contain `\Models\`.
     *
     * @param  array<string, string>  $imports  [shortName => FQCN]
     * @return string[] Unique FQCNs
     */
    private function extractUsedModels(string $body, string $signature, array $imports): array
    {
        $candidates = [];

        $searchIn = $signature."\n".$body;

        // 1a. Static calls: ClassName::method(
        if (preg_match_all('/\b([A-Z]\w*)\s*::/', $searchIn, $m)) {
            foreach ($m[1] as $name) {
                $candidates[] = $name;
            }
        }

        // 1b. new ClassName(
        if (preg_match_all('/\bnew\s+([A-Z]\w*)/', $searchIn, $m)) {
            foreach ($m[1] as $name) {
                $candidates[] = $name;
            }
        }

        // 1c. Type hints: TypeName $varname
        if (preg_match_all('/\b([A-Z]\w*)\s+\$\w+/', $searchIn, $m)) {
            foreach ($m[1] as $name) {
                $candidates[] = $name;
            }
        }

        // 2. Inline FQCNs: \App\Models\Foo:: or App\Models\Foo::
        if (preg_match_all('/(?:^|[^\\\\])\\\\?((?:\w+\\\\)+[A-Z]\w*)\s*::/', $searchIn, $m)) {
            foreach ($m[1] as $fqcn) {
                $fqcn = trim($fqcn, '\\');
                if (str_contains($fqcn, '\\Models\\')) {
                    $candidates[] = ltrim((string) strrchr($fqcn, '\\'), '\\');
                    $imports[ltrim((string) strrchr($fqcn, '\\'), '\\')] = $fqcn;
                }
            }
        }

        // 3. Resolve short names via imports and filter to \Models\ FQCNs
        $fqcns = [];

        foreach (array_unique($candidates) as $shortName) {
            if (isset($imports[$shortName])) {
                $fqcn = $imports[$shortName];
                if (str_contains($fqcn, '\\Models\\')) {
                    $fqcns[] = $fqcn;
                }
            }
        }

        return array_values(array_unique($fqcns));
    }
}
