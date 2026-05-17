<?php

namespace App\Plugins;

use App\Core\Contracts\PluginInterface;
use App\Core\Contracts\PluginLifecycleInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Base Plugin Class
 *
 * All game plugins must extend this class.
 * Implements PluginInterface and provides default implementations.
 * Supports manifest.json parsing, route registration, view loading,
 * and hook integration.
 */
abstract class Plugin implements PluginInterface, PluginLifecycleInterface
{
    /**
     * Plugin manifest data (parsed from plugin.json).
     */
    protected array $manifest = [];

    /**
     * Plugin filesystem path.
     */
    protected string $path;

    /**
     * Plugin identifier (slug).
     */
    protected string $id;

    /**
     * Cached route definitions.
     */
    protected ?array $routes = null;

    /**
     * Cached middleware stack.
     */
    protected ?array $middleware = null;

    /**
     * Constructor.
     *
     * @param string $path The filesystem path to the plugin directory.
     * @throws RuntimeException If plugin.json is missing or invalid.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->loadManifest();
    }

    /**
     * Load and validate the plugin.json manifest.
     *
     * @throws RuntimeException If manifest is missing or invalid.
     */
    protected function loadManifest(): void
    {
        $manifestPath = $this->path . '/plugin.json';

        if (!File::exists($manifestPath)) {
            throw new RuntimeException(
                "Plugin manifest not found: {$manifestPath}"
            );
        }

        $content = File::get($manifestPath);
        $this->manifest = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in plugin manifest: {$manifestPath} - " . json_last_error_msg()
            );
        }

        // Validate required fields
        $required = ['name', 'slug', 'version'];
        foreach ($required as $field) {
            if (empty($this->manifest[$field])) {
                throw new RuntimeException(
                    "Missing required field '{$field}' in plugin manifest: {$manifestPath}"
                );
            }
        }

        $this->id = $this->manifest['slug'];
    }

    // ==========================================
    // PluginInterface Implementation
    // ==========================================

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->manifest['name'] ?? $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(): string
    {
        return $this->manifest['version'] ?? '1.0.0';
    }

    /**
     * {@inheritdoc}
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespace(): string
    {
        // Convert slug to PascalCase namespace
        $pascal = Str::studly(str_replace('-', '_', $this->id));
        return "App\\Plugins\\{$pascal}";
    }

    /**
     * {@inheritdoc}
     */
    public function register(): void
    {
        // Default implementation - override in child classes
        // This is called during Laravel's "register" phase
        // Register services, config, etc. here
    }

    /**
     * {@inheritdoc}
     */
    public function boot(): void
    {
        // Default implementation - override in child classes
        // This is called during Laravel's "boot" phase
        // Register hooks, event listeners, etc. here
        $this->registerHooks();
    }

    /**
     * {@inheritdoc}
     */
    public function getRoutes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $this->routes = [];
        $routeConfig = $this->manifest['routes'] ?? [];

        $routeTypes = [
            'web' => ['middleware' => ['web', 'auth']],
            'api' => ['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api'],
            'admin' => ['middleware' => ['web', 'auth', 'admin'], 'prefix' => 'admin'],
        ];

        foreach ($routeTypes as $type => $defaults) {
            if (!empty($routeConfig[$type])) {
                $routePath = $this->path . '/routes/' . $type . '.php';
                if (File::exists($routePath)) {
                    $this->routes[$type] = array_merge($defaults, [
                        'path' => $routePath,
                        'file' => $type . '.php',
                    ]);
                }
            }
        }

        return $this->routes;
    }

    /**
     * {@inheritdoc}
     */
    public function getMiddleware(): array
    {
        if ($this->middleware !== null) {
            return $this->middleware;
        }

        $this->middleware = $this->manifest['middleware'] ?? [];
        return $this->middleware;
    }

    /**
     * {@inheritdoc}
     */
    public function getDependencies(): array
    {
        return (array) ($this->manifest['requires']['plugins'] ?? []);
    }

    /**
     * {@inheritdoc}
     */
    public function getViewNamespace(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getMigrationsPath(): string
    {
        return $this->path . '/database/migrations';
    }

    /**
     * {@inheritdoc}
     */
    public function getFrontendSlots(): array
    {
        return $this->manifest['frontend']['slots'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function requiresLicense(): bool
    {
        return $this->manifest['license_required'] ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissions(): array
    {
        return $this->manifest['permissions'] ?? [];
    }

    // ==========================================
    // PluginLifecycleInterface Implementation
    // ==========================================

    /**
     * {@inheritdoc}
     * Called once when a plugin is first installed.
     */
    public function install(): void
    {
        // Default: no action. Override in child classes.
        Log::info("Plugin '{$this->id}' installed.", ['version' => $this->getVersion()]);
    }

    /**
     * {@inheritdoc}
     * Called when a plugin is enabled.
     */
    public function enable(): void
    {
        // Default: no action. Override in child classes.
        Log::info("Plugin '{$this->id}' enabled.", ['version' => $this->getVersion()]);
    }

    /**
     * {@inheritdoc}
     * Called when a plugin is disabled.
     */
    public function disable(): void
    {
        // Default: no action. Override in child classes.
        Log::info("Plugin '{$this->id}' disabled.");
    }

    /**
     * {@inheritdoc}
     * Called when a plugin is fully removed.
     */
    public function uninstall(): void
    {
        // Default: no action. Override in child classes.
        Log::info("Plugin '{$this->id}' uninstalled.");
    }

    /**
     * {@inheritdoc}
     * Called when upgrading versions.
     */
    public function upgrade(string $fromVersion, string $toVersion): void
    {
        // Default: no action. Override in child classes.
        Log::info("Plugin '{$this->id}' upgraded.", [
            'from' => $fromVersion,
            'to' => $toVersion,
        ]);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Register hooks from the plugin's hooks.php file.
     */
    protected function registerHooks(): void
    {
        $hooksFile = $this->path . '/hooks.php';

        if (File::exists($hooksFile)) {
            // The hooks file should use the Hook facade to register callbacks
            require $hooksFile;
        }
    }

    /**
     * Get a configuration value from the plugin's manifest.
     *
     * @param string $key Dot-notation key (e.g., 'settings.icon')
     * @param mixed $default Default value if not found.
     * @return mixed
     */
    public function config(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->manifest;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get the plugin's assets URL.
     *
     * @param string $asset Relative path to asset.
     * @return string Public URL to the asset.
     */
    public function assetUrl(string $asset = ''): string
    {
        $base = asset('plugins/' . $this->id);
        return $asset ? "{$base}/{$asset}" : $base;
    }

    /**
     * Check if this plugin has database migrations.
     */
    public function hasMigrations(): bool
    {
        $path = $this->getMigrationsPath();
        return File::isDirectory($path) && count(File::files($path)) > 0;
    }

    /**
     * Check if this plugin has views.
     */
    public function hasViews(): bool
    {
        return File::isDirectory($this->path . '/views');
    }

    /**
     * Check if this plugin has translations.
     */
    public function hasTranslations(): bool
    {
        return File::isDirectory($this->path . '/lang');
    }

    /**
     * Get menu items defined by this plugin.
     */
    public function getMenuItems(): array
    {
        $menuConfig = $this->manifest['settings']['menu'] ?? [];

        if (empty($menuConfig['enabled'] ?? false)) {
            return [];
        }

        return [
            'section' => $menuConfig['section'] ?? 'main',
            'order' => $menuConfig['order'] ?? 100,
            'parent' => $menuConfig['parent'] ?? null,
            'icon' => $this->manifest['settings']['icon'] ?? null,
            'color' => $this->manifest['settings']['color'] ?? null,
            'route' => $this->manifest['settings']['route'] ?? null,
            'title' => $this->getName(),
        ];
    }

    /**
     * Get admin settings defined by this plugin.
     *
     * Plugins can define admin settings in their plugin.json:
     * {
     *   "admin_settings": {
     *     "combat": {
     *       "label": "Combat",
     *       "icon": "FireIcon",
     *       "order": 10,
     *       "settings": {
     *         "attack_cooldown": {
     *           "type": "number",
     *           "label": "Attack Cooldown (seconds)",
     *           "default": 300,
     *           "description": "Cooldown between attacks"
     *         }
     *       }
     *     }
     *   }
     * }
     */
    public function getAdminSettings(): array
    {
        $adminSettings = $this->manifest['admin_settings'] ?? [];

        // Prefix all setting keys with plugin slug to avoid collisions
        $prefixedSettings = [];

        foreach ($adminSettings as $groupId => $groupConfig) {
            $settings = $groupConfig['settings'] ?? [];
            $prefixedGroupSettings = [];

            foreach ($settings as $key => $config) {
                // Use plugin-prefixed key for storage, but keep original for display
                $prefixedKey = "plugin.{$this->id}.{$key}";
                $prefixedGroupSettings[$prefixedKey] = array_merge($config, [
                    'original_key' => $key,
                    'plugin_id' => $this->id,
                ]);
            }

            $prefixedSettings[$groupId] = array_merge($groupConfig, [
                'settings' => $prefixedGroupSettings,
                'plugin_id' => $this->id,
            ]);
        }

        return $prefixedSettings;
    }

    /**
     * Resolve a class within this plugin's namespace.
     *
     * @param string $className Class name without namespace.
     * @return string|null Fully qualified class name if it exists.
     */
    public function resolveClass(string $className): ?string
    {
        $fqcn = $this->getNamespace() . '\\' . $className;
        return class_exists($fqcn) ? $fqcn : null;
    }

    /**
     * Get a model instance from this plugin.
     *
     * @param string $modelName Model name without namespace.
     * @return string|null Model class name if it exists.
     */
    public function getModelClass(string $modelName): ?string
    {
        return $this->resolveClass('Models\\' . $modelName);
    }

    /**
     * Get a controller instance from this plugin.
     *
     * @param string $controllerName Controller name without namespace.
     * @return string|null Controller class name if it exists.
     */
    public function getControllerClass(string $controllerName): ?string
    {
        return $this->resolveClass('Controllers\\' . $controllerName);
    }

    /**
     * Broadcast an event to this plugin's WebSocket channel.
     *
     * @param string $event Event name.
     * @param array $data Event payload.
     */
    public function broadcast(string $event, array $data = []): void
    {
        if (function_exists('broadcastToPlugin')) {
            broadcastToPlugin($this->id, $event, $data);
        }
    }

    /**
     * Log a plugin-specific message.
     *
     * @param string $level Log level (debug, info, warning, error).
     * @param string $message Log message.
     * @param array $context Additional context.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        Log::{$level}("[Plugin:{$this->id}] {$message}", $context);
    }

    /**
     * Get a setting value for this plugin.
     *
     * @param string $key Setting key.
     * @param mixed $default Default value.
     * @return mixed
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        if (!class_exists(\App\Core\Services\SettingService::class)) {
            return $default;
        }

        return app(\App\Core\Services\SettingService::class)->get(
            "plugins.{$this->id}.{$key}",
            $default
        );
    }

    /**
     * Set a setting value for this plugin.
     *
     * @param string $key Setting key.
     * @param mixed $value Setting value.
     */
    public function setSetting(string $key, mixed $value): void
    {
        if (class_exists(\App\Core\Services\SettingService::class)) {
            app(\App\Core\Services\SettingService::class)->set(
                "plugins.{$this->id}.{$key}",
                $value
            );
        }
    }
}
