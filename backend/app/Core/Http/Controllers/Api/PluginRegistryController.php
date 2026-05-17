<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Models\InstalledPlugin;
use App\Core\Services\PluginRegistry;
use App\Core\Services\PluginManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

/**
 * Plugin Registry API Controller
 *
 * Provides endpoints for the frontend to discover active plugins,
 * their frontend slots, and component registrations.
 */
class PluginRegistryController extends Controller
{
    protected PluginManagerService $pluginManager;

    public function __construct(PluginManagerService $pluginManager)
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Get all active plugins with their frontend configurations.
     *
     * GET /api/core/plugins
     */
    public function index(): JsonResponse
    {
        $plugins = InstalledPlugin::where('enabled', true)
            ->get()
            ->map(function ($plugin) {
                return $this->formatPluginForFrontend($plugin);
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $plugins,
        ]);
    }

    /**
     * Get a specific plugin's configuration.
     *
     * GET /api/core/plugins/{pluginId}
     */
    public function show(string $pluginId): JsonResponse
    {
        $plugin = InstalledPlugin::where('slug', $pluginId)
            ->where('enabled', true)
            ->first();

        if (!$plugin) {
            return response()->json([
                'success' => false,
                'message' => 'Plugin not found or not active.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatPluginForFrontend($plugin),
        ]);
    }

    /**
     * Get all registered frontend slots and their components.
     *
     * GET /api/core/plugins/slots
     */
    public function slots(): JsonResponse
    {
        $slots = [];
        $plugins = InstalledPlugin::where('enabled', true)->get();

        foreach ($plugins as $plugin) {
            $frontendSlots = $this->getPluginFrontendSlots($plugin->slug);

            foreach ($frontendSlots as $slotName => $components) {
                if (!isset($slots[$slotName])) {
                    $slots[$slotName] = [];
                }

                foreach ($components as $component) {
                    $slots[$slotName][] = [
                        'plugin_id' => $plugin->slug,
                        'plugin_name' => $plugin->name,
                        'component' => $component,
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $slots,
        ]);
    }

    /**
     * Get components for a specific slot.
     *
     * GET /api/core/plugins/slots/{slotName}
     */
    public function slotComponents(string $slotName): JsonResponse
    {
        $components = [];
        $plugins = InstalledPlugin::where('enabled', true)->get();

        foreach ($plugins as $plugin) {
            $frontendSlots = $this->getPluginFrontendSlots($plugin->slug);

            if (isset($frontendSlots[$slotName])) {
                foreach ($frontendSlots[$slotName] as $component) {
                    $components[] = [
                        'plugin_id' => $plugin->slug,
                        'plugin_name' => $plugin->name,
                        'component' => $component,
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'slot' => $slotName,
            'data' => $components,
        ]);
    }

    /**
     * Check if a plugin is active.
     *
     * GET /api/core/plugins/{pluginId}/active
     */
    public function isActive(string $pluginId): JsonResponse
    {
        $active = InstalledPlugin::where('slug', $pluginId)
            ->where('enabled', true)
            ->exists();

        return response()->json([
            'success' => true,
            'plugin_id' => $pluginId,
            'active' => $active,
        ]);
    }

    /**
     * Get plugin permissions for the current user.
     *
     * GET /api/core/plugins/permissions
     */
    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();
        $permissions = [];

        $plugins = InstalledPlugin::where('enabled', true)->get();

        foreach ($plugins as $plugin) {
            $manifest = $this->getPluginManifest($plugin->slug);
            $pluginPerms = $manifest['permissions'] ?? [];

            foreach ($pluginPerms as $perm => $description) {
                $permissions[$perm] = [
                    'plugin' => $plugin->slug,
                    'description' => $description,
                    'has_permission' => $user?->can($perm) ?? false,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Get plugin menu items for navigation.
     *
     * GET /api/core/plugins/menus
     */
    public function menus(Request $request): JsonResponse
    {
        $menus = [];
        $plugins = InstalledPlugin::where('enabled', true)->get();

        foreach ($plugins as $plugin) {
            $manifest = $this->getPluginManifest($plugin->slug);
            $menuConfig = $manifest['settings']['menu'] ?? [];

            if (!empty($menuConfig['enabled'])) {
                $section = $menuConfig['section'] ?? 'main';

                if (!isset($menus[$section])) {
                    $menus[$section] = [];
                }

                $menus[$section][] = [
                    'plugin_id' => $plugin->slug,
                    'title' => $plugin->name,
                    'icon' => $manifest['settings']['icon'] ?? null,
                    'color' => $manifest['settings']['color'] ?? null,
                    'route' => $manifest['settings']['route'] ?? null,
                    'order' => $menuConfig['order'] ?? 100,
                    'parent' => $menuConfig['parent'] ?? null,
                ];
            }
        }

        // Sort menus by order
        foreach ($menus as $section => $items) {
            usort($menus[$section], fn($a, $b) => $a['order'] <=> $b['order']);
        }

        return response()->json([
            'success' => true,
            'data' => $menus,
        ]);
    }

    /**
     * Format a plugin for frontend consumption.
     *
     * @param InstalledPlugin $plugin
     * @return array
     */
    protected function formatPluginForFrontend(InstalledPlugin $plugin): array
    {
        $manifest = $this->getPluginManifest($plugin->slug);

        return [
            'id' => $plugin->slug,
            'name' => $plugin->name,
            'version' => $plugin->version,
            'description' => $plugin->description,
            'enabled' => $plugin->enabled,
            'installed_at' => $plugin->installed_at?->toIso8601String(),
            'icon' => $manifest['settings']['icon'] ?? null,
            'color' => $manifest['settings']['color'] ?? null,
            'slots' => $this->getPluginFrontendSlots($plugin->slug),
            'routes' => $this->getPluginFrontendRoutes($plugin->slug),
            'settings' => $manifest['settings'] ?? [],
        ];
    }

    /**
     * Get plugin manifest from filesystem.
     *
     * @param string $pluginId
     * @return array
     */
    protected function getPluginManifest(string $pluginId): array
    {
        // Try different casing variations
        $paths = [
            app_path('Plugins/' . ucfirst($pluginId) . '/plugin.json'),
            app_path('Plugins/' . $pluginId . '/plugin.json'),
            app_path('Plugins/' . strtolower($pluginId) . '/plugin.json'),
        ];

        foreach ($paths as $path) {
            if (File::exists($path)) {
                $content = File::get($path);
                return json_decode($content, true) ?? [];
            }
        }

        return [];
    }

    /**
     * Get frontend slots defined by a plugin.
     *
     * @param string $pluginId
     * @return array
     */
    protected function getPluginFrontendSlots(string $pluginId): array
    {
        $manifest = $this->getPluginManifest($pluginId);
        return $manifest['frontend']['slots'] ?? [];
    }

    /**
     * Get frontend routes defined by a plugin.
     *
     * @param string $pluginId
     * @return array
     */
    protected function getPluginFrontendRoutes(string $pluginId): array
    {
        $manifest = $this->getPluginManifest($pluginId);
        $frontendRoutes = $manifest['frontend']['routes'] ?? [];

        // Add plugin prefix to routes
        return array_map(function ($route) use ($pluginId) {
            if (!isset($route['path'])) {
                return $route;
            }

            // Ensure path starts with /p/{plugin}
            if (!str_starts_with($route['path'], '/p/')) {
                $route['path'] = '/p/' . $pluginId . '/' . ltrim($route['path'], '/');
            }

            return $route;
        }, $frontendRoutes);
    }
}
