# LaraBrain — CodeIgniter 3 Edition

**Give your CodeIgniter 3 application self-awareness.**

LaraBrain lets you ask natural-language questions about your own codebase and get accurate, grounded answers powered by AI (OpenAI, Gemini, Anthropic, or DeepSeek). It uses a structured context graph stored in your database — no hallucinations, no guessing.

> **Branch:** `codebrain` — CodeIgniter 3 only.  
> Laravel support lives on the `master` branch.

---

## How It Works

1. You populate two database tables (`app_brain_entities`, `app_brain_relations`) with structured knowledge about your application (routes, models, controllers, tables).
2. When a question is asked, Brain queries those tables to build a focused context payload.
3. The context is assembled into a prompt and sent to the configured AI provider.
4. The AI returns a grounded, Markdown-formatted answer — based only on what it knows from your data.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 7.4 or 8.x |
| CodeIgniter | 3.x |
| Composer autoloading | enabled |
| Database | MySQL / MariaDB |

---

## Installation

```bash
composer require inceptia-io/larabrain:"dev-codebrain@dev"
```

### 1. Enable Composer Autoloading in CI3

In `application/config/config.php`:

```php
$config['composer_autoload'] = FCPATH . 'vendor/autoload.php';
```

### 2. Import the Database Schema

```bash
mysql -u your_user -p your_database < vendor/inceptia-io/larabrain/database/schema/codebrain.sql
```

This creates two tables:
- `app_brain_entities` — routes, models, controller methods, database tables
- `app_brain_relations` — relationships between entities

### 3. Set Environment Variables

Set these in your server config, `.env` file (via a dotenv library), or `putenv()`:

```env
BRAIN_ENABLED=true
BRAIN_AI_DRIVER=openai

# OpenAI
OPENAI_API_KEY=sk-...
BRAIN_OPENAI_MODEL=gpt-4o

# — OR — Gemini
GEMINI_API_KEY=...
BRAIN_AI_DRIVER=gemini
BRAIN_GEMINI_MODEL=gemini-1.5-pro

# — OR — Anthropic
ANTHROPIC_API_KEY=...
BRAIN_AI_DRIVER=anthropic
BRAIN_ANTHROPIC_MODEL=claude-3-5-sonnet-20241022

# — OR — DeepSeek
DEEPSEEK_API_KEY=...
BRAIN_AI_DRIVER=deepseek
BRAIN_DEEPSEEK_MODEL=deepseek-chat
```

### 4. Create the Controller Wrapper

Create `application/controllers/Brain.php`:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Brain extends \Arafat\Brain\CI3\Controllers\BrainController {}
```

### 5. Register Routes

In `application/config/routes.php` (at the bottom):

```php
require FCPATH . 'vendor/inceptia-io/larabrain/routes/ci3-brain.php';
```

This registers:
- `GET  /brain`        → full-page chat UI
- `POST /brain/ask`    → JSON API
- `GET  /brain/widget` → floating widget partial

---

## Quick Start — Ask Programmatically

```php
$brain  = \Arafat\Brain\CI3\Services\BrainServices::brain();
$result = $brain->ask('How does the checkout flow work?');

echo $result['answer'];      // Markdown answer from the AI
echo $result['intent'];      // Detected intent (explain_workflow, show_routes, …)
echo $result['driver'];      // AI provider used (openai, gemini, …)
echo $result['elapsed_ms'];  // Time in milliseconds
```

---

## Web UI

### Chat Page

Visit `/brain` for the full-page standalone chat interface.

### Floating Widget

Include in any CI3 view/layout:

```php
<?php include FCPATH . 'vendor/inceptia-io/larabrain/resources/ci3-views/brain/widget.php'; ?>
```

Or use the controller endpoint: `GET /brain/widget`

---

## Configuration Reference

All settings are read from environment variables in the constructor of `AppBrain`.

### Core

| Env Key | Default | Description |
|---|---|---|
| `BRAIN_ENABLED` | `true` | Enable / disable the package globally |
| `BRAIN_AI_DRIVER` | `openai` | Active AI provider: `openai`, `gemini`, `anthropic`, `deepseek` |

### Cache (requires CI3 cache driver)

| Env Key | Default | Description |
|---|---|---|
| `BRAIN_CACHE_ENABLED` | `false` | Enable CI3 cache for context results |
| `BRAIN_CACHE_TTL` | `3600` | Default TTL in seconds |
| `BRAIN_CACHE_CONTEXT_TTL` | `3600` | TTL for context query results |
| `BRAIN_CACHE_PREFIX` | `brain` | Cache key prefix |
| `BRAIN_ASK_CACHE_CONTEXT` | `false` | Cache context result per keyword |

### Logging

| Env Key | Default | Description |
|---|---|---|
| `BRAIN_LOG_LEVEL` | _(none)_ | CI3 log level for query logging (`debug`, `info`, `error`) |
| `BRAIN_ASK_LOG_QUERIES` | `false` | Log every ask() call |

### OpenAI

| Env Key | Default |
|---|---|
| `OPENAI_API_KEY` | _(required)_ |
| `BRAIN_OPENAI_MODEL` | `gpt-4o` |
| `BRAIN_OPENAI_MAX_TOKENS` | `2048` |
| `BRAIN_OPENAI_TIMEOUT` | `30` |
| `BRAIN_OPENAI_BASE_URL` | `https://api.openai.com` |

