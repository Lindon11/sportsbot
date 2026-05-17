<?php

namespace App\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class PluginServiceProvider extends ServiceProvider
{
    /**
     * Discovered plugins
     * @var array
     */
    protected array $plugins = [];

    /**
     * Sorted plugins based on dependencies (load order)
     * @var array
     */
    protected array $sortedPlugins = [];

    /**
     * Register services.
     */
    public function register(): void
    {
        // During unit tests we avoid discovering plugins to keep the test
        // environment isolated (prevents plugin migrations/routes being loaded).
        if ($this->app->runningUnitTests()) {
            return;
        }

        $this->discoverPlugins();
        $this->resolveDependencies();
        $this->registerPluginConfig();
        $this->registerPluginsWithContainer();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            // Skip plugin booting in tests
            return;
        }

        $this->loadPluginRoutes();
        $this->loadPluginViews();
        $this->loadPluginMigrations();
        $this->loadPluginTranslations();
        $this->publishPluginAssets();

        // Register plugins to registry using already-discovered data (no duplicate scan)
        $this->registerPluginsToRegistry();

        // Only share safe plugin metadata with views (not internal paths/namespaces)
        $safePlugins = collect($this->sortedPlugins)->map(fn($p) => [
            'id' => $p['id'] ?? null,
            'name' => $p['name'] ?? $p['id'] ?? null,
            'enabled' => $p['enabled'] ?? true,
            'version' => $p['version'] ?? '1.0.0',
        ])->all();

        View::share('plugins', $safePlugins);
        app()->instance('plugins', $this->sortedPlugins);

