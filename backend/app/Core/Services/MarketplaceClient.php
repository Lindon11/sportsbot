<?php

namespace App\Core\Services;

use App\Core\Models\InstalledPlugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Marketplace Client Service
 *
 * Handles communication with the marketplace API for plugin discovery,
 * syncing, and installation.
 *
 * In development mode, this returns mock data.
 */
class MarketplaceClient
{
    /**
     * Marketplace API base URL.
     */
    protected string $apiUrl;

    /**
     * Development mode flag.
     */
    protected bool $devMode;

    /**
     * Cache TTL for API responses.
     */
    protected int $cacheTtl = 3600;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->apiUrl = config('plugins.marketplace.url', env('MARKETPLACE_URL', 'https://marketplace.example.com/api'));
        $this->devMode = config('plugins.marketplace.dev_mode', true); // Default to dev mode
    }

    /**
     * Get available plugins from the marketplace.
     *
     * @param array $filters Optional filters (category, search, etc.)
     * @return array
     */
    public function getAvailablePlugins(array $filters = []): array
    {
        if ($this->devMode) {
            return $this->getMockPlugins($filters);
        }

        try {
            $response = Http::timeout(10)
                ->get($this->apiUrl . '/plugins', $filters);

            if ($response->successful()) {
                return $response->json('data', []);
            }
        } catch (\Exception $e) {
            Log::warning('Marketplace API unavailable', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Sync installed plugins with the marketplace.
     *
     * @param string|null $licenseKey Optional license key override.
     * @return array Sync result with available updates.
     */
    public function sync(?string $licenseKey = null): array
    {
        $licenseKey = $licenseKey ?? LicenseService::getStoredKey();

        if ($this->devMode) {
            return $this->getMockSyncResult($licenseKey);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $licenseKey,
                    'Accept' => 'application/json',
                ])
                ->post($this->apiUrl . '/sync', [
                    'domain' => request()->getHost(),
                    'installed_plugins' => $this->getInstalledPluginList(),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Store sync result
                $this->storeSyncResult($data);

                return $data;
            }
        } catch (\Exception $e) {
            Log::error('Marketplace sync failed', ['error' => $e->getMessage()]);
        }

        return [
            'success' => false,
            'message' => 'Unable to connect to marketplace.',
        ];
    }

    /**
     * Download a plugin from the marketplace.
     *
     * @param string $pluginId Plugin identifier.
     * @param string|null $licenseKey Optional license key override.
     * @return array Download result with path to zip file.
     */
    public function downloadPlugin(string $pluginId, ?string $licenseKey = null): array
    {
        $licenseKey = $licenseKey ?? LicenseService::getStoredKey();

        if ($this->devMode) {
            return $this->getMockDownloadResult($pluginId);
        }

        try {
            // Request a temporary download token
            $tokenResponse = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $licenseKey,
                ])
                ->post($this->apiUrl . '/plugins/' . $pluginId . '/download-token');

            if (!$tokenResponse->successful()) {
                return [
                    'success' => false,
                    'message' => $tokenResponse->json('message', 'Failed to get download token.'),
                ];
            }

            $downloadToken = $tokenResponse->json('token');

            // Download the plugin
            $downloadUrl = $this->apiUrl . '/plugins/' . $pluginId . '/download?token=' . $downloadToken;
            $response = Http::timeout(300)->get($downloadUrl);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Failed to download plugin.',
                ];
            }

            // Save to temp location
            $tempPath = storage_path('plugins/downloads/' . $pluginId . '.zip');
            File::ensureDirectoryExists(dirname($tempPath));
            File::put($tempPath, $response->body());

            return [
                'success' => true,
                'path' => $tempPath,
                'plugin_id' => $pluginId,
            ];
        } catch (\Exception $e) {
            Log::error('Plugin download failed', [
                'plugin' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Download failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get plugin details from the marketplace.
     *
     * @param string $pluginId Plugin identifier.
     * @return array|null
     */
    public function getPluginDetails(string $pluginId): ?array
    {
        if ($this->devMode) {
            $plugins = $this->getMockPlugins();
            return collect($plugins)->firstWhere('slug', $pluginId);
        }

        try {
            $response = Http::timeout(10)
                ->get($this->apiUrl . '/plugins/' . $pluginId);

            if ($response->successful()) {
                return $response->json('data');
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get plugin details', [
                'plugin' => $pluginId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Verify a plugin purchase.
     *
     * @param string $pluginId Plugin identifier.
     * @param string|null $licenseKey Optional license key override.
     * @return bool
     */
    public function verifyPurchase(string $pluginId, ?string $licenseKey = null): bool
    {
        $licenseKey = $licenseKey ?? LicenseService::getStoredKey();

        if ($this->devMode) {
            return true; // Always allow in dev mode
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => 'Bearer ' . $licenseKey])
                ->get($this->apiUrl . '/purchases/' . $pluginId . '/verify');

            return $response->successful() && $response->json('valid', false);
        } catch (\Exception $e) {
            Log::warning('Purchase verification failed', [
                'plugin' => $pluginId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Get list of installed plugins for sync.
     *
     * @return array
     */
    protected function getInstalledPluginList(): array
    {
        return InstalledPlugin::query()
            ->get(['slug', 'version', 'enabled'])
            ->map(fn($p) => [
                'slug' => $p->slug,
                'version' => $p->version,
                'enabled' => $p->enabled,
            ])
            ->toArray();
    }

    /**
     * Store sync result for later use.
     *
     * @param array $data
     */
    protected function storeSyncResult(array $data): void
    {
        $path = storage_path('plugins/marketplace_sync.json');
        File::put($path, json_encode(array_merge($data, [
            'synced_at' => now()->toIso8601String(),
        ]), JSON_PRETTY_PRINT));
    }

    /**
     * Get last sync result.
     *
     * @return array|null
     */
    public function getLastSyncResult(): ?array
    {
        $path = storage_path('plugins/marketplace_sync.json');

        if (File::exists($path)) {
            return json_decode(File::get($path), true);
        }

        return null;
    }

    /**
     * Get mock plugins for development.
     *
     * @param array $filters
     * @return array
     */
    protected function getMockPlugins(array $filters = []): array
    {
        $plugins = [
            [
                'id' => 'mini-rpg',
                'slug' => 'mini-rpg',
                'name' => 'Mini RPG',
                'version' => '1.0.0',
                'description' => 'A complete RPG system with gold, levels, and combat.',
                'author' => 'OpenPBBG',
                'category' => 'gameplay',
                'price' => 0,
                'rating' => 4.8,
                'downloads' => 1523,
                'icon' => '⚔️',
                'license_required' => false,
                'compatibility' => ['laravel' => '^11.0'],
                'screenshots' => [],
                'features' => ['Gold System', 'Leveling', 'Combat', 'Quests'],
            ],
            [
                'id' => 'advanced-economy',
                'slug' => 'advanced-economy',
                'name' => 'Advanced Economy',
                'version' => '2.1.0',
                'description' => 'Stock market, trading, and complex economic systems.',
                'author' => 'OpenPBBG',
                'category' => 'economy',
                'price' => 19.99,
                'rating' => 4.5,
                'downloads' => 892,
                'icon' => '💰',
                'license_required' => true,
                'compatibility' => ['laravel' => '^11.0'],
                'screenshots' => [],
                'features' => ['Stock Market', 'Trading', 'Loans', 'Interest'],
            ],
            [
                'id' => 'gang-wars',
                'slug' => 'gang-wars',
                'name' => 'Gang Wars',
                'version' => '3.0.0',
                'description' => 'Territory control, gang battles, and organized crime.',
                'author' => 'OpenPBBG',
                'category' => 'gameplay',
                'price' => 0,
                'rating' => 4.7,
                'downloads' => 2156,
                'icon' => '🏴',
                'license_required' => false,
                'compatibility' => ['laravel' => '^11.0'],
                'screenshots' => [],
                'features' => ['Territory Map', 'Gang Battles', 'Turfs', 'Rankings'],
            ],
            [
                'id' => 'dark-theme',
                'slug' => 'dark-theme',
                'name' => 'Dark Theme Pack',
                'version' => '1.2.0',
                'description' => 'A sleek dark theme for your game.',
                'author' => 'OpenPBBG',
                'category' => 'themes',
                'price' => 0,
                'rating' => 4.9,
                'downloads' => 3421,
                'icon' => '🌙',
                'license_required' => false,
                'compatibility' => ['laravel' => '^11.0'],
                'screenshots' => [],
                'features' => ['Dark Mode', 'CSS Variables', 'Customizable'],
            ],
            [
                'id' => 'premium-support',
                'slug' => 'premium-support',
                'name' => 'Premium Support System',
                'version' => '1.0.0',
                'description' => 'Advanced ticket system with priorities and assignments.',
                'author' => 'OpenPBBG',
                'category' => 'admin',
                'price' => 49.99,
                'rating' => 4.3,
                'downloads' => 234,
                'icon' => '🎫',
                'license_required' => true,
                'compatibility' => ['laravel' => '^11.0'],
                'screenshots' => [],
                'features' => ['Priority Queues', 'Staff Assignment', 'SLA Tracking'],
            ],
        ];

        // Apply filters
        if (!empty($filters['category'])) {
            $plugins = array_filter($plugins, fn($p) => $p['category'] === $filters['category']);
        }

        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $plugins = array_filter($plugins, function ($p) use ($search) {
                return str_contains(strtolower($p['name']), $search) ||
                    str_contains(strtolower($p['description']), $search);
            });
        }

        return array_values($plugins);
    }

    /**
     * Get mock sync result for development.
     *
     * @param string|null $licenseKey
     * @return array
     */
    protected function getMockSyncResult(?string $licenseKey): array
    {
        return [
            'success' => true,
            'license_valid' => !empty($licenseKey),
            'synced_at' => now()->toIso8601String(),
            'updates_available' => [
                [
                    'slug' => 'crimes',
                    'current_version' => '3.0.0',
                    'latest_version' => '3.1.0',
                    'changelog' => 'Bug fixes and performance improvements.',
                ],
            ],
            'authorized_plugins' => [
                'mini-rpg',
                'gang-wars',
                'dark-theme',
            ],
            'subscription' => [
                'tier' => 'pro',
                'expires' => now()->addYear()->toDateString(),
            ],
        ];
    }

    /**
     * Get mock download result for development.
     *
     * @param string $pluginId
     * @return array
     */
    protected function getMockDownloadResult(string $pluginId): array
    {
        // In dev mode, create a mock plugin structure
        $tempPath = storage_path('plugins/downloads/' . $pluginId . '.zip');

        return [
            'success' => true,
            'plugin_id' => $pluginId,
            'path' => $tempPath,
            'message' => 'Mock download - create plugin manually in dev mode.',
        ];
    }

    /**
     * Check if marketplace is reachable.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if ($this->devMode) {
            return true;
        }

        try {
            $response = Http::timeout(5)->get($this->apiUrl . '/health');
            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }
}
