<?php

/**
 * brain.php — Standalone CI3 scanner
 *
 * Scans a CodeIgniter 3 project and populates:
 *   app_brain_entities
 *   app_brain_relations
 *
 * Usage:
 *   php brain.php
 *
 * Configure the DB and CI3 app path below, then run from anywhere.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// CONFIG — edit these to match your CI3 project
// ─────────────────────────────────────────────────────────────────────────────

$config = [
    'db_host'    => '127.0.0.1',
    'db_port'    => '3306',
    'db_name'    => 'your_database',
    'db_user'    => 'your_user',
    'db_pass'    => 'your_password',
    'db_charset' => 'utf8mb4',

    // Absolute path to your CI3 application folder
    'app_path'   => '/path/to/your/ci3/application',
];

// ─────────────────────────────────────────────────────────────────────────────
// BOOTSTRAP
// ─────────────────────────────────────────────────────────────────────────────

$appPath = rtrim($config['app_path'], '/');

if (!is_dir($appPath)) {
    abort("app_path does not exist: {$appPath}");
}

// DB connect
try {
    $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset={$config['db_charset']}";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    abort('DB connection failed: ' . $e->getMessage());
}

log_info('Connected to database.');

// ─────────────────────────────────────────────────────────────────────────────
// SCAN
// ─────────────────────────────────────────────────────────────────────────────

$stats = ['models' => 0, 'controllers' => 0, 'methods' => 0, 'routes' => 0, 'relations' => 0];

scan_models($pdo, $appPath, $stats);
scan_controllers($pdo, $appPath, $stats);
scan_routes($pdo, $appPath, $stats);

log_info('');
log_info('Done.');
log_info("  Models      : {$stats['models']}");
log_info("  Controllers : {$stats['controllers']}");
log_info("  Methods     : {$stats['methods']}");
log_info("  Routes      : {$stats['routes']}");
log_info("  Relations   : {$stats['relations']}");

// ─────────────────────────────────────────────────────────────────────────────
// SCANNERS
// ─────────────────────────────────────────────────────────────────────────────

function scan_models(PDO $pdo, string $appPath, array &$stats): void
{
    $dir = $appPath . '/models';
    if (!is_dir($dir)) {
        log_warn("Models directory not found: {$dir}");
        return;
    }

    foreach (php_files_in($dir) as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            continue;
        }

        // Must extend CI_Model (or MY_Model / Base_Model common patterns)
        if (!preg_match('/class\s+(\w+)\s+extends\s+[\w_]*(?:CI_Model|MY_Model|Base_Model)/i', $source, $m)) {
            continue;
        }

        $className = $m[1];
        $relativePath = relative_path($file, $appPath);

        // Extract $table property if present
        $table = null;
        if (preg_match('/\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $tm)) {
            $table = $tm[1];
        }

        // Extract $fillable if present
        $fillable = [];
        if (preg_match('/\$fillable\s*=\s*\[([^\]]*)\]/s', $source, $fm)) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $fm[1], $fields);
            $fillable = $fields[1] ?? [];
        }

        // Public methods (non-magic, non-CI internals)
        $methods = extract_public_methods($source);

        $metadata = [
            'source_file'  => $relativePath,
            'source_hash'  => hash('sha256', $source),
            'table_name'   => $table,
            'fillable'     => $fillable,
            'method_count' => count($methods),
            'scanned_by'   => 'brain.php/ci3',
            'scanned_at'   => date('c'),
        ];

        $key  = 'model.' . $className;
        $name = $className;
        $desc = "CI3 Model: {$className}" . ($table ? " (table: {$table})" : '');

        upsert_entity($pdo, 'model', $key, $name, $desc, $metadata);
        log_info("  [model] {$className}" . ($table ? " → {$table}" : ''));
        $stats['models']++;
    }
}

function scan_controllers(PDO $pdo, string $appPath, array &$stats): void
{
    $dir = $appPath . '/controllers';
    if (!is_dir($dir)) {
        log_warn("Controllers directory not found: {$dir}");
        return;
    }

    foreach (php_files_in($dir) as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            continue;
        }

        // Must extend CI_Controller or common base controller names
        if (!preg_match('/class\s+(\w+)\s+extends\s+[\w_]*(?:CI_Controller|MY_Controller|Base_Controller|Admin_Controller|Public_Controller|Core_Controller)/i', $source, $m)) {
            continue;
        }

        $className    = $m[1];
        $relativePath = relative_path($file, $appPath);

        // Models loaded via $this->load->model('...')
        $loadedModels = [];
        preg_match_all('/\$this\s*->\s*load\s*->\s*model\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $lm);
        foreach ($lm[1] as $raw) {
            // CI3 model names may include path: 'subfolder/model_name'
            $loadedModels[] = basename(str_replace('/', DIRECTORY_SEPARATOR, $raw));
        }
        $loadedModels = array_unique($loadedModels);

        $methods = extract_public_methods($source);

        $metadata = [
            'source_file'   => $relativePath,
            'source_hash'   => hash('sha256', $source),
            'method_count'  => count($methods),
            'loaded_models' => $loadedModels,
            'scanned_by'    => 'brain.php/ci3',
            'scanned_at'    => date('c'),
        ];

        $key  = 'controller.' . $className;
        $name = $className;
        $desc = "CI3 Controller: {$className}";

        $controllerId = upsert_entity($pdo, 'controller', $key, $name, $desc, $metadata);
        log_info("  [controller] {$className} (" . count($methods) . " methods)");
        $stats['controllers']++;

        // ── Controller methods ─────────────────────────────────────────────────
        foreach ($methods as $method) {
            $methodSource = extract_method_source($source, $method);
            $usedModels   = [];

            // Models referenced inside this method via $this->ModelName->
            if ($methodSource !== '') {
                preg_match_all('/\$this\s*->\s*([A-Z][a-zA-Z0-9_]+)\s*->/', $methodSource, $um);
                foreach ($um[1] as $candidate) {
                    // Filter obvious CI built-ins
                    if (!in_array(strtolower($candidate), ['load', 'input', 'output', 'session', 'db', 'lang', 'config', 'uri', 'router', 'security', 'form_validation', 'email', 'cart', 'cache', 'upload', 'pagination', 'template_parser', 'unit', 'zip', 'xmlrpc', 'ftp', 'encrypt', 'migration'], true)) {
                        $usedModels[] = $candidate;
                    }
                }
                $usedModels = array_unique($usedModels);
            }

            $methodMeta = [
                'controller'   => $className,
                'method'       => $method,
                'used_models'  => $usedModels,
                'scanned_by'   => 'brain.php/ci3',
                'scanned_at'   => date('c'),
            ];

            $methodKey  = "controller_method.{$className}.{$method}";
            $methodName = "{$className}::{$method}";
            $methodDesc = "CI3 controller method: {$className}->{$method}()";

            $methodId = upsert_entity($pdo, 'controller_method', $methodKey, $methodName, $methodDesc, $methodMeta);
            $stats['methods']++;

            // Relation: method → controller  (calls)
            upsert_relation($pdo, $methodId, $controllerId, 'calls', []);
            $stats['relations']++;

            // Relations: method → models it uses
            foreach ($usedModels as $usedModel) {
                $modelKey = 'model.' . $usedModel;
                $modelId  = entity_id_by_key($pdo, $modelKey);
                if ($modelId !== null) {
                    upsert_relation($pdo, $methodId, $modelId, 'uses', ['model' => $usedModel]);
                    // Inverse for context expansion: model → method
                    upsert_relation($pdo, $modelId, $methodId, 'used_by', ['method' => $methodName]);
                    $stats['relations'] += 2;
                }
            }
        }
    }
}

function scan_routes(PDO $pdo, string $appPath, array &$stats): void
{
    $routeFile = $appPath . '/config/routes.php';
    if (!is_file($routeFile)) {
        log_warn("Routes file not found: {$routeFile}");
        return;
    }

    $source = file_get_contents($routeFile);
    if ($source === false) {
        return;
    }

    $routes = parse_ci3_routes($source);

    foreach ($routes as $route) {
        ['method' => $httpMethod, 'uri' => $uri, 'target' => $target] = $route;

        // Parse target: 'controller/method' or 'controller'
        $parts      = explode('/', $target, 2);
        $ctrlName   = ucfirst($parts[0]);
        $actionName = isset($parts[1]) ? $parts[1] : 'index';

        $key  = "route.{$httpMethod}:{$uri}";
        $name = "{$httpMethod} /{$uri}";
        $desc = "CI3 Route: [{$httpMethod}] /{$uri} → {$ctrlName}/{$actionName}";

        $metadata = [
            'http_method' => $httpMethod,
            'uri'         => $uri,
            'target'      => $target,
            'controller'  => $ctrlName,
            'action'      => $actionName,
            'scanned_by'  => 'brain.php/ci3',
            'scanned_at'  => date('c'),
        ];

        $routeId = upsert_entity($pdo, 'route', $key, $name, $desc, $metadata);
        log_info("  [route] [{$httpMethod}] /{$uri} → {$ctrlName}/{$actionName}");
        $stats['routes']++;

        // Relation: route → controller_method (calls)
        $methodKey = "controller_method.{$ctrlName}.{$actionName}";
        $methodId  = entity_id_by_key($pdo, $methodKey);
        if ($methodId !== null) {
            upsert_relation($pdo, $routeId, $methodId, 'calls', ['via' => 'route']);
            $stats['relations']++;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PARSERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Extract names of all public, non-magic methods from PHP source.
 *
 * @return string[]
 */
