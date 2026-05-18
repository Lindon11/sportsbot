<?php

namespace Tests\Feature;

use App\Core\Models\InstalledPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PluginFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Test the enabled plugins endpoint returns proper structure.
     */
    public function test_enabled_plugins_endpoint_returns_proper_structure(): void
    {
        // Create a test plugin
        $plugin = InstalledPlugin::create([
            'name' => 'Test Combat',
            'slug' => 'combat',
            'version' => '1.0.0',
            'type' => 'plugin',
            'description' => 'Test combat plugin',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '⚔️',
            'color' => 'red',
            'route_name' => 'combat.index',
            'order' => 10,
            'has_api_routes' => true,
            'has_web_routes' => true,
            'has_admin_routes' => false,
            'frontend_routes' => [
                [
                    'path' => '/combat',
                    'name' => 'combat',
                    'component' => 'CombatView',
                    'meta' => ['title' => 'Combat'],
                ],
            ],
            'config' => [
                'menu' => [
                    'enabled' => true,
                    'section' => 'actions',
                    'order' => 10,
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
                    ],
                ],
                'navigation',
                'routes',
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('plugins.0.slug', 'combat')
            ->assertJsonPath('plugins.0.name', 'Test Combat')
            ->assertJsonPath('plugins.0.icon', '⚔️')
            ->assertJsonPath('plugins.0.frontend_routes.0.path', '/combat');
    }

    /**
     * Test that disabled plugins are not included.
     */
    public function test_disabled_plugins_not_included(): void
    {
        // Create an enabled plugin
        InstalledPlugin::create([
            'name' => 'Enabled Plugin',
            'slug' => 'enabled-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
        ]);

        // Create a disabled plugin
        InstalledPlugin::create([
            'name' => 'Disabled Plugin',
            'slug' => 'disabled-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => false,
            'installed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonCount(1, 'plugins')
            ->assertJsonPath('plugins.0.slug', 'enabled-plugin');
    }

    /**
     * Test navigation items are properly formatted.
     */
    public function test_navigation_items_properly_formatted(): void
    {
        InstalledPlugin::create([
            'name' => 'Hospital',
            'slug' => 'hospital',
            'version' => '1.0.0',
            'type' => 'plugin',
            'description' => 'Heal your wounds',
            'enabled' => true,
            'installed_at' => now(),
            'icon' => '🏥',
            'color' => 'green',
            'route_name' => 'hospital',
            'order' => 5,
            'config' => [
                'menu' => [
                    'enabled' => true,
                    'section' => 'utilities',
                    'order' => 5,
                ],
            ],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonStructure([
                'navigation' => [
                    '*' => [
                        'slug',
                        'name',
                        'icon',
                        'color',
                        'route',
                        'section',
                        'order',
                    ],
                ],
            ])
            ->assertJsonPath('navigation.0.slug', 'hospital')
            ->assertJsonPath('navigation.0.section', 'utilities');
    }

    /**
     * Test routes are extracted from plugins.
     */
    public function test_routes_extracted_from_plugins(): void
    {
        InstalledPlugin::create([
            'name' => 'Racing',
            'slug' => 'racing',
            'version' => '1.0.0',
            'type' => 'plugin',
            'description' => 'Race your cars',
            'enabled' => true,
            'installed_at' => now(),
            'frontend_routes' => [
                [
                    'path' => '/racing',
                    'name' => 'racing',
                    'component' => 'RacingView',
                    'meta' => ['title' => 'Racing'],
                ],
                [
                    'path' => '/racing/create',
                    'name' => 'racing-create',
                    'component' => 'RacingCreateView',
                    'meta' => ['title' => 'Create Race'],
                ],
            ],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonCount(2, 'routes')
            ->assertJsonPath('routes.0.plugin', 'racing')
            ->assertJsonPath('routes.0.path', '/racing')
            ->assertJsonPath('routes.1.path', '/racing/create');
    }

    /**
     * Test repo manifest routes are still exposed when installed metadata is stale.
     */
    public function test_manifest_routes_are_merged_with_stale_installed_plugin_routes(): void
    {
        InstalledPlugin::create([
            'name' => 'Sports Bot',
            'slug' => 'sportsbot',
            'version' => '0.1.0',
            'type' => 'plugin',
            'description' => 'Sports alerts',
            'enabled' => true,
            'installed_at' => now(),
            'frontend_routes' => [
                [
                    'path' => '/sportsbot/update',
                    'name' => 'sportsbot-update',
                    'component' => 'UpdateView',
                    'meta' => ['title' => 'Update'],
                ],
            ],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonFragment([
                'path' => '/sportsbot/telegram-settings',
                'name' => 'sportsbot-telegram-settings',
                'component' => 'TelegramSettingsView',
            ])
            ->assertJsonFragment([
                'path' => '/sportsbot/update',
                'name' => 'sportsbot-update',
                'component' => 'UpdateView',
            ]);
    }

    /**
     * Test empty response when no plugins enabled.
     */
    public function test_empty_response_when_no_plugins_enabled(): void
    {
        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('plugins', [])
            ->assertJsonPath('navigation', [])
            ->assertJsonPath('routes', []);
    }

    /**
     * Test plugins are sorted by order.
     */
    public function test_plugins_sorted_by_order(): void
    {
        // Create plugins with different orders
        InstalledPlugin::create([
            'name' => 'Third Plugin',
            'slug' => 'third-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'order' => 30,
        ]);

        InstalledPlugin::create([
            'name' => 'First Plugin',
            'slug' => 'first-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'order' => 10,
        ]);

        InstalledPlugin::create([
            'name' => 'Second Plugin',
            'slug' => 'second-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'order' => 20,
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonPath('plugins.0.slug', 'first-plugin')
            ->assertJsonPath('plugins.1.slug', 'second-plugin')
            ->assertJsonPath('plugins.2.slug', 'third-plugin');
    }

    /**
     * Test themes are not included in plugins list.
     */
    public function test_themes_not_included_in_plugins_list(): void
    {
        // Create a plugin
        InstalledPlugin::create([
            'name' => 'Test Plugin',
            'slug' => 'test-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
        ]);

        // Create a theme
        InstalledPlugin::create([
            'name' => 'Test Theme',
            'slug' => 'test-theme',
            'version' => '1.0.0',
            'type' => 'theme',
            'enabled' => true,
            'installed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonCount(1, 'plugins')
            ->assertJsonPath('plugins.0.slug', 'test-plugin');
    }

    /**
     * Test plugin manifest includes frontend slots.
     */
    public function test_plugin_manifest_includes_frontend_slots(): void
    {
        InstalledPlugin::create([
            'name' => 'Widget Plugin',
            'slug' => 'widget-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'frontend_slots' => ['dashboard-widget', 'sidebar-panel'],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonPath('plugins.0.frontend_slots', ['dashboard-widget', 'sidebar-panel']);
    }

    /**
     * Test plugin manifest includes permissions.
     */
    public function test_plugin_manifest_includes_permissions(): void
    {
        InstalledPlugin::create([
            'name' => 'Admin Plugin',
            'slug' => 'admin-plugin',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
            'installed_at' => now(),
            'permissions' => ['view-admin', 'manage-users', 'view-logs'],
        ]);

        $response = $this->getJson('/api/v1/plugins/enabled');

        $response->assertOk()
            ->assertJsonPath('plugins.0.permissions', ['view-admin', 'manage-users', 'view-logs']);
    }
}