        // Backwards compatibility
        View::share('modules', $safePlugins);
        app()->instance('modules', $this->sortedPlugins);
    }

    /**
     * Resolve plugin dependencies and sort plugins in load order.
     * Plugins are sorted so dependencies are loaded first.
     */
    protected function resolveDependencies(): void
    {
        $plugins = $this->plugins;
        $sorted = [];
        $visited = [];
        $visitedWithOrder = [];

        /**
         * Topological sort using DFS
         * @param string $pluginId
         * @param array $stack Stack to track recursion for cycle detection
         * @param int $depth Current depth for logging
         */
        $visit = function (string $pluginId, array &$stack = [], int $depth = 0) use (&$plugins, &$sorted, &$visited, &$visitedWithOrder, &$visit) {
            // Indent for debug logging
            $indent = str_repeat('  ', $depth);

            // Check for circular dependency
            if (in_array($pluginId, $stack)) {
                Log::warning("Plugin '{$pluginId}' has a circular dependency - skipping.");
                return;
            }

            // Skip if already processed
            if (isset($visitedWithOrder[$pluginId])) {
                return;
            }

            $stack[] = $pluginId;

            // Check if plugin exists
            if (!isset($plugins[$pluginId])) {
                Log::debug("{$indent}Plugin '{$pluginId}' not found - skipping.");
                array_pop($stack);
                return;
            }

            $plugin = $plugins[$pluginId];
            $dependencies = $plugin['requires']['plugins'] ?? $plugin['dependencies'] ?? [];

            Log::debug("{$indent}Processing plugin: {$pluginId} (dependencies: " . implode(', ', array_keys($dependencies)) . ")");

            // Visit all dependencies first
            foreach ($dependencies as $depId => $versionConstraint) {
                // Skip if dependency is explicitly disabled
                if (isset($plugins[$depId]) && !($plugins[$depId]['enabled'] ?? true)) {
                    Log::debug("{$indent}  Skipping disabled dependency: {$depId}");
                    continue;
                }

                $visit($depId, $stack, $depth + 1);
            }

            // Mark as visited
            $visited[$pluginId] = true;
            $visitedWithOrder[$pluginId] = true;

            // Add to sorted list
            $sorted[] = $plugin;

            // Get order from plugin (default 100)
            $order = $plugin['order'] ?? 100;

            Log::debug("{$indent}Added {$pluginId} to sorted list (order: {$order})");

            array_pop($stack);
        };

        // Process all plugins
        foreach (array_keys($plugins) as $pluginId) {
            if (!isset($visited[$pluginId])) {
                $visit($pluginId);
            }
        }

        // Secondary sort by 'order' field for plugins at same dependency level
        usort($sorted, function ($a, $b) {
            $orderA = $a['order'] ?? $a['settings']['menu']['order'] ?? 100;
            $orderB = $b['order'] ?? $b['settings']['menu']['order'] ?? 100;
            return $orderA - $orderB;
        });

        $this->sortedPlugins = $sorted;

        // Log resolved order
        $orderList = array_map(fn($p) => $p['id'], $sorted);
        Log::info("Plugin load order resolved: " . implode(' -> ', $orderList));
    }

    /**
     * Register each plugin class with the container for dependency injection.
     */
    protected function registerPluginsWithContainer(): void
    {
        foreach ($this->sortedPlugins as $plugin) {
            $pluginClass = $plugin['class'] ?? null;

            if (!$pluginClass) {
                // Try to find the plugin class automatically
                $namespace = $plugin['namespace'] ?? "App\\Plugins\\{$plugin['id']}";
                $className = $namespace . '\\' . Str::studly($plugin['id']) . 'Plugin';

                if (class_exists($className)) {
                    $pluginClass = $className;
                }
            }

            if ($pluginClass && class_exists($pluginClass)) {
                // Register as singleton with the container
                $this->app->singleton($pluginClass);

                Log::debug("Registered plugin class: {$pluginClass}");
            }
        }
    }

    /**
     * Get a specific plugin class instance from the container.
     *
     * @param string $pluginId
     * @return object|null
     */
    public static function getPluginInstance(string $pluginId): ?object
    {
        $namespace = "App\\Plugins\\" . Str::studly($pluginId);
        $className = $namespace . '\\' . Str::studly($pluginId) . 'Plugin';

        if (class_exists($className) && app()->bound($className)) {
            return app($className);
        }

        return null;
    }

    /**
     * Register plugins to the registry using already-discovered plugin data.
     * Uses $this->plugins instead of re-scanning the filesystem.
     */
    protected function registerPluginsToRegistry(): void
    {
        if (!class_exists(\App\Core\Services\PluginRegistry::class)) {
            return;
        }

        foreach ($this->plugins as $plugin) {
            $configPath = $plugin['path'] . '/plugin.json';

            // Also check for module.json for backwards compatibility
            if (!file_exists($configPath)) {
                $configPath = $plugin['path'] . '/module.json';
            }

            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true);
                if ($config) {
                    \App\Core\Services\PluginRegistry::register($config);
                } else {
                    \Illuminate\Support\Facades\Log::warning("Plugin '{$plugin['id']}' has invalid plugin.json — skipped registry.");
                }
            }
        }
    }

    /**
     * Discover all plugins
     */
    protected function discoverPlugins(): void
    {
        $pluginsPath = app_path('Plugins');

        if (!File::exists($pluginsPath)) {
            File::makeDirectory($pluginsPath, 0755, true);
            return;
        }

        $pluginDirs = File::directories($pluginsPath);

        foreach ($pluginDirs as $pluginDir) {
            $pluginName = basename($pluginDir);

            // Skip the base Plugin.php file
            if ($pluginName === 'Plugin.php') {
                continue;
            }

            $pluginJsonPath = $pluginDir . '/plugin.json';

            // Also check for module.json for backwards compatibility
            if (!File::exists($pluginJsonPath)) {
                $pluginJsonPath = $pluginDir . '/module.json';
            }

            if (File::exists($pluginJsonPath)) {
                $pluginData = json_decode(File::get($pluginJsonPath), true);

                if ($pluginData === null && json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning("Plugin '{$pluginName}' has malformed JSON in plugin.json — skipped.", [
                        'error' => json_last_error_msg(),
                        'path' => $pluginJsonPath,
                    ]);
                    continue;
                }

                $this->plugins[$pluginName] = array_merge([
                    'id' => $pluginName,
                    'path' => $pluginDir,
                    'namespace' => "App\\Plugins\\{$pluginName}",
                    'enabled' => true,
                ], $pluginData ?? []);
            }
        }
    }

    /**
     * Register plugin configuration
     */
    protected function registerPluginConfig(): void
    {
        foreach ($this->plugins as $plugin) {
            $configPath = $plugin['path'] . '/config.php';

            if (File::exists($configPath)) {
                $this->mergeConfigFrom($configPath, 'plugins.' . $plugin['id']);
            }
        }
    }

    /**
     * Load plugin routes
     */
    protected function loadPluginRoutes(): void
    {
        foreach ($this->plugins as $plugin) {
            if (!($plugin['enabled'] ?? true)) {
                continue;
            }

            // Web routes
            $webRoutesPath = $plugin['path'] . '/routes/web.php';
            if (File::exists($webRoutesPath)) {
                Route::middleware('web')
                    ->namespace($plugin['namespace'] . '\\Controllers')
                    ->group($webRoutesPath);
            }

            // API routes
            $apiRoutesPath = $plugin['path'] . '/routes/api.php';
            if (File::exists($apiRoutesPath)) {
                Route::prefix('api')
                    ->middleware('api')
                    ->namespace($plugin['namespace'] . '\\Controllers')
                    ->group($apiRoutesPath);
            }

            // Admin routes - loaded under api/v1/admin for API access
            $adminRoutesPath = $plugin['path'] . '/routes/admin.php';
            if (File::exists($adminRoutesPath)) {
                Route::prefix('api/v1/admin')
                    ->middleware(['api', 'auth:sanctum', 'role:admin|moderator', 'verify.license'])
                    ->namespace($plugin['namespace'] . '\\Controllers\\Admin')
                    ->name('admin.')
                    ->group($adminRoutesPath);
            }
        }
    }

    /**
     * Load plugin views
     */
    protected function loadPluginViews(): void
    {
        foreach ($this->plugins as $plugin) {
            $viewsPath = $plugin['path'] . '/views';

            if (File::exists($viewsPath)) {
                $this->loadViewsFrom($viewsPath, $plugin['id']);
            }
        }
    }

    /**
     * Load plugin migrations
     */
    protected function loadPluginMigrations(): void
    {
        // Avoid loading plugin migrations during unit tests; the test
        // harness (RefreshDatabase) controls migrations to ensure isolation.
        if ($this->app->runningUnitTests()) {
            return;
        }
        foreach ($this->plugins as $plugin) {
            $migrationsPath = $plugin['path'] . '/database/migrations';

            if (File::exists($migrationsPath)) {
                $this->loadMigrationsFrom($migrationsPath);
            }
        }
    }

    /**
     * Load plugin translations
     */
    protected function loadPluginTranslations(): void
    {
        foreach ($this->plugins as $plugin) {
            $langPath = $plugin['path'] . '/lang';

            if (File::exists($langPath)) {
                $this->loadTranslationsFrom($langPath, $plugin['id']);
            }
        }
    }

    /**
     * Publish plugin assets
     */
    protected function publishPluginAssets(): void
    {
        foreach ($this->plugins as $plugin) {
            $assetsPath = $plugin['path'] . '/assets';

            if (File::exists($assetsPath)) {
                $this->publishes([
                    $assetsPath => public_path('plugins/' . $plugin['id']),
                ], 'plugin-' . $plugin['id'] . '-assets');
            }
        }
    }

    /**
     * Get all enabled plugins
     */
    public static function getPlugins(): array
    {
        return app('plugins') ?? [];
    }

    /**
     * Get a specific plugin
     */
    public static function getPlugin(string $name): ?array
    {
        $plugins = self::getPlugins();
        return $plugins[$name] ?? null;
    }

    /**
     * Check if plugin is enabled
     */
    public static function isEnabled(string $name): bool
    {
        $plugin = self::getPlugin($name);
        return $plugin && ($plugin['enabled'] ?? true);
    }

    // Backwards compatibility static methods
    public static function getModules(): array
    {
        return self::getPlugins();
    }

    public static function getModule(string $name): ?array
    {
        return self::getPlugin($name);
    }
}
