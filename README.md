# LaraBrain

Give your Laravel application self-awareness. LaraBrain scans your codebase (models, migrations, routes, controllers), builds a structured context graph, and uses an AI provider to answer natural-language questions about how your application works — with clickable links to relevant pages.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/inceptia-io/larabrain.svg)](https://packagist.org/packages/inceptia-io/larabrain)
[![PHP Version](https://img.shields.io/packagist/php-v/inceptia-io/larabrain.svg)](https://packagist.org/packages/inceptia-io/larabrain)
[![License](https://img.shields.io/github/license/inceptia-io/larabrain.svg)](LICENSE)

---

## Requirements

- PHP 8.1 or higher
- Laravel 10, 11, 12, or 13

---

## Installation

Install via Composer:

```bash
composer require inceptia-io/larabrain
```

Laravel auto-discovers the service provider. No manual registration is needed.

Publish the config file:

```bash
php artisan vendor:publish --tag=brain-config
```

This places `config/app-brain.php` in your application's `config/` directory.

---

## Environment Variables

Add the following to your `.env` file. Only the AI driver key is required to get started.

```env
# Which AI provider to use (openai, gemini, anthropic, deepseek)
BRAIN_AI_DRIVER=openai

# API keys — only set the one for your chosen driver
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...
ANTHROPIC_API_KEY=...
DEEPSEEK_API_KEY=...

# Optional: cache context lookups to avoid repeated filesystem scans
BRAIN_CACHE_ENABLED=true
BRAIN_ASK_CACHE_CONTEXT=false
BRAIN_CACHE_CONTEXT_TTL=3600

# Optional: log every ask() call at DEBUG level
BRAIN_ASK_LOG_QUERIES=false
BRAIN_LOG_CHANNEL=null
```

---

## Scanning Your Codebase

Before asking questions, scan your application to build the context index:

```bash
php artisan app-brain:scan
```

This scans your models, migrations, routes, and controllers and stores a snapshot. Options:

```bash
# Scan a specific path
php artisan app-brain:scan --path=app/Models

# Only run one type of scanner
php artisan app-brain:scan --scanner=model

# Preview files without scanning
php artisan app-brain:scan --dry-run
```

---

## Asking Questions

Use the Artisan command to ask a natural-language question:

```bash
php artisan app-brain:ask "How does the user registration flow work?"
```

Options:

```bash
# Provide an explicit keyword to focus context lookup
php artisan app-brain:ask "Explain the checkout process" --keyword=order

# Output the full response as JSON
php artisan app-brain:ask "What routes does the user have?" --json
```

---

## Web Interface

LaraBrain includes a built-in web UI for asking questions directly from your browser. Access is controlled by middleware (protected by `auth` by default).

### Standalone Chat Page

Visit the chat interface at your configured URL:

```
https://your-app.test/brain
```

This is a full-page chat application with:
- Clean dark theme
- Markdown rendering for AI responses
- Clickable links to relevant routes in your application
- Request metadata (intent, driver, elapsed time)

**Access control:** Protected by `auth` middleware by default. Configure in `config/app-brain.php`:

```php
'ui' => [
    'enabled'    => true,
    'prefix'     => 'brain',              // URL prefix
    'middleware' => ['web', 'auth'],      // Remove 'auth' to make public
],
```

To make it public:

```php
'middleware' => ['web'],
```

### Floating Widget

Drop the widget anywhere in your admin layout for quick access without leaving the page:

```blade
<!-- In your master layout (e.g. resources/views/layouts/app.blade.php) -->
@include('brain::widget')
```

The widget appears as a fixed button in the bottom-right corner. Click to open a chat panel with the same features as the standalone page.

**Example:** Add to your admin panel footer:

```blade
<footer>
    <p>&copy; {{ date('Y') }} Your App</p>
    @include('brain::widget')
</footer>
```

The widget respects the same middleware configuration as the chat page.

---

## Using the Facade

```php
use AppBrain;

$response = AppBrain::ask('How does the order placement flow work?');

echo $response->answer;
echo $response->intent->label();   // e.g. "Explain Workflow"
echo $response->driver;            // e.g. "openai"
echo $response->elapsedMs;
```

You can also override keyword detection:

```php
$response = AppBrain::ask(
    question: 'Explain the payment process',
    keyword:  'payment',
);
```

The `AppBrainResponse` object contains:

| Property | Type | Description |
|---|---|---|
| `query` | string | The original question |
| `keyword` | string | Extracted or overridden keyword |
| `intent` | Intent | Detected intent enum |
| `context` | ContextResult | The resolved context graph |
| `prompt` | string | The full prompt sent to the AI |
| `driver` | string | The AI driver used |
| `answer` | string | The AI response |
| `elapsedMs` | float | Total time in milliseconds |

---

## Intent Detection

The package classifies each question into one of five intents before building the prompt. This focuses the AI's response on the relevant aspect of your codebase.

| Intent | Triggered by |
|---|---|
| Explain Workflow | "how does", "explain", "walk me through", "flow", "process", "lifecycle" |
| Show Routes | "routes", "endpoints", "url", "web route", "api route" |
| Describe Model | "model", "schema", "fields", "fillable", "casts", "relationships" |
| List Dependencies | "dependencies", "what uses", "what calls", "connected to", "references" |
| General | Everything else |

You can extend the keyword map at runtime:

```php
use Arafat\Brain\AI\Intent;
use Arafat\Brain\AI\IntentMap;

IntentMap::extend(Intent::ExplainWorkflow, ['pipeline', 'journey', 'sequence']);
```

---

## Context Building

The `Brain` facade provides direct access to the context layer:

```php
use Brain;

$context = Brain::context('order');

$context->models;            // Collection of matched model data
$context->tables;            // Collection of matched table data
$context->routes;            // Collection of matched route data
$context->controllerMethods; // Collection of matched controller method data
$context->elapsedMs;
```

---

## AI Providers

Four providers are built in. Switch between them with `BRAIN_AI_DRIVER`.

| Driver | Environment Key | Default Model |
|---|---|---|
| `openai` | `OPENAI_API_KEY` | gpt-4o |
| `gemini` | `GEMINI_API_KEY` | gemini-1.5-pro |
| `anthropic` | `ANTHROPIC_API_KEY` | claude-3-5-sonnet-20241022 |
| `deepseek` | `DEEPSEEK_API_KEY` | deepseek-chat |

Per-provider model and token settings can be overridden in `config/app-brain.php` or via environment variables:

```env
BRAIN_OPENAI_MODEL=gpt-4-turbo
BRAIN_OPENAI_MAX_TOKENS=4096
BRAIN_OPENAI_TIMEOUT=60
```

### Custom Provider

Implement `Arafat\Brain\AI\AppBrainAIInterface` and register it in the config:

```php
// config/app-brain.php
'ai' => [
    'default' => 'myprovider',
    'drivers' => [
        'myprovider' => \App\AI\MyProvider::class,
    ],
],
```

---

## Configuration Reference

The full config file at `config/app-brain.php` includes these top-level keys:

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Globally enable or disable the package |
| `cache.enabled` | `true` | Enable the cache layer |
| `cache.ttl` | `3600` | Default cache TTL in seconds |
| `cache.context_ttl` | `3600` | TTL for context results specifically |
| `cache.prefix` | `brain` | Cache key prefix |
| `ask.cache_context` | `false` | Cache context results per keyword |
| `ask.log_queries` | `false` | Log each ask() call at DEBUG level |
| `log_channel` | `null` | Log channel (null uses app default) |
| `scan.exclude` | vendor, node_modules, storage, etc. | Paths excluded from file scanning |
| `scan.scanners` | all enabled | Per-scanner enable/disable flags |
| `ai.default` | `openai` | Active AI driver |
| `ui.enabled` | `true` | Enable/disable the web chat interface |
| `ui.prefix` | `brain` | URL prefix for chat page (e.g. `/brain`) |
| `ui.middleware` | `['web', 'auth']` | Middleware stack for UI routes (remove `auth` to make public) |

---

## Testing

```bash
composer test
```

With coverage:

```bash
composer test:coverage
```

Code quality:

```bash
# Style check + static analysis
composer quality

# Auto-fix style
composer format

# Static analysis only
composer analyse
```

---

## License

MIT


---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.1 |
| Laravel | ^10.0 \| ^11.0 \| ^12.0 |

---

## Installation

### 1 — Install via Composer

```bash
composer require inceptia-io/larabrain
```

Laravel will auto-discover the service provider via the `extra.laravel` key in
`composer.json`. No manual provider registration is required for Laravel 10+.

### 2 — Publish the config file

```bash
php artisan vendor:publish --tag=brain-config
```

This places `config/app-brain.php` in your application's `config/` directory.

### 3 — (Optional) Publish migrations

```bash
php artisan vendor:publish --tag=brain-migrations
php artisan migrate
```

### 4 — (Optional) Configure environment variables

Add any of the following to your `.env` file:

```env
BRAIN_ENABLED=true
BRAIN_CACHE_ENABLED=true
BRAIN_CACHE_TTL=3600
BRAIN_CACHE_PREFIX=brain
BRAIN_LOG_CHANNEL=null
BRAIN_QUEUE_ENABLED=false
BRAIN_QUEUE_CONNECTION=default
BRAIN_QUEUE_NAME=brain
```

---

## Usage

### Via Facade

```php
use Arafat\Brain\Facades\Brain;

Brain::method();
```

### Via Dependency Injection

```php
use Arafat\Brain\Contracts\BrainInterface;

class MyService
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
