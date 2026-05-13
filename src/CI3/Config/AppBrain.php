<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\Config;

/**
 * AppBrain Configuration for CodeIgniter 3
 *
 * Copy this file to application/config/AppBrain.php in your CI3 project
 * and adjust values as needed.  All settings can also be driven by
 * environment variables (via putenv / server-level env or a dotenv library).
 *
 * Environment variable keys:
 *   BRAIN_ENABLED, BRAIN_AI_DRIVER, BRAIN_CACHE_ENABLED, BRAIN_CACHE_TTL,
 *   BRAIN_CACHE_CONTEXT_TTL, BRAIN_CACHE_PREFIX, BRAIN_ASK_CACHE_CONTEXT,
 *   BRAIN_ASK_LOG_QUERIES, BRAIN_LOG_LEVEL,
 *   OPENAI_API_KEY, BRAIN_OPENAI_MODEL, BRAIN_OPENAI_MAX_TOKENS, BRAIN_OPENAI_TIMEOUT, BRAIN_OPENAI_BASE_URL,
 *   GEMINI_API_KEY, BRAIN_GEMINI_MODEL, BRAIN_GEMINI_TIMEOUT,
 *   ANTHROPIC_API_KEY, BRAIN_ANTHROPIC_MODEL, BRAIN_ANTHROPIC_MAX_TOKENS, BRAIN_ANTHROPIC_TIMEOUT,
 *   DEEPSEEK_API_KEY, BRAIN_DEEPSEEK_MODEL, BRAIN_DEEPSEEK_MAX_TOKENS, BRAIN_DEEPSEEK_TIMEOUT, BRAIN_DEEPSEEK_BASE_URL
 */
class AppBrain
{
    // ── Package ───────────────────────────────────────────────────────────────

    /** @var bool Globally enable or disable the package. */
    public $enabled = true;

    // ── Cache ─────────────────────────────────────────────────────────────────

    /** @var bool */
    public $cacheEnabled = false;

    /** @var int Default cache TTL in seconds. */
    public $cacheTtl = 3600;

    /** @var int TTL specifically for resolved context results. */
    public $cacheContextTtl = 3600;

    /** @var string */
    public $cachePrefix = 'brain';

    // ── Logging ───────────────────────────────────────────────────────────────

    /** @var string|null CI3 log level (error, debug, info). Null disables query logging. */
    public $logLevel = null;

    // ── Ask pipeline ──────────────────────────────────────────────────────────

    /** @var bool Cache the context result keyed by keyword. */
    public $askCacheContext = false;

    /** @var bool Log every ask() call. */
    public $askLogQueries = false;

    // ── AI ────────────────────────────────────────────────────────────────────

    /** @var string Active driver key — must match a key in $aiDrivers. */
    public $aiDefault = 'openai';

    /**
     * Map of driver key => provider class.
     *
     * @var array<string, string>
     */
    public $aiDrivers = [
        'openai'    => \Arafat\Brain\CI3\AI\Providers\OpenAIProvider::class,
        'gemini'    => \Arafat\Brain\CI3\AI\Providers\GeminiProvider::class,
        'anthropic' => \Arafat\Brain\CI3\AI\Providers\AnthropicProvider::class,
        'deepseek'  => \Arafat\Brain\CI3\AI\Providers\DeepSeekProvider::class,
    ];