function extract_public_methods(string $source): array
{
    preg_match_all(
        '/^\s*public\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m',
        $source,
        $matches,
    );

    $methods = $matches[1] ?? [];

    // Remove magic methods and CI controller internals
    $skip = ['__construct', '__destruct', '__get', '__set', '__call', '__callStatic',
              '__toString', '__invoke', '__clone', '__sleep', '__wakeup',
              'index'];   // keep index — it IS a real route action

    return array_values(array_filter($methods, static fn (string $m) => !in_array($m, $skip, true)));
}

/**
 * Naively extract the source of a single method by walking braces.
 * Returns empty string if the method cannot be found.
 */
function extract_method_source(string $source, string $method): string
{
    $pos = preg_match(
        '/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/m',
        $source,
        $m,
        PREG_OFFSET_CAPTURE,
    );

    if (!$pos) {
        return '';
    }

    $start = $m[0][1];
    $open  = strpos($source, '{', $start);
    if ($open === false) {
        return '';
    }

    $depth = 0;
    $len   = strlen($source);
    for ($i = $open; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

/**
 * Parse CI3 routes.php and return an array of route definitions.
 *
 * Handles:
 *   $route['uri']        = 'controller/method';
 *   $route['uri']['GET'] = 'controller/method';
 *
 * @return array<int, array{method: string, uri: string, target: string}>
 */
function parse_ci3_routes(string $source): array
{
    $routes = [];

    // Pattern 1: $route['uri']['HTTP_METHOD'] = 'target';
    preg_match_all(
        '/\$route\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*\[\s*[\'"]([A-Z]+)[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]/',
        $source,
        $m1,
        PREG_SET_ORDER,
    );
    foreach ($m1 as $match) {
        $uri = $match[1];
        if (in_array($uri, ['default_controller', '404_override', 'translate_uri_dashes'], true)) {
            continue;
        }
        $routes[] = ['method' => strtoupper($match[2]), 'uri' => $uri, 'target' => $match[3]];
    }

    // Pattern 2: $route['uri'] = 'target';  (no HTTP method — treat as ANY)
    preg_match_all(
        '/\$route\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]/',
        $source,
        $m2,
        PREG_SET_ORDER,
    );
    foreach ($m2 as $match) {
        $uri = $match[1];
        if (in_array($uri, ['default_controller', '404_override', 'translate_uri_dashes'], true)) {
            continue;
        }
        $routes[] = ['method' => 'ANY', 'uri' => $uri, 'target' => $match[2]];
    }

    return $routes;
}

// ─────────────────────────────────────────────────────────────────────────────
// DB HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Insert or update an entity row.
 * Returns the entity's auto-increment id.
 */
function upsert_entity(
    PDO $pdo,
    string $type,
    string $key,
    string $name,
    string $description,
    array $metadata,
): int {
    $now      = date('Y-m-d H:i:s');
    $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ulid     = generate_ulid();

    // Try update first
    $stmt = $pdo->prepare(
        'UPDATE app_brain_entities
            SET name = :name, description = :desc, metadata = :meta,
                is_active = 1, updated_at = :now
          WHERE type = :type AND `key` = :key'
    );
    $stmt->execute([
        ':name' => $name,
        ':desc' => $description,
        ':meta' => $metaJson,
        ':now'  => $now,
        ':type' => $type,
        ':key'  => $key,
    ]);

    if ($stmt->rowCount() > 0) {
        return (int) $pdo->query("SELECT id FROM app_brain_entities WHERE type = " . $pdo->quote($type) . " AND `key` = " . $pdo->quote($key))->fetchColumn();
    }

    // Insert
    $ins = $pdo->prepare(
        'INSERT INTO app_brain_entities
            (ulid, type, `key`, name, description, metadata, is_active, created_at, updated_at)
         VALUES
            (:ulid, :type, :key, :name, :desc, :meta, 1, :now, :now)'
    );
    $ins->execute([
        ':ulid' => $ulid,
        ':type' => $type,
        ':key'  => $key,
        ':name' => $name,
        ':desc' => $description,
        ':meta' => $metaJson,
        ':now'  => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Insert a relation if it does not already exist.
 */
function upsert_relation(PDO $pdo, int $sourceId, int $targetId, string $relationType, array $metadata): void
{
    $now      = date('Y-m-d H:i:s');
    $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Check if exists
    $check = $pdo->prepare(
        'SELECT id FROM app_brain_relations
          WHERE source_entity_id = :src AND target_entity_id = :tgt AND relation_type = :type'
    );
    $check->execute([':src' => $sourceId, ':tgt' => $targetId, ':type' => $relationType]);

    if ($check->fetchColumn() !== false) {
        return; // already exists
    }

    $ins = $pdo->prepare(
        'INSERT INTO app_brain_relations
            (source_entity_id, target_entity_id, relation_type, metadata, created_at, updated_at)
         VALUES
            (:src, :tgt, :type, :meta, :now, :now)'
    );
    $ins->execute([
        ':src'  => $sourceId,
        ':tgt'  => $targetId,
        ':type' => $relationType,
        ':meta' => $metaJson,
        ':now'  => $now,
    ]);
}

/**
 * Look up an entity's id by its key. Returns null if not found.
 */
function entity_id_by_key(PDO $pdo, string $key): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM app_brain_entities WHERE `key` = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetchColumn();
    return $row !== false ? (int) $row : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// UTILITIES
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Recursively yield all .php files under a directory.
 *
 * @return \Generator<string>
 */
function php_files_in(string $dir): \Generator
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'php') {
            yield $file->getRealPath();
        }
    }
}

/**
 * Make an absolute path relative to the app root.
 */
function relative_path(string $absolute, string $base): string
{
    $base = rtrim($base, '/') . '/';
    return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
}

/**
 * Generate a simple 26-character ULID-like string.
 */
function generate_ulid(): string
{
    $t  = (int) (microtime(true) * 1000);
    $ts = str_pad(base_convert((string) $t, 10, 32), 10, '0', STR_PAD_LEFT);

    $rand = '';
    for ($i = 0; $i < 16; $i++) {
        $rand .= base_convert((string) random_int(0, 31), 10, 32);
    }

    return strtoupper($ts . $rand);
}

function log_info(string $msg): void
{
    echo $msg . PHP_EOL;
}

function log_warn(string $msg): void
{
    echo '[WARN] ' . $msg . PHP_EOL;
}

function abort(string $msg): never
{
    echo '[ERROR] ' . $msg . PHP_EOL;
    exit(1);
}
