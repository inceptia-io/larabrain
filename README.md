# LaraBrain

Give your PHP application self-awareness. LaraBrain scans your codebase (models, migrations, routes, controllers), builds a structured context graph, and uses an AI provider to answer natural-language questions about how your application works — with clickable links to relevant pages.

Supports **Laravel 10/11/12/13** and **CodeIgniter 4**.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/inceptia-io/larabrain.svg)](https://packagist.org/packages/inceptia-io/larabrain)
[![PHP Version](https://img.shields.io/packagist/php-v/inceptia-io/larabrain.svg)](https://packagist.org/packages/inceptia-io/larabrain)
[![License](https://img.shields.io/github/license/inceptia-io/larabrain.svg)](LICENSE)

---

## Table of Contents

- [Requirements](#requirements)
- [Installation — Laravel](#installation--laravel)
- [Installation — CodeIgniter 4](#installation--codeigniter-4)
- [Environment Variables](#environment-variables)
- [AI Providers](#ai-providers)
- [Laravel Usage](#laravel-usage)
- [CodeIgniter 4 Usage](#codeigniter-4-usage)
- [Web Interface](#web-interface)
- [Intent Detection](#intent-detection)
- [Configuration Reference](#configuration-reference)
- [License](#license)

---

## Requirements

- PHP **8.1** or higher
- **Laravel** 10, 11, 12, or 13 — OR — **CodeIgniter 4.4+**
- A Composer-managed project

---

## Installation — Laravel

### 1. Install via Composer

```bash
composer require inceptia-io/larabrain
```

Laravel auto-discovers the service provider. No manual registration needed.

### 2. Publish the config

```bash
php artisan vendor:publish --tag=brain-config
```

This creates `config/app-brain.php` in your application.

### 3. Publish and run migrations

```bash
php artisan vendor:publish --tag=brain-migrations
php artisan migrate
```

### 4. Set environment variables

```env
BRAIN_AI_DRIVER=openai
OPENAI_API_KEY=sk-...
```

### 5. Scan your codebase

```bash
php artisan app-brain:scan
```

### 6. Ask a question

```bash
php artisan app-brain:ask "How does the user registration flow work?"
```

---

## Installation — CodeIgniter 4

### 1. Install via Composer

```bash
composer require inceptia-io/larabrain
```

`guzzlehttp/guzzle` is bundled as a dependency and installs automatically.

### 2. Register the routes

In `app/Config/Routes.php`:

```php
require ROOTPATH . 'vendor/inceptia-io/larabrain/routes/ci4-brain.php';
```

This registers:
- `GET  /brain`      → Standalone chat page
- `POST /brain/ask`  → JSON API endpoint

### 3. Run the database migrations

Create the two required tables. Run this SQL against your database (or adapt to a CI4 migration):

```sql
CREATE TABLE app_brain_entities (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ulid       VARCHAR(26)  NOT NULL UNIQUE,
    type       VARCHAR(50)  NOT NULL,
    `key`      VARCHAR(500) NOT NULL,
    name       VARCHAR(255) NOT NULL,
    description TEXT         NULL,
    metadata   JSON         NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NULL,
    updated_at TIMESTAMP    NULL,
    deleted_at TIMESTAMP    NULL,
    INDEX idx_type (type),
    INDEX idx_key (`key`(191))
);

CREATE TABLE app_brain_relations (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_entity_id BIGINT UNSIGNED NOT NULL,
    target_entity_id BIGINT UNSIGNED NOT NULL,
    relation_type    VARCHAR(100)    NOT NULL,
    metadata         JSON            NULL,
    created_at       TIMESTAMP       NULL,
    updated_at       TIMESTAMP       NULL,
    INDEX idx_source (source_entity_id),
    INDEX idx_target (target_entity_id)
);
```

> **Note:** The Laravel migration files (published via `vendor:publish --tag=brain-migrations`) contain the canonical schema and can be used as a reference.

### 4. Set environment variables

In your `.env`:

```env
BRAIN_AI_DRIVER=openai
OPENAI_API_KEY=sk-...
```

### 5. (Optional) Publish the config

Copy `vendor/inceptia-io/larabrain/src/CI4/Config/AppBrain.php` to `app/Config/AppBrain.php` for full control over all settings.

### 6. Seed the context index

CI4 does not have an Artisan scanner yet. Seed the `app_brain_entities` and `app_brain_relations` tables directly — either manually or via a CI4 Seeder — using your application's models, routes, and controllers as the source.

---

## Environment Variables

All variables are optional unless marked **required**. They work identically in both Laravel and CodeIgniter 4.

### Package Toggle

| Variable | Default | Description |
|---|---|---|
| `BRAIN_ENABLED` | `true` | Globally enable or disable the package |

### AI Driver

| Variable | Default | Description |
|---|---|---|
| `BRAIN_AI_DRIVER` | `openai` | **Required.** Active provider: `openai`, `gemini`, `anthropic`, `deepseek` |

### API Keys

| Variable | Required when | Description |
|---|---|---|
| `OPENAI_API_KEY` | `BRAIN_AI_DRIVER=openai` | OpenAI API key (`sk-...`) |
| `GEMINI_API_KEY` | `BRAIN_AI_DRIVER=gemini` | Google Gemini API key |
| `ANTHROPIC_API_KEY` | `BRAIN_AI_DRIVER=anthropic` | Anthropic API key |
| `DEEPSEEK_API_KEY` | `BRAIN_AI_DRIVER=deepseek` | DeepSeek API key |

### Model & Token Settings

| Variable | Default | Description |
|---|---|---|
| `BRAIN_OPENAI_MODEL` | `gpt-4o` | OpenAI model name |
| `BRAIN_OPENAI_MAX_TOKENS` | `2048` | Max tokens for OpenAI response |
| `BRAIN_OPENAI_TIMEOUT` | `30` | HTTP timeout in seconds |
| `BRAIN_OPENAI_BASE_URL` | `https://api.openai.com` | Override for self-hosted / proxy endpoints |
| `BRAIN_GEMINI_MODEL` | `gemini-1.5-pro` | Gemini model name |
| `BRAIN_GEMINI_MAX_TOKENS` | `2048` | Max tokens for Gemini response |
| `BRAIN_ANTHROPIC_MODEL` | `claude-3-5-sonnet-20241022` | Anthropic model name |
| `BRAIN_ANTHROPIC_MAX_TOKENS` | `2048` | Max tokens for Anthropic response |
| `BRAIN_DEEPSEEK_MODEL` | `deepseek-chat` | DeepSeek model name |
| `BRAIN_DEEPSEEK_MAX_TOKENS` | `2048` | Max tokens for DeepSeek response |
| `BRAIN_DEEPSEEK_BASE_URL` | `https://api.deepseek.com` | Override for DeepSeek endpoint |

### Caching

| Variable | Default | Description |
|---|---|---|
| `BRAIN_CACHE_ENABLED` | `true` | Enable/disable the cache layer entirely |
| `BRAIN_ASK_CACHE_CONTEXT` | `false` | Cache resolved context per keyword (avoids re-querying DB on repeated questions) |
| `BRAIN_CACHE_CONTEXT_TTL` | `3600` | TTL in seconds for cached context results |
| `BRAIN_CACHE_TTL` | `3600` | Default TTL for all other cached items |
| `BRAIN_CACHE_PREFIX` | `brain` | Cache key prefix to avoid conflicts with other packages |

### Logging

| Variable | Default | Description |
|---|---|---|
| `BRAIN_ASK_LOG_QUERIES` | `false` | Log every `ask()` call at DEBUG level with metadata |
| `BRAIN_LOG_CHANNEL` | `null` | Laravel: log channel name. CI4: log level (`debug`, `info`). Null disables query logging |

### Web Interface (Laravel only)

| Variable | Default | Description |
|---|---|---|
| `BRAIN_UI_ENABLED` | `true` | Enable/disable the web chat interface |
| `BRAIN_UI_PREFIX` | `brain` | URL prefix (e.g. `brain` → accessible at `/brain`) |

### Example `.env`

```env
# ── Required ────────────────────────────────────────────────
BRAIN_AI_DRIVER=openai
OPENAI_API_KEY=sk-your-key-here

# ── Model settings ───────────────────────────────────────────
BRAIN_OPENAI_MODEL=gpt-4o
BRAIN_OPENAI_MAX_TOKENS=4096
BRAIN_OPENAI_TIMEOUT=60

# ── Caching ──────────────────────────────────────────────────
BRAIN_CACHE_ENABLED=true
BRAIN_ASK_CACHE_CONTEXT=true
BRAIN_CACHE_CONTEXT_TTL=7200
BRAIN_CACHE_PREFIX=brain

# ── Logging ──────────────────────────────────────────────────
BRAIN_ASK_LOG_QUERIES=true
BRAIN_LOG_CHANNEL=single     # Laravel; use BRAIN_LOG_CHANNEL=debug for CI4
```

---

## AI Providers

Four providers are built in. Switch between them by changing `BRAIN_AI_DRIVER`.

| Driver | Key variable | Default model | Endpoint |
|---|---|---|---|
| `openai` | `OPENAI_API_KEY` | `gpt-4o` | `https://api.openai.com/v1/chat/completions` |
| `gemini` | `GEMINI_API_KEY` | `gemini-1.5-pro` | `https://generativelanguage.googleapis.com/...` |
| `anthropic` | `ANTHROPIC_API_KEY` | `claude-3-5-sonnet-20241022` | `https://api.anthropic.com/v1/messages` |
| `deepseek` | `DEEPSEEK_API_KEY` | `deepseek-chat` | `https://api.deepseek.com/v1/chat/completions` |

### Custom Provider — Laravel

Implement `Arafat\Brain\AI\AppBrainAIInterface` and register it:

```php
// config/app-brain.php
'ai' => [
    'default' => 'myprovider',
    'drivers' => [
        'myprovider' => \App\AI\MyProvider::class,
    ],
],
```

### Custom Provider — CodeIgniter 4

Extend `Arafat\Brain\CI4\AI\AbstractCIProvider` and register in `app/Config/AppBrain.php`:

```php
public string $aiDefault = 'myprovider';

public array $aiDrivers = [
    'myprovider' => \App\AI\MyProvider::class,
];
```

---

## Laravel Usage

### Artisan Commands

```bash
# Scan your codebase (run once, then re-run whenever code changes)
php artisan app-brain:scan

# Ask a question
php artisan app-brain:ask "How does the checkout flow work?"

# Provide a keyword override
php artisan app-brain:ask "Explain payments" --keyword=payment

# Get JSON output
php artisan app-brain:ask "What routes does the user have?" --json
```

### Facade

```php
use Arafat\Brain\Facades\AppBrain;

$response = AppBrain::ask('How does the order placement flow work?');

echo $response->answer;       // Markdown-formatted answer
echo $response->intent->label(); // "Explain Workflow"
echo $response->driver;          // "openai"
echo $response->elapsedMs;       // 1243.7
```

Override keyword detection:

```php
$response = AppBrain::ask(
    question: 'Explain the payment process',
    keyword:  'payment',
);
```

### Response object (`AppBrainResponse`)

| Property | Type | Description |
|---|---|---|
| `query` | `string` | The original question |
| `keyword` | `string` | Extracted or overridden keyword |
| `intent` | `Intent` | Detected intent enum |
| `context` | `ContextResult` | Resolved context graph |
| `prompt` | `string` | Full prompt sent to the AI |
| `driver` | `string` | AI driver used |
| `answer` | `string` | Markdown-formatted AI response |
| `elapsedMs` | `float` | Total pipeline time in milliseconds |

### Context (direct access)

```php
use Arafat\Brain\Facades\Brain;

$context = Brain::context('order');

$context->models;             // Collection of matched model entities
$context->tables;             // Collection of matched table entities
$context->routes;             // Collection of matched route entities
$context->controllerMethods;  // Collection of matched controller method entities
$context->elapsedMs;
```

### Dependency Injection

```php
use Arafat\Brain\AI\AppBrainService;

class MyService
{
    public function __construct(private AppBrainService $brain) {}

    public function answer(string $question): string
    {
        return $this->brain->ask($question)->answer;
    }
}
```

---

## CodeIgniter 4 Usage

### Service factory

```php
use Arafat\Brain\CI4\Services\BrainServices;

$brain  = BrainServices::brain();           // shared singleton
$result = $brain->ask('How does the checkout flow work?');

echo $result['answer'];      // Markdown-formatted answer
echo $result['intent'];      // "explain_workflow"
echo $result['driver'];      // "openai"
echo $result['elapsed_ms'];  // 1243.7
```

### In a controller

```php
use Arafat\Brain\CI4\Services\BrainServices;
use Arafat\Brain\Exceptions\AIException;
use CodeIgniter\Controller;

class DashboardController extends Controller
{
    public function askBrain(): \CodeIgniter\HTTP\ResponseInterface
    {
        $question = $this->request->getPost('question');

        try {
            $result = BrainServices::brain()->ask($question);
            return $this->response->setJSON(['answer' => $result['answer']]);
        } catch (AIException $e) {
            return $this->response->setStatusCode(502)->setJSON(['error' => $e->getMessage()]);
        }
    }
}
```

### Response array

| Key | Type | Description |
|---|---|---|
| `query` | `string` | The original question |
| `keyword` | `string` | Extracted or overridden keyword |
| `intent` | `string` | Intent value: `explain_workflow`, `show_routes`, `describe_model`, `list_dependencies`, `general` |
| `driver` | `string` | AI driver used |
| `answer` | `string` | Markdown-formatted AI response |
| `elapsed_ms` | `float` | Total pipeline time in milliseconds |
| `context` | `array` | Full context breakdown (models, tables, routes, controller_methods) |

### Optional: shortcut via `Config\Services`

Add to `app/Config/Services.php`:

```php
public static function brain(bool $getShared = true): \Arafat\Brain\CI4\AppBrainCI4Service
{
    return \Arafat\Brain\CI4\Services\BrainServices::brain($getShared);
}
```

Then use anywhere:

```php
$result = \Config\Services::brain()->ask('Show me the product routes.');
```

---

## Web Interface

### Laravel

The chat page is available at `GET /brain` and protected by the `auth` middleware by default.

```php
// config/app-brain.php
'ui' => [
    'enabled'    => true,
    'prefix'     => 'brain',
    'middleware' => ['web', 'auth'],  // remove 'auth' to make public
],
```

Include the floating widget in any Blade layout:

```blade
@include('brain::widget')
```

### CodeIgniter 4

Register the routes (if not already done):

```php
// app/Config/Routes.php
require ROOTPATH . 'vendor/inceptia-io/larabrain/routes/ci4-brain.php';
```

Visit `GET /brain` for the standalone chat page.

Include the floating widget in any CI4 view:

```php
<?php include ROOTPATH . 'vendor/inceptia-io/larabrain/resources/ci4-views/brain/widget.php'; ?>
```

> **Note:** The CI4 widget uses `csrf_token()` and `csrf_hash()` — ensure CSRF protection is enabled in `app/Config/Security.php` (it is by default).

---

## Intent Detection

Each question is automatically classified into one of five intents before the prompt is assembled. This focuses the AI's answer on the relevant aspect of your codebase.

| Intent | Value | Triggered by |
|---|---|---|
| Explain Workflow | `explain_workflow` | "how does", "explain", "walk me through", "flow", "process", "lifecycle", "pipeline" |
| Show Routes | `show_routes` | "route", "routes", "endpoint", "url", "uri", "api path" |
| Describe Model | `describe_model` | "model", "schema", "fields", "columns", "fillable", "casts", "relationships" |
| List Dependencies | `list_dependencies` | "dependencies", "what uses", "what calls", "relies on", "references" |
| General | `general` | Everything else |

Extend the intent map at runtime:

```php
use Arafat\Brain\AI\Intent;
use Arafat\Brain\AI\IntentMap;

IntentMap::extend(Intent::ExplainWorkflow, ['journey', 'sequence', 'steps']);
```

---

## Configuration Reference

### Laravel (`config/app-brain.php`)

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Globally enable or disable the package |
| `cache.enabled` | `true` | Enable the cache layer |
| `cache.ttl` | `3600` | Default cache TTL in seconds |
| `cache.context_ttl` | `3600` | TTL for context results |
| `cache.prefix` | `brain` | Cache key prefix |
| `ask.cache_context` | `false` | Cache context per keyword |
| `ask.log_queries` | `false` | Log each ask() call at DEBUG level |
| `log_channel` | `null` | Log channel (null = app default) |
| `scan.exclude` | vendor, node_modules… | Paths skipped during scanning |
| `scan.scanners.migration` | `true` | Enable migration scanner |
| `scan.scanners.model` | `true` | Enable model scanner |
| `scan.scanners.route` | `true` | Enable route scanner |
| `scan.scanners.controller` | `true` | Enable controller scanner |
| `ai.default` | `openai` | Active AI driver |
| `ui.enabled` | `true` | Enable web chat interface |
| `ui.prefix` | `brain` | URL prefix |
| `ui.middleware` | `['web', 'auth']` | Middleware stack for UI routes |

### CodeIgniter 4 (`app/Config/AppBrain.php`)

| Property | Default | Description |
|---|---|---|
| `$enabled` | `true` | Globally enable or disable the package |
| `$cacheEnabled` | `true` | Enable the cache layer |
| `$cacheTtl` | `3600` | Default cache TTL in seconds |
| `$cacheContextTtl` | `3600` | TTL for context results |
| `$cachePrefix` | `brain` | Cache key prefix |
| `$askCacheContext` | `false` | Cache context per keyword |
| `$askLogQueries` | `false` | Log each ask() call |
| `$logLevel` | `null` | CI4 log level string (`debug`, `info`) — null disables |
| `$aiDefault` | `openai` | Active AI driver key |
| `$aiDrivers` | all four built-in | Map of driver name → provider class |
| `$providers` | see config | Per-provider API keys, models, timeouts |
| `$scanPaths` | `[]` | Additional scan paths |
| `$scanExclude` | vendor, node_modules… | Paths skipped during scanning |

---

## Testing (Laravel)

```bash
# Run tests
composer test

# With coverage
composer test:coverage

# Static analysis (PHPStan level 6)
composer analyse

# Auto-fix code style
composer format

# Style check + analysis in one
composer quality
```

---

## License

MIT

{
    public function __construct(
        protected readonly BrainInterface $brain
    ) {}
}
```

---

## Folder Structure

```
laravel-brain/
├── composer.json
├── .gitignore
├── config/
│   └── app-brain.php            # Package configuration
├── database/
│   └── migrations/
│       └── 2026_01_01_000000_create_brain_tables.php
├── src/
│   ├── Brain.php                # Core implementation
│   ├── BrainServiceProvider.php # Laravel service provider
│   ├── Contracts/
│   │   └── BrainInterface.php  # Public API contract
│   ├── Exceptions/
│   │   └── BrainException.php  # Base package exception
│   └── Facades/
│       └── Brain.php            # Laravel Facade
└── tests/
    ├── Pest.php                 # Pest bootstrap
    ├── TestCase.php             # Testbench base case
    ├── Feature/                 # Feature tests
    └── Unit/                   # Unit tests
```

---

## Testing

```bash
composer test
# with coverage
composer test:coverage
```

---

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
