<?php

declare(strict_types=1);

namespace Arafat\Brain\AI;

use Arafat\Brain\AI\Providers\AnthropicProvider;
use Arafat\Brain\AI\Providers\DeepSeekProvider;
use Arafat\Brain\AI\Providers\GeminiProvider;
use Arafat\Brain\AI\Providers\OpenAIProvider;
use Arafat\Brain\Exceptions\AIException;

/**
 * Resolves the active AI provider from configuration and proxies calls to it.
 *
 * Driver resolution
 * ─────────────────
 * The active driver is read from `app-brain.ai.default`.  A custom provider
 * class can be registered by adding its fully-qualified class name to the
 * `app-brain.ai.drivers` map:
 *
 *   'drivers' => [
 *       'openai'     => \Arafat\Brain\AI\Providers\OpenAIProvider::class,
 *       'gemini'     => \Arafat\Brain\AI\Providers\GeminiProvider::class,
 *       'anthropic'  => \Arafat\Brain\AI\Providers\AnthropicProvider::class,
 *       'deepseek'   => \Arafat\Brain\AI\Providers\DeepSeekProvider::class,
 *       'my-custom'  => \App\AI\MyCustomProvider::class,
 *   ],
 *
 * The resolved class must implement AppBrainAIInterface (which all
 * AbstractAIProvider subclasses do automatically).
 *
 * This class itself implements AppBrainAIInterface so it can be bound
 * directly to the interface in the service container.
 */
final class AIManager implements AppBrainAIInterface
{
    /** Built-in driver → class map. */
    private const BUILT_IN_DRIVERS = [
        'openai' => OpenAIProvider::class,
        'gemini' => GeminiProvider::class,
        'anthropic' => AnthropicProvider::class,
        'deepseek' => DeepSeekProvider::class,
    ];

    private readonly AppBrainAIInterface $provider;

    /**
     * @param  array<string, mixed>  $config  Full app-brain config array
     */
    public function __construct(array $config)
    {
        $this->provider = $this->resolve($config);
    }

    // ── AppBrainAIInterface ───────────────────────────────────────────────────

    public function ask(string $question, array $context = []): string
    {
        return $this->provider->ask($question, $context);
    }

    public function driver(): string
    {
        return $this->provider->driver();
    }

    // ── Resolution ─────────────────────────────────────────────────────────────

    private function resolve(array $config): AppBrainAIInterface
    {
        $aiConfig = $config['ai'] ?? [];
        $driverKey = (string) ($aiConfig['default'] ?? 'openai');

        // Merge built-ins with any user-defined drivers (user values win)
        $drivers = array_merge(
            self::BUILT_IN_DRIVERS,
            (array) ($aiConfig['drivers'] ?? []),
        );

        if (!isset($drivers[$driverKey])) {
            throw new AIException(
                "Unknown AI driver '{$driverKey}'. "
                .'Available: '.implode(', ', array_keys($drivers)).'.',
                $driverKey,
            );
        }

        $class = $drivers[$driverKey];

        if (!class_exists($class)) {
            throw new AIException(
                "AI driver class '{$class}' does not exist.",
                $driverKey,
            );
        }

        $instance = new $class($config);

        if (!$instance instanceof AppBrainAIInterface) {
            throw new AIException(
                "Driver class '{$class}' must implement AppBrainAIInterface.",
                $driverKey,
            );
        }

        return $instance;
    }
}
