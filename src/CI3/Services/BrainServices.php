<?php

declare(strict_types=1);

namespace Arafat\Brain\CI3\Services;

use Arafat\Brain\CI3\AI\AbstractCIProvider;
use Arafat\Brain\CI3\AppBrainCI3Service;
use Arafat\Brain\CI3\Config\AppBrain as AppBrainConfig;
use Arafat\Brain\CI3\Context\CIContextBuilder;
use Arafat\Brain\Exceptions\AIException;

/**
 * BrainServices
 *
 * Factory / singleton for AppBrainCI3Service.
 *
 * Usage in your CI3 controller or model:
 * ────────────────────────────────────────
 *   $brain  = \Arafat\Brain\CI3\Services\BrainServices::brain();
 *   $result = $brain->ask('How does the checkout flow work?');
 *   echo $result['answer'];
 *
 * Requirements
 * ────────────
 *   • CI3 Composer autoloading must be enabled:
 *       $config['composer_autoload'] = FCPATH . 'vendor/autoload.php';
 *   • Your database must be configured in application/config/database.php.
 *   • Set environment variables (OPENAI_API_KEY, BRAIN_AI_DRIVER, etc.) via
 *       your server config, .env loader, or CI3's config files.
 */
final class BrainServices
{
    /** @var AppBrainCI3Service|null */
    private static $instance = null;

    /**
     * Return a shared (singleton) AppBrainCI3Service instance.
     * Pass $getShared = false to force a fresh instance.
     */
    public static function brain(bool $getShared = true): AppBrainCI3Service
    {
        if ($getShared && self::$instance !== null) {
            return self::$instance;
        }

        $config = new AppBrainConfig();

        if (!$config->enabled) {
            throw new \RuntimeException('Brain is disabled (BRAIN_ENABLED=false).');
        }

        // Get the CI super object
        $CI = &get_instance();

        // Ensure database is loaded
        if (!isset($CI->db)) {
            $CI->load->database();
        }

        // Ensure cache driver is loaded when caching is enabled
        if ($config->askCacheContext && $config->cacheEnabled) {
            if (!isset($CI->cache)) {
                $CI->load->driver('cache');
            }
        }

        $contextBuilder = new CIContextBuilder($CI->db);
        $provider       = self::resolveProvider($config);
        $appUrl         = function_exists('base_url') ? base_url() : '';

        $service = new AppBrainCI3Service(
            $contextBuilder,
            $provider,
            $config,
            $appUrl
        );

        if ($getShared) {
            self::$instance = $service;
        }

        return $service;
    }

    /**
     * Resolve the active AI provider from config.
     */
    private static function resolveProvider(AppBrainConfig $config): AbstractCIProvider
    {
        $driver  = $config->aiDefault;
        $drivers = $config->aiDrivers;

        if (!isset($drivers[$driver])) {
            throw new AIException(
                "Brain: unknown AI driver '{$driver}'. Check your AppBrain config.",
                $driver
            );
        }

        $providerClass = $drivers[$driver];

        if (!class_exists($providerClass)) {
            throw new AIException(
                "Brain: provider class '{$providerClass}' does not exist.",
                $driver
            );
        }

        $fullConfigArray = [
            'providers' => $config->providers,
        ];

        return new $providerClass($fullConfigArray);
    }

    /**
     * Clear the shared singleton (useful in tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Return the absolute path to the CI3 views directory of this package.
     */
    public static function viewPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/ci3-views/brain/';
    }
}
