<?php

declare(strict_types=1);

namespace Arafat\Brain;

use Arafat\Brain\AI\AIManager;
use Arafat\Brain\AI\AppBrainAIInterface;
use Arafat\Brain\AI\AppBrainService;
use Arafat\Brain\Console\Commands\AskCommand;
use Arafat\Brain\Console\Commands\ScanCommand;
use Arafat\Brain\Context\ContextBuilder;
use Arafat\Brain\Contracts\BrainInterface;
use Arafat\Brain\Http\Controllers\BrainUIController;
use Arafat\Brain\Scanning\ControllerRepository;
use Arafat\Brain\Scanning\MigrationRepository;
use Arafat\Brain\Scanning\ModelRepository;
use Arafat\Brain\Scanning\Parsers\ControllerParser;
use Arafat\Brain\Scanning\Parsers\MigrationParser;
use Arafat\Brain\Scanning\Parsers\ModelParser;
use Arafat\Brain\Scanning\RouteRepository;
use Arafat\Brain\Scanning\ScannerManager;
use Arafat\Brain\Scanning\Scanners\ControllerScanner;
use Arafat\Brain\Scanning\Scanners\MigrationScanner;
use Arafat\Brain\Scanning\Scanners\ModelScanner;
use Arafat\Brain\Scanning\Scanners\RouteScanner;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class BrainServiceProvider extends ServiceProvider
{
    /**
     * All of the package's registered bindings.
     *
     * @var array<string, string>
     */
    public array $bindings = [];

    /**
     * All of the package's registered singletons.
     *
     * @var array<string, string>
     */
    public array $singletons = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/app-brain.php',
            'app-brain',
        );

        $this->app->singleton(ContextBuilder::class);

        $this->app->singleton(AppBrainAIInterface::class, function ($app) {
            return new AIManager($app['config']->get('app-brain', []));
        });

        $this->app->singleton(AppBrainService::class, function ($app) {
            $config = $app['config']->get('app-brain', []);

            // Resolve cache — null when caching is globally disabled so the
            // service never attempts a cache operation.
            $cache = ($config['cache']['enabled'] ?? false)
                ? $app->make(CacheRepository::class)
                : null;

            // Resolve logger — use the package's dedicated channel when set,
            // otherwise fall back to the default application logger.
            $logChannel = $config['log_channel'] ?? null;
            $logger = $logChannel
                ? $app->make('log')->channel($logChannel)
                : $app->make(LoggerInterface::class);

            return new AppBrainService(
                contextBuilder: $app->make(ContextBuilder::class),
                ai: $app->make(AppBrainAIInterface::class),
                config: $config,
                cache: $cache,
                logger: $logger,
            );
        });

        $this->app->singleton(BrainInterface::class, function ($app) {
            return new Brain(
                contextBuilder: $app->make(ContextBuilder::class),
                ai: $app->make(AppBrainAIInterface::class),
                brainService: $app->make(AppBrainService::class),
                config: $app['config']->get('app-brain', []),
            );
        });

        $this->app->singleton(ScannerManager::class, function ($app) {
            $manager = new ScannerManager($app['config']->get('app-brain', []));

            $manager->register(new MigrationScanner(new MigrationParser, new MigrationRepository))
                ->register(new ModelScanner(new ModelParser, new ModelRepository))
                ->register(new RouteScanner(new RouteRepository))
                ->register(new ControllerScanner(new ControllerParser, new ControllerRepository));

            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/app-brain.php' => config_path('app-brain.php'),
        ], 'brain-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'brain-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/brain'),
        ], 'brain-views');

        // Auto-load migrations so they run during tests without publishing.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register Blade views under the "brain::" namespace.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'brain');

        // Register web UI routes when enabled.
        $this->registerUIRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                AskCommand::class,
            ]);
        }
    }

    /**
     * Register the web UI route group.
     *
     * Middleware is driven by config('app-brain.ui.middleware').
     * Default: ['web', 'auth'] — protected by the application's existing auth.
     * Set to ['web'] in config to make the UI publicly accessible.
     */
    private function registerUIRoutes(): void
    {
        $config = $this->app['config']->get('app-brain.ui', []);

        if (empty($config['enabled'])) {
            return;
        }

        $prefix     = $config['prefix'] ?? 'brain';
        $middleware = $config['middleware'] ?? ['web', 'auth'];

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name('brain.')
            ->group(function () {
                Route::get('/', [BrainUIController::class, 'index'])->name('index');
                Route::post('/ask', [BrainUIController::class, 'ask'])->name('ask');
            });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            BrainInterface::class,
            AppBrainAIInterface::class,
            AppBrainService::class,
            ContextBuilder::class,
            ScannerManager::class,
        ];
    }
}
