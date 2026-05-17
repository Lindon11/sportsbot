<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Services\MarketplaceClient;
use App\Core\Services\PluginManagerService;
use App\Core\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Marketplace API Controller
 *
 * Provides endpoints for the frontend to interact with the marketplace,
 * sync plugins, and manage installations.
 */
class MarketplaceController extends Controller
{
    protected MarketplaceClient $marketplace;
    protected PluginManagerService $pluginManager;

    public function __construct(MarketplaceClient $marketplace, PluginManagerService $pluginManager)
    {
        $this->marketplace = $marketplace;
        $this->pluginManager = $pluginManager;
    }

    /**
     * Get available plugins from the marketplace.
     *
     * GET /api/marketplace/plugins
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => 'nullable|string',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $plugins = $this->marketplace->getAvailablePlugins($filters);

        return response()->json([
            'success' => true,
            'data' => $plugins,
            'marketplace_available' => $this->marketplace->isAvailable(),
        ]);
    }

    /**
     * Get details for a specific plugin.
     *
     * GET /api/marketplace/plugins/{pluginId}
     */
    public function show(string $pluginId): JsonResponse
    {
        $plugin = $this->marketplace->getPluginDetails($pluginId);

        if (!$plugin) {
            return response()->json([
                'success' => false,
                'message' => 'Plugin not found in marketplace.',
            ], 404);
        }

        // Check if plugin is already installed
        $installedPlugin = \App\Core\Models\InstalledPlugin::where('slug', $pluginId)->first();
        $plugin['installed'] = !is_null($installedPlugin);
        $plugin['installed_version'] = $installedPlugin?->version;
        $plugin['can_install'] = $this->canInstall($plugin);

        return response()->json([
            'success' => true,
            'data' => $plugin,
        ]);
    }

    /**
     * Sync with the marketplace.
     *
     * POST /api/marketplace/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $licenseKey = $request->input('license_key') ?? LicenseService::getStoredKey();

        $result = $this->marketplace->sync($licenseKey);

        return response()->json($result);
    }

    /**
     * Get the last sync result.
     *
     * GET /api/marketplace/sync/status
     */
    public function syncStatus(): JsonResponse
    {
        $result = $this->marketplace->getLastSyncResult();

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Download and install a plugin from the marketplace.
     *
     * POST /api/marketplace/plugins/{pluginId}/install
     */
    public function install(string $pluginId): JsonResponse
    {
        // Get plugin details first
        $plugin = $this->marketplace->getPluginDetails($pluginId);

        if (!$plugin) {
            return response()->json([
                'success' => false,
                'message' => 'Plugin not found in marketplace.',
            ], 404);
        }

        // Check if can install
        if (!$this->canInstall($plugin)) {
            return response()->json([
                'success' => false,
                'message' => 'This plugin requires a valid license.',
            ], 402);
        }

        // Verify purchase if required
        if ($plugin['license_required'] ?? false) {
            if (!$this->marketplace->verifyPurchase($pluginId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have a valid license for this plugin.',
                ], 403);
            }
        }

        // Download the plugin
        $downloadResult = $this->marketplace->downloadPlugin($pluginId);

        if (!$downloadResult['success']) {
            return response()->json($downloadResult, 500);
        }

        // In dev mode, the file won't actually exist
        if (!isset($downloadResult['path']) || !file_exists($downloadResult['path'])) {
            return response()->json([
                'success' => false,
                'message' => $downloadResult['message'] ?? 'Download failed.',
                'dev_mode' => true,
            ]);
        }

        // Install using PluginManagerService
        $installResult = $this->pluginManager->installPlugin($pluginId);

        return response()->json($installResult, $installResult['success'] ? 200 : 500);
    }

    /**
     * Get available categories.
     *
     * GET /api/marketplace/categories
     */
    public function categories(): JsonResponse
    {
        // In dev mode, return mock categories
        $categories = [
            ['id' => 'gameplay', 'name' => 'Gameplay', 'icon' => '🎮', 'count' => 12],
            ['id' => 'economy', 'name' => 'Economy', 'icon' => '💰', 'count' => 8],
            ['id' => 'social', 'name' => 'Social', 'icon' => '👥', 'count' => 5],
            ['id' => 'admin', 'name' => 'Admin', 'icon' => '⚙️', 'count' => 7],
            ['id' => 'themes', 'name' => 'Themes', 'icon' => '🎨', 'count' => 4],
        ];

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Check if a plugin can be installed.
     *
     * @param array $plugin
     * @return bool
     */
    protected function canInstall(array $plugin): bool
    {
        // Free plugins can always be installed
        if (($plugin['price'] ?? 0) === 0) {
            return true;
        }

        // License required plugins need valid license
        if ($plugin['license_required'] ?? false) {
            return LicenseService::isLicensed();
        }

        return true;
    }
}