    /**
     * Per-provider credentials and settings.
     *
     * @var array<string, array<string, mixed>>
     */
    public $providers = [
        'openai' => [
            'api_key'    => '',   // env: OPENAI_API_KEY
            'model'      => 'gpt-4o',
            'max_tokens' => 2048,
            'timeout'    => 30,
            'base_url'   => 'https://api.openai.com',
        ],
        'gemini' => [
            'api_key' => '',      // env: GEMINI_API_KEY
            'model'   => 'gemini-1.5-pro',
            'timeout' => 30,
        ],
        'anthropic' => [
            'api_key'    => '',   // env: ANTHROPIC_API_KEY
            'model'      => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 2048,
            'timeout'    => 30,
        ],
        'deepseek' => [
            'api_key'    => '',   // env: DEEPSEEK_API_KEY
            'model'      => 'deepseek-chat',
            'max_tokens' => 2048,
            'timeout'    => 30,
            'base_url'   => 'https://api.deepseek.com',
        ],
    ];

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct()
    {
        // Package toggle
        $this->applyEnvBool('BRAIN_ENABLED', 'enabled');

        // AI
        $this->applyEnvString('BRAIN_AI_DRIVER', 'aiDefault');

        // Cache
        $this->applyEnvBool('BRAIN_CACHE_ENABLED', 'cacheEnabled');
        $this->applyEnvInt('BRAIN_CACHE_TTL', 'cacheTtl');
        $this->applyEnvInt('BRAIN_CACHE_CONTEXT_TTL', 'cacheContextTtl');
        $this->applyEnvString('BRAIN_CACHE_PREFIX', 'cachePrefix');

        // Ask pipeline
        $this->applyEnvBool('BRAIN_ASK_CACHE_CONTEXT', 'askCacheContext');
        $this->applyEnvBool('BRAIN_ASK_LOG_QUERIES', 'askLogQueries');

        // Logging
        $this->applyEnvString('BRAIN_LOG_LEVEL', 'logLevel');

        // Provider env vars
        $this->loadProviderEnv('openai', [
            'api_key'    => 'OPENAI_API_KEY',
            'model'      => 'BRAIN_OPENAI_MODEL',
            'max_tokens' => 'BRAIN_OPENAI_MAX_TOKENS',
            'timeout'    => 'BRAIN_OPENAI_TIMEOUT',
            'base_url'   => 'BRAIN_OPENAI_BASE_URL',
        ]);
        $this->loadProviderEnv('gemini', [
            'api_key' => 'GEMINI_API_KEY',
            'model'   => 'BRAIN_GEMINI_MODEL',
            'timeout' => 'BRAIN_GEMINI_TIMEOUT',
        ]);
        $this->loadProviderEnv('anthropic', [
            'api_key'    => 'ANTHROPIC_API_KEY',
            'model'      => 'BRAIN_ANTHROPIC_MODEL',
            'max_tokens' => 'BRAIN_ANTHROPIC_MAX_TOKENS',
            'timeout'    => 'BRAIN_ANTHROPIC_TIMEOUT',
        ]);
        $this->loadProviderEnv('deepseek', [
            'api_key'    => 'DEEPSEEK_API_KEY',
            'model'      => 'BRAIN_DEEPSEEK_MODEL',
            'max_tokens' => 'BRAIN_DEEPSEEK_MAX_TOKENS',
            'timeout'    => 'BRAIN_DEEPSEEK_TIMEOUT',
            'base_url'   => 'BRAIN_DEEPSEEK_BASE_URL',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function applyEnvBool(string $envKey, string $property): void
    {
        $val = getenv($envKey);
        if ($val !== false && $val !== '') {
            $this->$property = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }
    }

    private function applyEnvInt(string $envKey, string $property): void
    {
        $val = getenv($envKey);
        if ($val !== false && $val !== '') {
            $this->$property = (int) $val;
        }
    }

    private function applyEnvString(string $envKey, string $property): void
    {
        $val = getenv($envKey);
        if ($val !== false && $val !== '') {
            $this->$property = (string) $val;
        }
    }

    /**
     * @param array<string, string> $keyMap  field => ENV_KEY
     */
    private function loadProviderEnv(string $driver, array $keyMap): void
    {
        foreach ($keyMap as $field => $envKey) {
            $val = getenv($envKey);
            if ($val !== false && $val !== '') {
                $this->providers[$driver][$field] = $val;
            }
        }
    }
}