### Gemini

| Env Key | Default |
|---|---|
| `GEMINI_API_KEY` | _(required)_ |
| `BRAIN_GEMINI_MODEL` | `gemini-1.5-pro` |
| `BRAIN_GEMINI_TIMEOUT` | `30` |

### Anthropic

| Env Key | Default |
|---|---|
| `ANTHROPIC_API_KEY` | _(required)_ |
| `BRAIN_ANTHROPIC_MODEL` | `claude-3-5-sonnet-20241022` |
| `BRAIN_ANTHROPIC_MAX_TOKENS` | `2048` |
| `BRAIN_ANTHROPIC_TIMEOUT` | `30` |

### DeepSeek

| Env Key | Default |
|---|---|
| `DEEPSEEK_API_KEY` | _(required)_ |
| `BRAIN_DEEPSEEK_MODEL` | `deepseek-chat` |
| `BRAIN_DEEPSEEK_MAX_TOKENS` | `2048` |
| `BRAIN_DEEPSEEK_TIMEOUT` | `30` |
| `BRAIN_DEEPSEEK_BASE_URL` | `https://api.deepseek.com` |

---

## Context Data Tables

### `app_brain_entities`

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `type` | enum | `model`, `table`, `route`, `controller_method` |
| `key` | varchar | Machine-readable identifier |
| `name` | varchar | Human-readable name |
| `description` | text | What this entity does |
| `metadata` | json | Extra data (fields, URI, HTTP method, etc.) |
| `is_active` | tinyint | `1` = included in context queries |

### `app_brain_relations`

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `source_entity_id` | int | FK → `app_brain_entities.id` |
| `target_entity_id` | int | FK → `app_brain_entities.id` |
| `relation_type` | varchar | `used_by`, `calls`, `called_by`, `has_many`, `belongs_to` |

### Example Seed Data

```sql
INSERT INTO app_brain_entities (type, key, name, description, metadata, is_active)
VALUES ('model', 'Product', 'Product Model',
        'Manages product records.',
        '{"table":"products","fillable":["name","price","category_id"]}', 1);

INSERT INTO app_brain_entities (type, key, name, description, metadata, is_active)
VALUES ('route', 'GET /products', 'List Products',
        'Displays the product listing page.',
        '{"method":"GET","uri":"/products","controller":"Products","action":"index"}', 1);
```

---

## Custom Intent Keywords

Extend the built-in intent vocabulary at runtime (e.g. in a CI3 hook):

```php
use Arafat\Brain\AI\IntentMap;
use Arafat\Brain\AI\Intent;

IntentMap::extend(Intent::ExplainWorkflow(), ['saga', 'pipeline', 'lifecycle']);
IntentMap::extend(Intent::ShowRoutes(),      ['api docs', 'endpoints list']);
```

---

## ask() Response Shape

```php
[
    'query'      => 'How does the checkout flow work?',
    'keyword'    => 'checkout',
    'intent'     => 'explain_workflow',
    'driver'     => 'openai',
    'answer'     => '## Checkout Flow\n\n...',
    'elapsed_ms' => 842.3,
    'context'    => [
        'keyword' => 'checkout',
        'summary' => ['models' => 2, 'tables' => 1, 'routes' => 4, 'controller_methods' => 3],
        'models'  => [...],
        'routes'  => [...],
        ...
    ],
]
```

---

## File Structure

```
src/
  AI/
    Intent.php             # Value class (PHP 7.4+)
    IntentMap.php          # Keyword → Intent mapping
    PromptBuilder.php      # Builds the AI prompt
  CI3/
    AI/
      AbstractCIProvider.php
      Providers/
        OpenAIProvider.php
        GeminiProvider.php
        AnthropicProvider.php
        DeepSeekProvider.php
    Config/
      AppBrain.php         # Config class (reads from getenv())
    Context/
      CIContextBuilder.php # CI3 Query Builder — fetches context from DB
      CIContextResult.php  # Immutable value object
    Controllers/
      BrainController.php  # Base controller (extend in application/controllers/)
    Services/
      BrainServices.php    # Singleton factory
    AppBrainCI3Service.php # Main orchestration service
  Exceptions/
    AIException.php

routes/
  ci3-brain.php            # Drop-in route definitions

resources/
  ci3-views/brain/
    chat.php               # Full-page chat UI
    widget.php             # Floating widget partial

database/schema/
  codebrain.sql            # MySQL/MariaDB schema
```

---

## License

MIT © [Arafat Hossain](https://arafatdev.com)
