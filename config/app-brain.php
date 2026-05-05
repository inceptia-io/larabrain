<?php

declare(strict_types=1);
use Arafat\Brain\AI\Providers\AnthropicProvider;
use Arafat\Brain\AI\Providers\DeepSeekProvider;
use Arafat\Brain\AI\Providers\GeminiProvider;
use Arafat\Brain\AI\Providers\OpenAIProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Brain — Default Configuration
    |--------------------------------------------------------------------------
    |
    | This file is the central configuration for the laravel-brain package.
    | Publish this file with:
    |   php artisan vendor:publish --tag=brain-config
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Package Enabled
    |--------------------------------------------------------------------------
    |
    | Globally enable or disable the package without removing it.
    |
    */
    'enabled' => env('BRAIN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Configure caching behaviour for the package.
    |
    */
    'cache' => [
        'enabled' => env('BRAIN_CACHE_ENABLED', true),
        'ttl' => env('BRAIN_CACHE_TTL', 3600),          // seconds — default TTL for all cached items
        'context_ttl' => env('BRAIN_CACHE_CONTEXT_TTL', 3600), // seconds — TTL for context results specifically
        'prefix' => env('BRAIN_CACHE_PREFIX', 'brain'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Set the log channel used by the package.
    | Leave null to use the application default channel.
    |
    */
    'log_channel' => env('BRAIN_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Ask (AppBrainService) Behaviour
    |--------------------------------------------------------------------------
    |
    | Fine-grained toggles for the AppBrainService ask() pipeline.
    |
    | cache_context  — Cache resolved ContextResult by keyword.
    |                  Requires cache.enabled = true.
    |                  TTL is read from cache.context_ttl (falls back to cache.ttl).
    |
    | log_queries    — Log each completed ask() call at DEBUG level.
    |                  The entry includes: query, keyword, intent, driver,
    |                  elapsed_ms, and context entity counts.
    |                  Respects the log_channel setting above.
    |
    */
    'ask' => [
        'cache_context' => env('BRAIN_ASK_CACHE_CONTEXT', false),
        'log_queries' => env('BRAIN_ASK_LOG_QUERIES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Configure queueing behaviour for async operations.
    |
    */
    'queue' => [
        'enabled' => env('BRAIN_QUEUE_ENABLED', false),
        'connection' => env('BRAIN_QUEUE_CONNECTION', 'default'),
        'queue_name' => env('BRAIN_QUEUE_NAME', 'brain'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner
    |--------------------------------------------------------------------------
    |
    | Control the behaviour of the `app-brain:scan` command.
    |
    | `paths`   — Additional paths to include alongside base_path().
    | `exclude` — Relative path segments to skip during file discovery.
    | `scanners`— Associative map of scanner name => enabled flag.
    |             Set a scanner to false to disable it globally.
    |
    */
    'scan' => [
        'paths' => [],

        'exclude' => [
            'vendor',
            'node_modules',
            'storage',
            'bootstrap/cache',
            'public',
        ],

        'scanners' => [
            'migration' => env('BRAIN_SCANNER_MIGRATION', true),
            'model' => env('BRAIN_SCANNER_MODEL', true),
            'route' => env('BRAIN_SCANNER_ROUTE', true),
            'controller' => env('BRAIN_SCANNER_CONTROLLER', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Set the default AI driver and configure each provider's credentials.
    | Switch the active provider at any time by changing `default`.
    |
    | Supported drivers: openai, gemini, anthropic, deepseek
    | Custom drivers may be added to the `drivers` map.
    |
    */
    'ai' => [

        'default' => env('BRAIN_AI_DRIVER', 'openai'),

        /*
         * Map of driver name => fully-qualified provider class.
         * You can override any built-in entry or add custom providers here.
         */
        'drivers' => [
            'openai' => OpenAIProvider::class,
            'gemini' => GeminiProvider::class,
            'anthropic' => AnthropicProvider::class,
            'deepseek' => DeepSeekProvider::class,
        ],

        'providers' => [

            'openai' => [
                'api_key' => env('OPENAI_API_KEY', ''),
                'model' => env('BRAIN_OPENAI_MODEL', 'gpt-4o'),
                'max_tokens' => (int) env('BRAIN_OPENAI_MAX_TOKENS', 2048),
                'timeout' => (int) env('BRAIN_OPENAI_TIMEOUT', 30),
                'base_url' => env('BRAIN_OPENAI_BASE_URL', 'https://api.openai.com'),
            ],

            'gemini' => [
                'api_key' => env('GEMINI_API_KEY', ''),
                'model' => env('BRAIN_GEMINI_MODEL', 'gemini-1.5-pro'),
                'max_tokens' => (int) env('BRAIN_GEMINI_MAX_TOKENS', 2048),
                'timeout' => (int) env('BRAIN_GEMINI_TIMEOUT', 30),
                'base_url' => env('BRAIN_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            ],

            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY', ''),
                'model' => env('BRAIN_ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
                'max_tokens' => (int) env('BRAIN_ANTHROPIC_MAX_TOKENS', 2048),
                'timeout' => (int) env('BRAIN_ANTHROPIC_TIMEOUT', 30),
                'base_url' => env('BRAIN_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            ],

            'deepseek' => [
                'api_key' => env('DEEPSEEK_API_KEY', ''),
                'model' => env('BRAIN_DEEPSEEK_MODEL', 'deepseek-chat'),
                'max_tokens' => (int) env('BRAIN_DEEPSEEK_MAX_TOKENS', 2048),
                'timeout' => (int) env('BRAIN_DEEPSEEK_TIMEOUT', 30),
                'base_url' => env('BRAIN_DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Web UI
    |--------------------------------------------------------------------------
    |
    | Enables the built-in chat interface available at /{prefix}.
    |
    | enabled     — Set to false to disable all UI routes entirely.
    | prefix      — URL prefix for the UI routes. Default: "brain"
    |               → standalone page: /brain
    |               → widget endpoint: /brain/ask
    | middleware  — List of middleware applied to all UI routes.
    |               Default ['web', 'auth'] means the page is protected by your
    |               application's existing auth system — users who are already
    |               logged in can access it directly.
    |               Change to ['web'] to make it publicly accessible.
    |
    */
    'ui' => [
        'enabled'    => env('BRAIN_UI_ENABLED', true),
        'prefix'     => env('BRAIN_UI_PREFIX', 'brain'),
        'middleware' => ['web', 'auth'],
    ],

];
