# LaraBrain (CodeIgniter 4 Edition)

LaraBrain gives your CodeIgniter 4 app self-awareness.
It builds a context graph from your indexed application entities and uses AI to answer natural-language questions about your codebase.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/inceptia-io/larabrain.svg)](https://packagist.org/packages/inceptia-io/larabrain)
[![PHP Version](https://img.shields.io/packagist/php-v/inceptia-io/larabrain.svg)](https://packagist.org/packages/inceptia-io/larabrain)
[![License](https://img.shields.io/github/license/inceptia-io/larabrain.svg)](LICENSE)

## Requirements

- PHP 8.1+
- CodeIgniter 4.4+
- Composer

## Installation

```bash
composer require inceptia-io/larabrain
```

## Quick Start

### 1) Register package routes

In app/Config/Routes.php:

```php
require ROOTPATH . 'vendor/inceptia-io/larabrain/routes/ci4-brain.php';
```

Routes added:

- GET /brain
- POST /brain/ask

### 2) Configure environment

At minimum:

```env
BRAIN_AI_DRIVER=openai
OPENAI_API_KEY=sk-...
```

### 3) Prepare DB tables

The package expects:

- app_brain_entities
- app_brain_relations

Use your existing migration strategy in CI4 to create them.

### 4) Ask your first question

```php
use Arafat\Brain\CI4\Services\BrainServices;

$result = BrainServices::brain()->ask('How does checkout work?');
echo $result['answer'];
```

## Full Environment Keys

All keys are optional unless noted.

### Core

| Key | Default | Required | Notes |
|---|---|---|---|
| BRAIN_ENABLED | true | No | Global package switch |
| BRAIN_AI_DRIVER | openai | Yes | openai, gemini, anthropic, deepseek |

### Provider API Keys

| Key | Required when | Notes |
|---|---|---|
| OPENAI_API_KEY | BRAIN_AI_DRIVER=openai | OpenAI secret |
| GEMINI_API_KEY | BRAIN_AI_DRIVER=gemini | Google Gemini key |
| ANTHROPIC_API_KEY | BRAIN_AI_DRIVER=anthropic | Anthropic key |
| DEEPSEEK_API_KEY | BRAIN_AI_DRIVER=deepseek | DeepSeek key |

### OpenAI

| Key | Default |
|---|---|
| BRAIN_OPENAI_MODEL | gpt-4o |
| BRAIN_OPENAI_MAX_TOKENS | 2048 |
| BRAIN_OPENAI_TIMEOUT | 30 |
| BRAIN_OPENAI_BASE_URL | https://api.openai.com |

### Gemini

| Key | Default |
|---|---|
| BRAIN_GEMINI_MODEL | gemini-1.5-pro |
| BRAIN_GEMINI_MAX_TOKENS | 2048 |

### Anthropic

| Key | Default |
|---|---|
| BRAIN_ANTHROPIC_MODEL | claude-3-5-sonnet-20241022 |
| BRAIN_ANTHROPIC_MAX_TOKENS | 2048 |

### DeepSeek

| Key | Default |
|---|---|
| BRAIN_DEEPSEEK_MODEL | deepseek-chat |
| BRAIN_DEEPSEEK_MAX_TOKENS | 2048 |
| BRAIN_DEEPSEEK_BASE_URL | https://api.deepseek.com |

### Cache

| Key | Default | Notes |
|---|---|---|
| BRAIN_CACHE_ENABLED | true | Master cache switch |
| BRAIN_CACHE_TTL | 3600 | Default TTL seconds |
| BRAIN_CACHE_CONTEXT_TTL | 3600 | Context TTL seconds |
| BRAIN_CACHE_PREFIX | brain | Cache key namespace |
| BRAIN_ASK_CACHE_CONTEXT | false | Cache resolved context by keyword |

### Logging

| Key | Default | Notes |
|---|---|---|
| BRAIN_ASK_LOG_QUERIES | false | Log each ask() call |
| BRAIN_LOG_CHANNEL | null | For CI4 this is treated as log level string (e.g. debug) |

## CI4 Config File

Optional: copy this file into your app for centralized config overrides:

- vendor/inceptia-io/larabrain/src/CI4/Config/AppBrain.php
- to app/Config/AppBrain.php

Main properties in AppBrain config:

| Property | Default |
|---|---|
| $enabled | true |
| $cacheEnabled | true |
| $cacheTtl | 3600 |
| $cacheContextTtl | 3600 |
| $cachePrefix | brain |
| $logLevel | null |
| $askCacheContext | false |
| $askLogQueries | false |
| $aiDefault | openai |
| $aiDrivers | openai, gemini, anthropic, deepseek |
| $providers | per-driver credentials/options |
| $scanPaths | [] |
| $scanExclude | vendor, node_modules, writable, public |

## Usage

### Service usage

```php
use Arafat\Brain\CI4\Services\BrainServices;

$brain = BrainServices::brain();
$result = $brain->ask('Explain product creation flow.');

echo $result['answer'];
```

### Response shape

```php
[
  'query' => '...',
  'keyword' => '...',
  'intent' => 'explain_workflow|show_routes|describe_model|list_dependencies|general',
  'driver' => 'openai|gemini|anthropic|deepseek',
  'answer' => '...markdown...',
  'elapsed_ms' => 123.45,
  'context' => [ ... ],
]
```

### Optional Services shortcut

In app/Config/Services.php:

```php
public static function brain(bool $getShared = true): \Arafat\Brain\CI4\AppBrainCI4Service
{
    return \Arafat\Brain\CI4\Services\BrainServices::brain($getShared);
}
```

Then:

```php
$result = \Config\Services::brain()->ask('Show route dependencies for orders.');
```

## Web UI

### Standalone chat page

- URL: /brain
- Route source: routes/ci4-brain.php

### Floating widget include

In your layout/view:

```php
<?php include ROOTPATH . 'vendor/inceptia-io/larabrain/resources/ci4-views/brain/widget.php'; ?>
```

Note: widget requests include CSRF token/hash helpers. Keep CSRF enabled in CI4.

## Context Data Notes

This CI branch expects indexed data in:

- app_brain_entities
- app_brain_relations

Current branch focus is CI runtime. Automatic scanner CLI integration from Laravel is not used here.

## AI Drivers

Built-in drivers:

- openai
- gemini
- anthropic
- deepseek

You can add custom drivers by extending:

- Arafat\Brain\CI4\AI\AbstractCIProvider

and registering it in AppBrain config ($aiDrivers and $aiDefault).

## Intent Detection

Intent map values:

- explain_workflow
- show_routes
- describe_model
- list_dependencies
- general

Keywords are resolved by the shared intent map and used to bias prompt construction.

## License

MIT
