<?php

declare(strict_types=1);

namespace Arafat\Brain\CI4\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * AppBrain Configuration for CodeIgniter 4
 *
 * Copy this file to your CI4 project at app/Config/AppBrain.php
 * and adjust values as needed, or set them via your .env file.
 *
 * .env keys follow the same convention as the Laravel version
 * (BRAIN_* prefix) so migrating is straightforward.
 */
class AppBrain extends BaseConfig
{
    // ── Package ───────────────────────────────────────────────────────────────

    /** Globally enable or disable the package. */
    public bool $enabled = true;

    // ── Cache ─────────────────────────────────────────────────────────────────

    public bool $cacheEnabled = true;

    /** Default cache TTL in seconds. */
    public int $cacheTtl = 3600;

    /** TTL specifically for resolved context results. */
    public int $cacheContextTtl = 3600;

    public string $cachePrefix = 'brain';

    // ── Logging ───────────────────────────────────────────────────────────────

    /** CI4 log level (debug, info, error …). Null disables query logging. */
    public ?string $logLevel = null;

    // ── Ask pipeline ──────────────────────────────────────────────────────────

    public bool $askCacheContext = false;

    public bool $askLogQueries = false;

    // ── AI ────────────────────────────────────────────────────────────────────

    /** Active driver key — must match a key in $providers. */
    public string $aiDefault = 'openai';

    /**
     * Map of driver => provider class.
     *
     * @var array<string, class-string>
     */
    public array $aiDrivers = [
        'openai'    => \Arafat\Brain\CI4\AI\Providers\OpenAIProvider::class,
        'gemini'    => \Arafat\Brain\CI4\AI\Providers\GeminiProvider::class,
        'anthropic' => \Arafat\Brain\CI4\AI\Providers\AnthropicProvider::class,
        'deepseek'  => \Arafat\Brain\CI4\AI\Providers\DeepSeekProvider::class,
    ];

    /**
     * Per-provider credentials and settings.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $providers = [
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

    // ── Scan ──────────────────────────────────────────────────────────────────

    /**
     * Additional absolute paths to include during scanning.
     *
     * @var list<string>
     */
    public array $scanPaths = [];

    /**
     * Path segments to skip during file discovery.
     *
     * @var list<string>
     */
    public array $scanExclude = [
        'vendor',
        'node_modules',
        'writable',
        'public',
    ];

    // ── Constructor: map .env values ──────────────────────────────────────────

    public function __construct()
    {
        parent::__construct();

        // Allow .env overrides via CI4's env() helper
        $this->enabled          = (bool) env('BRAIN_ENABLED', $this->enabled);
        $this->cacheEnabled     = (bool) env('BRAIN_CACHE_ENABLED', $this->cacheEnabled);
        $this->cacheTtl         = (int) env('BRAIN_CACHE_TTL', $this->cacheTtl);
        $this->cacheContextTtl  = (int) env('BRAIN_CACHE_CONTEXT_TTL', $this->cacheContextTtl);
        $this->cachePrefix      = (string) env('BRAIN_CACHE_PREFIX', $this->cachePrefix);
        $this->askCacheContext  = (bool) env('BRAIN_ASK_CACHE_CONTEXT', $this->askCacheContext);
        $this->askLogQueries    = (bool) env('BRAIN_ASK_LOG_QUERIES', $this->askLogQueries);
        $this->aiDefault        = (string) env('BRAIN_AI_DRIVER', $this->aiDefault);

        // Per-provider .env overrides
        $this->providers['openai']['api_key']   = (string) env('OPENAI_API_KEY', '');
        $this->providers['openai']['model']     = (string) env('BRAIN_OPENAI_MODEL', $this->providers['openai']['model']);
        $this->providers['openai']['max_tokens'] = (int) env('BRAIN_OPENAI_MAX_TOKENS', $this->providers['openai']['max_tokens']);

        $this->providers['gemini']['api_key']   = (string) env('GEMINI_API_KEY', '');
        $this->providers['gemini']['model']     = (string) env('BRAIN_GEMINI_MODEL', $this->providers['gemini']['model']);

        $this->providers['anthropic']['api_key']    = (string) env('ANTHROPIC_API_KEY', '');
        $this->providers['anthropic']['model']      = (string) env('BRAIN_ANTHROPIC_MODEL', $this->providers['anthropic']['model']);
        $this->providers['anthropic']['max_tokens'] = (int) env('BRAIN_ANTHROPIC_MAX_TOKENS', $this->providers['anthropic']['max_tokens']);

        $this->providers['deepseek']['api_key']   = (string) env('DEEPSEEK_API_KEY', '');
        $this->providers['deepseek']['model']     = (string) env('BRAIN_DEEPSEEK_MODEL', $this->providers['deepseek']['model']);
        $this->providers['deepseek']['max_tokens'] = (int) env('BRAIN_DEEPSEEK_MAX_TOKENS', $this->providers['deepseek']['max_tokens']);
    }
}
