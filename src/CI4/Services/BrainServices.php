<?php

declare(strict_types=1);

namespace Arafat\Brain\CI4\Services;

use Arafat\Brain\CI4\AI\AbstractCIProvider;
use Arafat\Brain\CI4\AppBrainCI4Service;
use Arafat\Brain\CI4\Config\AppBrain as AppBrainConfig;
use Arafat\Brain\CI4\Context\CIContextBuilder;
use Arafat\Brain\Exceptions\AIException;

/**
 * BrainServices
 *
 * CI4-style service factory for the Brain package.
 * Mirrors CI4's own \Config\Services pattern.
 *
 * Usage in your CI4 code
 * ──────────────────────
 *   // Simple (uses config defaults):
 *   $brain = \Arafat\Brain\CI4\Services\BrainServices::brain();
 *   $result = $brain->ask('How does the checkout flow work?');
 *   echo $result['answer'];
 *
 * Setup in app/Config/Services.php (optional shortcut):
 * ──────────────────────────────────────────────────────
 *   public static function brain(bool $getShared = true): AppBrainCI4Service
 *   {
 *       return \Arafat\Brain\CI4\Services\BrainServices::brain($getShared);
 *   }
 */
final class BrainServices
{
    private static ?AppBrainCI4Service $instance = null;

    /**
     * Return a shared (singleton) AppBrainCI4Service instance.
     * Pass $getShared = false to force a fresh instance.
     */
    public static function brain(bool $getShared = true): AppBrainCI4Service
    {
        if ($getShared && self::$instance !== null) {
            return self::$instance;
        }

        $config = new AppBrainConfig();

        if (!$config->enabled) {
            throw new \RuntimeException('Laravel Brain is disabled (BRAIN_ENABLED=false).');
        }

        $db             = \Config\Database::connect();
        $contextBuilder = new CIContextBuilder($db);
        $provider       = self::resolveProvider($config);
        $appUrl         = (string) env('app.baseURL', base_url());

        $service = new AppBrainCI4Service(
            contextBuilder: $contextBuilder,
            ai: $provider,
            config: $config,
            appUrl: $appUrl,
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
        $driver = $config->aiDefault;
        $drivers = $config->aiDrivers;

        if (!isset($drivers[$driver])) {
            throw new AIException(
                "Brain: unknown AI driver '{$driver}'. Check your AppBrain config.",
                $driver,
            );
        }

        $providerClass = $drivers[$driver];

        if (!class_exists($providerClass)) {
            throw new AIException(
                "Brain: provider class '{$providerClass}' does not exist.",
                $driver,
            );
        }

        // Pass the full config as array (providers slice keyed by driver name)
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
     * Return the absolute path to the CI4 views directory of this package.
     * Use this to load views with an explicit path in the controller.
     */
    public static function viewPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/ci4-views/brain/';
    }
}
