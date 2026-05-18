<?php

namespace App\Core\Services;

use App\Core\Models\InstalledPlugin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

/**
 * PluginManifestService - Consolidates plugin metadata for frontend consumption.
 *
 * This service provides a unified interface for accessing plugin information
 * including routes, components, navigation, and API endpoints.
 */
class PluginManifestService
{
    protected string $pluginsPath;

    public function __construct()
    {
        $this->pluginsPath = app_path('Plugins');
    }

    /**
     * Get all enabled plugins with full manifest data for frontend.
     */
    public function getEnabledPluginsForFrontend(): Collection
    {
        return Cache::remember('plugins.frontend_manifest', 60, function () {
            $plugins = InstalledPlugin::plugins()
                ->where('enabled', true)
                ->orderBy('order')
                ->get();

            return $plugins->map(function ($plugin) {
                return $this->buildPluginManifest($plugin);
            })->filter();
        });
    }

    /**
     * Get a single plugin manifest by slug.
     */
    public function getPluginManifest(string $slug): ?array
    {
        $plugin = InstalledPlugin::where('slug', $slug)
            ->where('enabled', true)
            ->first();

        if (!$plugin) {
            return null;
        }

        return $this->buildPluginManifest($plugin);
    }

    /**
     * Get all available routes from enabled plugins.
     */
    public function getPluginRoutes(): array
    {
        $routes = [];

        foreach ($this->getEnabledPluginsForFrontend() as $plugin) {
            if (!empty($plugin['frontend_routes'])) {
                foreach ($plugin['frontend_routes'] as $route) {
                    $routes[] = [
                        'plugin' => $plugin['slug'],
                        'path' => $route['path'],
                        'name' => $route['name'] ?? null,
                        'component' => $route['component'] ?? null,
                        'meta' => $route['meta'] ?? [],
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * Get navigation items for frontend menu generation.
     */
    public function getNavigationItems(): array
    {
        $navigation = [];

        foreach ($this->getEnabledPluginsForFrontend() as $plugin) {
            if ($plugin['navigation']['enabled'] ?? false) {
                $navigation[] = [
                    'slug' => $plugin['slug'],
                    'name' => $plugin['name'],
                    'icon' => $plugin['icon'],
                    'color' => $plugin['color'],
                    'route' => $plugin['route_name'],
                    'section' => $plugin['navigation']['section'] ?? 'main',
                    'order' => $plugin['navigation']['order'] ?? $plugin['order'] ?? 100,
                ];
            }
        }

        // Sort by section and order
        usort($navigation, function ($a, $b) {
            $sectionCompare = strcmp($a['section'], $b['section']);
            if ($sectionCompare !== 0) {
                return $sectionCompare;
            }
            return $a['order'] <=> $b['order'];
        });

        return $navigation;
    }

    /**
     * Get API routes for enabled plugins.
     */
    public function getApiRoutes(): array
    {
        $apiRoutes = [];

        foreach ($this->getEnabledPluginsForFrontend() as $plugin) {
            if ($plugin['has_api_routes']) {
                $apiRoutes[] = [
                    'plugin' => $plugin['slug'],
                    'prefix' => "/api/v1/{$plugin['slug']}",
                ];
            }
        }

        return $apiRoutes;
    }

    /**
     * Build complete plugin manifest from database and filesystem.
     */
    protected function buildPluginManifest(InstalledPlugin $plugin): array
    {
        // Load plugin.json for additional frontend metadata
        $pluginJson = $this->loadPluginJson($plugin->slug);

        // Get navigation configuration
        $navigation = $this->buildNavigationConfig($plugin, $pluginJson);

        // Get frontend routes
        $frontendRoutes = $this->buildFrontendRoutes($plugin, $pluginJson);

        return [
            // Core info
            'slug' => $plugin->slug,
            'name' => $plugin->name,
            'version' => $plugin->version,
            'description' => $plugin->description,

            // Frontend display
            'icon' => $plugin->icon ?? ($pluginJson['settings']['icon'] ?? null),
            'color' => $plugin->color ?? ($pluginJson['settings']['color'] ?? null),

            // Routing
            'route_name' => $plugin->route_name ?? ($pluginJson['settings']['route'] ?? null),
            'frontend_routes' => $frontendRoutes,

            // Navigation
            'navigation' => $navigation,
            'order' => $plugin->order ?? ($pluginJson['settings']['menu']['order'] ?? 100),

            // API
            'has_api_routes' => $plugin->has_api_routes ?? ($pluginJson['routes']['api'] ?? false),
            'has_web_routes' => $plugin->has_web_routes ?? ($pluginJson['routes']['web'] ?? false),
            'has_admin_routes' => $plugin->has_admin_routes ?? ($pluginJson['routes']['admin'] ?? false),

            // Frontend integration
            'frontend_slots' => $plugin->frontend_slots ?? ($pluginJson['frontend']['slots'] ?? []),

            // Permissions
            'permissions' => $plugin->permissions ?? ($pluginJson['permissions'] ?? []),
        ];
    }

    /**
     * Build navigation configuration from plugin data.
     */
    protected function buildNavigationConfig(InstalledPlugin $plugin, ?array $pluginJson): array
    {
        $config = $plugin->config ?? [];
        $menuSettings = $config['menu'] ?? ($pluginJson['settings']['menu'] ?? []);

        if (empty($menuSettings)) {
            return ['enabled' => false];
        }

        return [
            'enabled' => $menuSettings['enabled'] ?? true,
            'section' => $menuSettings['section'] ?? 'main',
            'order' => $menuSettings['order'] ?? $plugin->order ?? 100,
            'parent' => $menuSettings['parent'] ?? null,
        ];
    }

    /**
     * Build frontend routes from plugin configuration.
     */
    protected function buildFrontendRoutes(InstalledPlugin $plugin, ?array $pluginJson): array
    {
        $frontendConfig = $pluginJson['frontend'] ?? [];
        $manifestRoutes = $frontendConfig['routes'] ?? [];
        $databaseRoutes = $plugin->frontend_routes ?? [];
        $routes = $this->mergeFrontendRoutes($databaseRoutes, $manifestRoutes);

        // Auto-generate from route_name if no explicit routes defined
        if (empty($routes) && $plugin->route_name) {
            $routePath = $this->routeNameToPath($plugin->route_name);
            $componentName = $this->slugToComponentName($plugin->slug);

            $routes = [
                [
                    'path' => $routePath,
                    'name' => $plugin->slug,
                    'component' => $componentName,
                    'meta' => [
                        'title' => $plugin->name,
                    ],
                ],
            ];
        }

        return $routes;
    }

    /**
     * Merge DB-stored routes with plugin.json routes.
     *
     * Installed plugin rows can lag behind the checked-out repo after an update.
     * Keeping manifest routes in the response lets newly shipped frontend pages
     * become available without a manual plugin reinstall.
     */
    protected function mergeFrontendRoutes(array $databaseRoutes, array $manifestRoutes): array
    {
        $routes = [];

        foreach (array_merge($databaseRoutes, $manifestRoutes) as $route) {
            if (!is_array($route)) {
                continue;
            }

            $path = trim((string) ($route['path'] ?? ''));
            $name = trim((string) ($route['name'] ?? ''));

            if ($path === '' && $name === '') {
                continue;
            }

            $key = $path !== '' ? 'path:' . $path : 'name:' . $name;
            $routes[$key] = $route;
        }

        return array_values($routes);
    }

    /**
     * Load plugin.json from filesystem.
     */
    protected function loadPluginJson(string $slug): ?array
    {
        // Find the actual plugin directory (case-insensitive)
        $actualDir = $this->findPluginDirectory($slug);
        if (!$actualDir) {
            return null;
        }

        $pluginJsonPath = $this->pluginsPath . '/' . $actualDir . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return null;
        }

        $content = File::get($pluginJsonPath);
        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Find plugin directory by slug (case-insensitive).
     */
    protected function findPluginDirectory(string $slug): ?string
    {
        if (!File::exists($this->pluginsPath)) {
            return null;
        }

        $directories = File::directories($this->pluginsPath);
        $slugLower = strtolower($slug);

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            if (strtolower($dirName) === $slugLower) {
                return $dirName;
            }
        }

        return null;
    }

    /**
     * Convert route name to path (e.g., 'combat.index' -> '/combat').
     */
    protected function routeNameToPath(string $routeName): string
    {
        // Remove common suffixes
        $path = preg_replace('/\.(index|show|list|main)$/', '', $routeName);

        // Convert dots to slashes
        $path = str_replace('.', '/', $path);

        // Ensure leading slash
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * Convert plugin slug to component name (e.g., 'organized-crime' -> 'OrganizedCrimeView').
     */
    protected function slugToComponentName(string $slug): string
    {
        $parts = explode('-', $slug);
        $componentName = implode('', array_map('ucfirst', $parts));

        return $componentName . 'View';
    }

    /**
     * Clear the plugin manifest cache.
     */
    public function clearCache(): void
    {
        Cache::forget('plugins.frontend_manifest');
    }

    /**
     * Get plugins grouped by section for navigation.
     */
    public function getPluginsBySection(): array
    {
        $sections = [];

        foreach ($this->getNavigationItems() as $item) {
            $section = $item['section'] ?? 'main';

            if (!isset($sections[$section])) {
                $sections[$section] = [];
            }

            $sections[$section][] = $item;
        }

        return $sections;
    }
}
