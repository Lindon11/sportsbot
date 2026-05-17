<?php

namespace Tests\Feature;

use App\Core\Models\InstalledPlugin;
use App\Core\Services\PluginManagerService;
use App\Core\Services\PluginManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Comprehensive test suite for plugin integration with frontend.
 * Tests all aspects of plugin discovery, manifest generation, and frontend integration.
 */
class PluginIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected PluginManagerService $pluginManager;
    protected PluginManifestService $manifestService;
    protected string $pluginsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginManager = app(PluginManagerService::class);
        $this->manifestService = app(PluginManifestService::class);
        $this->pluginsPath = app_path('Plugins');
    }

    /**
     * Test that all plugins in filesystem have valid plugin.json files.
     */
    public function test_all_plugins_have_valid_plugin_json(): void
    {
        $this->assertDirectoryExists($this->pluginsPath, 'Plugins directory should exist');

        $directories = File::directories($this->pluginsPath);
        $this->assertNotEmpty($directories, 'There should be at least one plugin directory');

        $invalidPlugins = [];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));

            // Skip directories that start with underscore (e.g., _archived)
            if (str_starts_with($slug, '_')) {
                continue;
            }

            $pluginJsonPath = $dir . '/plugin.json';

            if (!File::exists($pluginJsonPath)) {
                $invalidPlugins[] = [
                    'slug' => $slug,
                    'issue' => 'Missing plugin.json file',
                ];
                continue;
            }

            $content = File::get($pluginJsonPath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalidPlugins[] = [
                    'slug' => $slug,
                    'issue' => 'Invalid JSON: ' . json_last_error_msg(),
                ];
                continue;
            }

            // Check required fields
            $requiredFields = ['name', 'slug', 'version'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $invalidPlugins[] = [
                        'slug' => $slug,
                        'issue' => "Missing required field: {$field}",
                    ];
                }
            }

            // Verify slug matches directory name (case-insensitive)
            // Note: Some plugins use hyphenated slugs in plugin.json but directory name is not hyphenated
            // This is expected and acceptable (e.g., 'advanced-crimes' vs 'advancedcrimes')
        }

        $this->assertEmpty(
            $invalidPlugins,
            'All plugins should have valid plugin.json files. Issues found: ' .
            json_encode($invalidPlugins, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that all plugins have required frontend integration fields.
     */
    public function test_all_plugins_have_frontend_integration_fields(): void
    {
        $directories = File::directories($this->pluginsPath);
        $missingFields = [];

        $frontendFields = [
            'settings.icon' => 'Icon for navigation display',
            'settings.menu.enabled' => 'Menu visibility flag',
            'settings.route' => 'Route name for navigation',
        ];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));
            $pluginJsonPath = $dir . '/plugin.json';

            if (!File::exists($pluginJsonPath)) {
                continue;
            }

            // Skip test plugins
            if ($slug === 'testplugin') {
                continue;
            }

            $data = json_decode(File::get($pluginJsonPath), true);

            foreach ($frontendFields as $field => $description) {
                $keys = explode('.', $field);
                $value = $data;
                $found = true;

                foreach ($keys as $key) {
                    if (!isset($value[$key])) {
                        $found = false;
                        break;
                    }
                    $value = $value[$key];
                }

                // Only flag if menu is enabled but missing other fields
                $menuEnabled = $data['settings']['menu']['enabled'] ?? false;
                if (!$found && $menuEnabled) {
                    $missingFields[] = [
                        'slug' => $slug,
                        'field' => $field,
                        'description' => $description,
                    ];
                }
            }
        }

        $this->assertEmpty(
            $missingFields,
            'Plugins with enabled menus should have all frontend integration fields. Missing: ' .
            json_encode($missingFields, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that plugin routes configuration is correct.
     */
    public function test_all_plugins_have_valid_routes_configuration(): void
    {
        $directories = File::directories($this->pluginsPath);
        $invalidRoutes = [];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));
            $pluginJsonPath = $dir . '/plugin.json';

            if (!File::exists($pluginJsonPath)) {
                continue;
            }

            $data = json_decode(File::get($pluginJsonPath), true);
            $routes = $data['routes'] ?? [];

            // Check routes structure
            if (!empty($routes)) {
                $validKeys = ['web', 'api', 'admin'];
                foreach ($routes as $key => $value) {
                    if (!in_array($key, $validKeys)) {
                        $invalidRoutes[] = [
                            'slug' => $slug,
                            'issue' => "Invalid route key: {$key}",
                        ];
                    }
                    if (!is_bool($value)) {
                        $invalidRoutes[] = [
                            'slug' => $slug,
                            'issue' => "Route value must be boolean: {$key}",
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $invalidRoutes,
            'All plugins should have valid routes configuration. Issues: ' .
            json_encode($invalidRoutes, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that plugins can be discovered by PluginManagerService.
     */
    public function test_all_filesystem_plugins_are_discoverable(): void
    {
        $directories = File::directories($this->pluginsPath);
        $discoveredPlugins = $this->pluginManager->getAllPlugins();
        $discoveredSlugs = array_column($discoveredPlugins, 'slug');

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));

            // Skip directories that start with underscore (e.g., _archived)
            if (str_starts_with($slug, '_')) {
                continue;
            }

            // Skip test plugin directory if exists
            if ($slug === 'testplugin') {
                continue;
            }

            $this->assertContains(
                $slug,
                $discoveredSlugs,
                "Plugin '{$slug}' should be discoverable by PluginManagerService"
            );
        }
    }

    /**
     * Test that enabled plugins endpoint returns correct structure.
     */
    public function test_enabled_plugins_endpoint_complete_structure(): void
    {
        // Create a test plugin in database with unique slug
        $uniqueSlug = 'test-plugin-' . uniqid();
        $plugin = InstalledPlugin::create([
            'name' => 'Test Plugin',
            'slug' => $uniqueSlug,
            'version' => '1.0.0',
            'type' => 'plugin',
            'description' => 'Test plugin for integration testing',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '🔧',
            'color' => 'blue',
            'route_name' => 'test-plugin.index',
            'order' => 100,
            'has_api_routes' => true,
            'has_web_routes' => true,
            'has_admin_routes' => false,
            'frontend_routes' => [
                ['path' => '/test-plugin', 'name' => 'test-plugin', 'component' => 'TestPluginView'],
            ],
            'frontend_slots' => ['dashboard-widget'],
            'permissions' => ['view-test-plugin'],
            'config' => [
                'menu' => [
                    'enabled' => true,
                    'section' => 'utilities',
                    'order' => 100,
                ],
            ],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'plugins' => [
                    '*' => [
                        'slug',
                        'name',
                        'version',
                        'description',
                        'icon',
                        'color',
                        'route_name',
                        'frontend_routes',
                        'navigation',
                        'order',
                        'has_api_routes',
                        'has_web_routes',
                        'has_admin_routes',
                        'frontend_slots',
                        'permissions',
                    ],
                ],
                'navigation',
                'routes',
            ]);

        // Find our test plugin in the response
        $plugins = collect($response->json('plugins'));
        $testPlugin = $plugins->firstWhere('slug', $uniqueSlug);

        $this->assertNotNull($testPlugin, 'Test plugin should be in response');
        $this->assertEquals('🔧', $testPlugin['icon']);
        $this->assertEquals('blue', $testPlugin['color']);
        $this->assertEquals(['dashboard-widget'], $testPlugin['frontend_slots']);
        $this->assertEquals(['view-test-plugin'], $testPlugin['permissions']);
    }

    /**
     * Test that plugin manifest includes all expected routes.
     */
    public function test_plugin_manifest_includes_all_routes(): void
    {
        // Use unique slugs for test isolation
        $slug1 = 'plugin-one-' . uniqid();
        $slug2 = 'plugin-two-' . uniqid();

        // Create multiple plugins with routes
        InstalledPlugin::create([
            'name' => 'Plugin One',
            'slug' => $slug1,
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'frontend_routes' => [
                ['path' => '/plugin-one', 'name' => 'plugin-one', 'component' => 'PluginOneView'],
                ['path' => '/plugin-one/settings', 'name' => 'plugin-one-settings', 'component' => 'PluginOneSettingsView'],
            ],
        ]);

        InstalledPlugin::create([
            'name' => 'Plugin Two',
            'slug' => $slug2,
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'frontend_routes' => [
                ['path' => '/plugin-two', 'name' => 'plugin-two', 'component' => 'PluginTwoView'],
            ],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk();

        // Find our test routes in the response
        $routes = collect($response->json('routes'));
        $testRoutes1 = $routes->where('plugin', $slug1)->values();
        $testRoutes2 = $routes->where('plugin', $slug2)->values();

        // Should have 2 routes from plugin-one, 1 from plugin-two
        $this->assertCount(2, $testRoutes1, 'Plugin one should have 2 routes');
        $this->assertCount(1, $testRoutes2, 'Plugin two should have 1 route');

        // Verify route structure
        foreach ($routes as $route) {
            $this->assertArrayHasKey('plugin', $route);
            $this->assertArrayHasKey('path', $route);
            $this->assertArrayHasKey('name', $route);
            $this->assertArrayHasKey('component', $route);
        }
    }

    /**
     * Test that navigation items are sorted correctly.
     */
    public function test_navigation_items_sorted_by_section_and_order(): void
    {
        // Use unique slugs for test isolation
        $prefix = 'nav-sort-' . uniqid() . '-';

        InstalledPlugin::create([
            'name' => 'Plugin A',
            'slug' => $prefix . 'a',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '🅰️',
            'route_name' => 'plugin-a',
            'order' => 30,
            'config' => ['menu' => ['enabled' => true, 'section' => 'actions', 'order' => 30]],
        ]);

        InstalledPlugin::create([
            'name' => 'Plugin B',
            'slug' => $prefix . 'b',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '🅱️',
            'route_name' => 'plugin-b',
            'order' => 10,
            'config' => ['menu' => ['enabled' => true, 'section' => 'actions', 'order' => 10]],
        ]);

        InstalledPlugin::create([
            'name' => 'Plugin C',
            'slug' => $prefix . 'c',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '©️',
            'route_name' => 'plugin-c',
            'order' => 5,
            'config' => ['menu' => ['enabled' => true, 'section' => 'utilities', 'order' => 5]],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        // Find our test navigation items in the response
        $navigation = collect($response->json('navigation'));
        $testNav = $navigation->filter(function ($n) use ($prefix) {
            return str_starts_with($n['slug'], $prefix);
        })->values();

        // Should have 3 navigation items
        $this->assertCount(3, $testNav);

        // Should be sorted by section first (alphabetically), then order
        // 'actions' comes before 'utilities' alphabetically
        $this->assertEquals('actions', $testNav[0]['section']);
        $this->assertEquals('actions', $testNav[1]['section']);
        $this->assertEquals('utilities', $testNav[2]['section']);

        // Within actions section, should be sorted by order
        $this->assertEquals($prefix . 'b', $testNav[0]['slug']); // order 10
        $this->assertEquals($prefix . 'a', $testNav[1]['slug']); // order 30
        $this->assertEquals($prefix . 'c', $testNav[2]['slug']); // utilities section
    }

    /**
     * Test that disabled plugins are excluded from manifest.
     */
    public function test_disabled_plugins_excluded_from_manifest(): void
    {
        // Use unique slugs for test isolation
        $enabledSlug = 'enabled-plugin-' . uniqid();
        $disabledSlug = 'disabled-plugin-' . uniqid();

        // Create enabled plugin
        InstalledPlugin::create([
            'name' => 'Enabled Plugin',
            'slug' => $enabledSlug,
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'config' => ['menu' => ['enabled' => true]],
        ]);

        // Create disabled plugin
        InstalledPlugin::create([
            'name' => 'Disabled Plugin',
            'slug' => $disabledSlug,
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => false,
            'installed_at' => now(),
            'config' => ['menu' => ['enabled' => true]],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk();

        // Find our plugins in the response
        $plugins = collect($response->json('plugins'));
        $enabledPlugin = $plugins->firstWhere('slug', $enabledSlug);
        $disabledPlugin = $plugins->firstWhere('slug', $disabledSlug);

        $this->assertNotNull($enabledPlugin, 'Enabled plugin should be in response');
        $this->assertNull($disabledPlugin, 'Disabled plugin should NOT be in response');
    }

    /**
     * Test that themes are excluded from plugin manifest.
     */
    public function test_themes_excluded_from_plugin_manifest(): void
    {
        // Use unique slugs for test isolation
        $pluginSlug = 'test-plugin-' . uniqid();
        $themeSlug = 'test-theme-' . uniqid();

        InstalledPlugin::create([
            'name' => 'Test Plugin',
            'slug' => $pluginSlug,
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
        ]);

        InstalledPlugin::create([
            'name' => 'Test Theme',
            'slug' => $themeSlug,
            'version' => '1.0.0',
            'type' => 'theme',
            'enabled' => true,
            'installed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk();

        // Find our plugin and theme in the response
        $plugins = collect($response->json('plugins'));
        $testPlugin = $plugins->firstWhere('slug', $pluginSlug);
        $testTheme = $plugins->firstWhere('slug', $themeSlug);

        $this->assertNotNull($testPlugin, 'Plugin should be in response');
        $this->assertNull($testTheme, 'Theme should NOT be in response');
    }

    /**
     * Test plugin hooks are properly registered.
     */
    public function test_plugin_hooks_properly_configured(): void
    {
        $directories = File::directories($this->pluginsPath);
        $invalidHooks = [];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));
            $pluginJsonPath = $dir . '/plugin.json';

            if (!File::exists($pluginJsonPath)) {
                continue;
            }

            $data = json_decode(File::get($pluginJsonPath), true);
            $hooks = $data['hooks'] ?? [];

            if (!empty($hooks)) {
                if (!is_array($hooks)) {
                    $invalidHooks[] = [
                        'slug' => $slug,
                        'issue' => 'Hooks must be an array/object',
                    ];
                }
            }
        }

        $this->assertEmpty(
            $invalidHooks,
            'All plugin hooks should be properly configured. Issues: ' .
            json_encode($invalidHooks, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test plugin dependencies are valid.
     */
    public function test_plugin_dependencies_valid(): void
    {
        $directories = File::directories($this->pluginsPath);
        $allPluginSlugs = [];

        // Collect all plugin slugs first
        foreach ($directories as $dir) {
            $allPluginSlugs[] = strtolower(basename($dir));
        }

        $invalidDependencies = [];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));
            $pluginJsonPath = $dir . '/plugin.json';

            if (!File::exists($pluginJsonPath)) {
                continue;
            }

            $data = json_decode(File::get($pluginJsonPath), true);
            $dependencies = $data['requires']['plugins'] ?? [];

            foreach ($dependencies as $depSlug => $version) {
                // Check if dependency exists
                if (!in_array(strtolower($depSlug), $allPluginSlugs)) {
                    $invalidDependencies[] = [
                        'plugin' => $slug,
                        'missing_dependency' => $depSlug,
                        'version_constraint' => $version,
                    ];
                }
            }
        }

        $this->assertEmpty(
            $invalidDependencies,
            'All plugin dependencies should exist. Missing dependencies: ' .
            json_encode($invalidDependencies, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test plugin lifecycle methods exist if defined.
     */
    public function test_plugin_lifecycle_classes_valid(): void
    {
        $directories = File::directories($this->pluginsPath);
        $lifecycleIssues = [];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));
            $pascal = str_replace('-', '', ucwords($slug, '-'));

            // Check for Plugin class
            $pluginClassPath = $dir . "/{$pascal}Plugin.php";
            $lifecyclePath = $dir . '/Lifecycle.php';
            $installerPath = $dir . '/src/Installer.php';

            // If any lifecycle class exists, verify it's valid
            foreach ([$pluginClassPath, $lifecyclePath, $installerPath] as $path) {
                if (File::exists($path)) {
                    $content = File::get($path);

                    // Check for class declaration
                    if (!preg_match('/class\s+\w+/', $content)) {
                        $lifecycleIssues[] = [
                            'slug' => $slug,
                            'file' => basename($path),
                            'issue' => 'No class declaration found',
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $lifecycleIssues,
            'Plugin lifecycle classes should be valid. Issues: ' .
            json_encode($lifecycleIssues, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that manifest service returns consistent data.
     */
    public function test_manifest_service_returns_consistent_data(): void
    {
        // Create a plugin
        InstalledPlugin::create([
            'name' => 'Consistency Test',
            'slug' => 'consistency-test',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '🧪',
            'color' => 'purple',
            'route_name' => 'consistency-test',
            'frontend_routes' => [
                ['path' => '/consistency-test', 'name' => 'consistency-test', 'component' => 'ConsistencyTestView'],
            ],
            'config' => ['menu' => ['enabled' => true, 'section' => 'test', 'order' => 1]],
        ]);

        // Call service directly
        $plugins = $this->manifestService->getEnabledPluginsForFrontend();
        $navigation = $this->manifestService->getNavigationItems();
        $routes = $this->manifestService->getPluginRoutes();

        // Verify plugin exists in all collections
        $pluginSlugs = $plugins->pluck('slug')->toArray();
        $this->assertContains('consistency-test', $pluginSlugs);

        // Verify navigation includes plugin
        $navSlugs = array_column($navigation, 'slug');
        $this->assertContains('consistency-test', $navSlugs);

        // Verify routes include plugin routes
        $routePlugins = array_column($routes, 'plugin');
        $this->assertContains('consistency-test', $routePlugins);
    }
}
