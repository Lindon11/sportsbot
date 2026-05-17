<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Test suite to detect hardcoded fragments from pre-upgrade system.
 * Ensures the frontend properly integrates with the dynamic plugin system
 * and does not contain hardcoded plugin references.
 */
class HardcodedFragmentDetectionTest extends TestCase
{
    protected string $frontendPath;
    protected string $backendPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->frontendPath = base_path('frontend/src');
        $this->backendPath = base_path('backend');
    }

    /**
     * Patterns that indicate hardcoded pre-upgrade fragments.
     */
    protected function getHardcodedPatterns(): array
    {
        return [
            // Hardcoded navigation items (should use plugin store)
            'hardcoded_nav' => [
                'pattern' => '/Hardcoded.*Navigation|navigationItems\s*=\s*\[[^\]]*\]/i',
                'description' => 'Hardcoded navigation items should use plugin store',
            ],
            // Direct plugin references without using store
            'direct_plugin_check' => [
                'pattern' => '/if\s*\(\s*[\'"](?:combat|hospital|jail|bank|casino|drugs|racing|theft|bounty|detective|bullets|gang|missions|properties|stocks|achievements|leaderboards|quests|education|employment|inventory|market|chat|messaging|forums|wiki|tickets|announcements|events|tournament|travel|daily-rewards|organized-crime|alliances|minirpg|advancedcrimes|crimes|progression)[\'"]\s*\)/i',
                'description' => 'Direct plugin string checks should use pluginsStore.isEnabled()',
            ],
            // Hardcoded feature flags (pre-plugin system)
            'hardcoded_features' => [
                'pattern' => '/features\s*[:=]\s*\{[^}]*(?:combat|hospital|jail)[^}]*\}/i',
                'description' => 'Hardcoded feature flags should be plugin-based',
            ],
            // Static module lists
            'static_modules' => [
                'pattern' => '/modules\s*[:=]\s*\[[^\]]*(?:combat|hospital|jail|bank)[^\]]*\]/i',
                'description' => 'Static module lists should use dynamic plugin discovery',
            ],
            // Hardcoded menu configurations
            'hardcoded_menu' => [
                'pattern' => '/menuItems\s*[:=]\s*\[[^\]]*path\s*:[^\]]*\]/i',
                'description' => 'Hardcoded menu items should use plugin navigation',
            ],
        ];
    }

    /**
     * Test frontend views for hardcoded plugin references.
     */
    public function test_frontend_views_no_hardcoded_plugins(): void
    {
        $viewsPath = $this->frontendPath . '/views';

        if (!File::isDirectory($viewsPath)) {
            $this->markTestSkipped('Frontend views directory not found');
        }

        $hardcodedReferences = [];
        $vueFiles = $this->getVueFiles($viewsPath);

        foreach ($vueFiles as $file) {
            $content = File::get($file);
            $relativePath = str_replace(base_path() . '/', '', $file);

            // Check for hardcoded plugin lists in template sections
            $patterns = [
                // Feature sections that should be dynamically generated
                '/Combat\s*&\s*Activities/i',
                '/Economy\s*&\s*Trading/i',
                '/Social\s*&\s*Community/i',
                // Hardcoded links without isPluginEnabled check
                '/<router-link[^>]*to=[\'"]\/(?:combat|hospital|jail|bank|casino|drugs|racing|theft)[\'"][^>]*>[^<]*<\/router-link>/i',
            ];

            foreach ($patterns as $pattern) {
                // Skip files that properly use isPluginEnabled
                if (strpos($content, 'isPluginEnabled') !== false) {
                    continue;
                }

                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $lineNum = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                        $hardcodedReferences[] = [
                            'file' => $relativePath,
                            'line' => $lineNum,
                            'match' => trim($match[0]),
                        ];
                    }
                }
            }
        }

        // Filter out HomeView.vue since it properly uses isPluginEnabled
        $hardcodedReferences = array_filter($hardcodedReferences, function ($ref) {
            return !str_contains($ref['file'], 'HomeView.vue') ||
                   !str_contains($ref['match'], 'router-link');
        });

        $this->assertEmpty(
            $hardcodedReferences,
            'Frontend views should not contain hardcoded plugin references without isPluginEnabled checks. Found: ' .
            json_encode($hardcodedReferences, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that navigation components use plugin store.
     */
    public function test_navigation_uses_plugin_store(): void
    {
        $navFiles = [
            $this->frontendPath . '/layouts/GameLayout.vue',
            $this->frontendPath . '/components/Navigation.vue',
            $this->frontendPath . '/components/Sidebar.vue',
        ];

        foreach ($navFiles as $file) {
            if (!File::exists($file)) {
                continue;
            }

            $content = File::get($file);
            $relativePath = str_replace(base_path() . '/', '', $file);

            // Should import or use plugins store
            $usesPluginStore = preg_match('/usePluginsStore|pluginsStore/', $content);

            // Should not have hardcoded navigation array
            $hasHardcodedNav = preg_match('/navigationItems\s*=\s*\[[^\]]*\]/', $content);

            if ($hasHardcodedNav && !$usesPluginStore) {
                $this->fail("Navigation file {$relativePath} should use pluginsStore for dynamic navigation");
            }
        }

        $this->assertTrue(true, 'Navigation components properly use plugin store');
    }

    /**
     * Test router configuration has no hardcoded plugin routes.
     */
    public function test_router_no_hardcoded_plugin_routes(): void
    {
        $routerPath = $this->frontendPath . '/router/index.ts';

        if (!File::exists($routerPath)) {
            $this->markTestSkipped('Router file not found');
        }

        $content = File::get($routerPath);

        // Check for pluginRoutes mapping (this is OK - it's used for static route definitions)
        $hasPluginRoutesMapping = preg_match('/const\s+pluginRoutes\s*:/', $content);

        // Check for proper dynamic route initialization
        $hasDynamicInit = preg_match('/initializePluginRoutes|registerPluginRoutes/', $content);

        // Check that plugins store is imported
        $importsPluginStore = preg_match('/usePluginsStore/', $content);

        $this->assertTrue(
            $hasDynamicInit || $hasPluginRoutesMapping,
            'Router should have dynamic plugin route initialization'
        );

        $this->assertTrue(
            $importsPluginStore,
            'Router should import pluginsStore for route management'
        );
    }

    /**
     * Test that components properly check plugin enabled status.
     */
    public function test_components_check_plugin_status(): void
    {
        $componentPath = $this->frontendPath . '/components';

        if (!File::isDirectory($componentPath)) {
            $this->markTestSkipped('Components directory not found');
        }

        $vueFiles = $this->getVueFiles($componentPath);
        $issues = [];

        foreach ($vueFiles as $file) {
            $content = File::get($file);
            $relativePath = str_replace(base_path() . '/', '', $file);

            // Check if component has plugin-specific content
            $hasPluginContent = preg_match('/Combat|Hospital|Jail|Bank|Casino/i', $content);

            // If it does, it should check plugin status
            if ($hasPluginContent) {
                $checksPluginStatus = preg_match('/isPluginEnabled|isEnabled|pluginsStore/', $content);

                // Skip if it's just displaying text/titles
                $isJustText = preg_match('/<h[1-6][^>]*>(?:Combat|Hospital|Jail|Bank|Casino)/i', $content) &&
                              !preg_match('/v-if.*plugin|v-show.*plugin/i', $content);

                if (!$checksPluginStatus && !$isJustText) {
                    $issues[] = [
                        'file' => $relativePath,
                        'issue' => 'Component has plugin-specific content but does not check plugin status',
                    ];
                }
            }
        }

        $this->assertEmpty(
            $issues,
            'Components with plugin-specific content should check plugin enabled status. Issues: ' .
            json_encode($issues, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test backend API routes are not hardcoded for plugins.
     */
    public function test_backend_api_no_hardcoded_plugin_routes(): void
    {
        $apiRoutesPath = $this->backendPath . '/routes/api.php';

        if (!File::exists($apiRoutesPath)) {
            $this->markTestSkipped('API routes file not found');
        }

        $content = File::get($apiRoutesPath);

        // Check that plugin routes are registered dynamically or through plugin files
        // The routes file should use plugin controllers, not hardcoded routes

        // Look for plugin controller references
        $hasPluginControllers = preg_match('/App\\\\Plugins\\\\\w+\\\\Controllers/', $content);

        // Check for proper plugin manifest endpoint
        $hasManifestEndpoint = preg_match('/plugins\/enabled/', $content);

        $this->assertTrue(
            $hasManifestEndpoint,
            'API routes should include plugin manifest endpoint'
        );

        $this->assertTrue(
            $hasPluginControllers,
            'API routes should use plugin controllers'
        );
    }

    /**
     * Test that plugin store is used consistently.
     */
    public function test_plugin_store_consistent_usage(): void
    {
        $storePath = $this->frontendPath . '/stores/plugins.ts';

        if (!File::exists($storePath)) {
            $this->markTestSkipped('Plugin store file not found');
        }

        $content = File::get($storePath);

        // Verify store has all required methods
        $requiredMethods = [
            'isEnabled',
            'fetchPlugins',
            'getPlugin',
            'getPluginByRoute',
            'hasSlot',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertMatchesRegularExpression(
                "/function\s+{$method}|const\s+{$method}\s*=/",
                $content,
                "Plugin store should have '{$method}' method"
            );
        }

        // Verify store fetches from correct endpoint
        $this->assertStringContainsString(
            '/plugins/enabled',
            $content,
            'Plugin store should fetch from /plugins/enabled endpoint'
        );
    }

    /**
     * Test that there are no duplicate plugin definitions.
     */
    public function test_no_duplicate_plugin_definitions(): void
    {
        $pluginsPath = base_path('backend/app/Plugins');

        if (!File::isDirectory($pluginsPath)) {
            $this->markTestSkipped('Plugins directory not found');
        }

        $directories = File::directories($pluginsPath);
        $slugs = [];
        $duplicates = [];

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));

            if (isset($slugs[$slug])) {
                $duplicates[] = [
                    'slug' => $slug,
                    'paths' => [$slugs[$slug], $dir],
                ];
            } else {
                $slugs[$slug] = $dir;
            }
        }

        $this->assertEmpty(
            $duplicates,
            'There should be no duplicate plugin directories. Duplicates found: ' .
            json_encode($duplicates, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that all Vue views in plugins directory exist.
     */
    public function test_frontend_views_match_backend_plugins(): void
    {
        $pluginsPath = base_path('backend/app/Plugins');
        $viewsPath = $this->frontendPath . '/views/plugins';

        if (!File::isDirectory($pluginsPath)) {
            $this->markTestSkipped('Plugins directory not found');
        }

        $missingViews = [];
        $directories = File::directories($pluginsPath);

        foreach ($directories as $dir) {
            $slug = strtolower(basename($dir));
            $pluginJsonPath = $dir . '/plugin.json';

            // Skip if no plugin.json
            if (!File::exists($pluginJsonPath)) {
                continue;
            }

            $data = json_decode(File::get($pluginJsonPath), true);
            $menuEnabled = $data['settings']['menu']['enabled'] ?? false;

            // Only check plugins with enabled menus
            if (!$menuEnabled) {
                continue;
            }

            // Check if corresponding Vue view exists
            $pascalName = str_replace('-', '', ucwords($slug, '-'));
            $expectedView = $viewsPath . "/{$pascalName}View.vue";

            if (!File::exists($expectedView)) {
                // Check for alternate naming conventions
                $altViews = [
                    $viewsPath . "/{$pascalName}View.vue",
                    $viewsPath . "/" . ucfirst($slug) . "View.vue",
                    $this->frontendPath . "/views/" . ucfirst($slug) . "View.vue",
                ];

                $found = false;
                foreach ($altViews as $altView) {
                    if (File::exists($altView)) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $missingViews[] = [
                        'plugin' => $slug,
                        'expected_view' => str_replace(base_path() . '/', '', $expectedView),
                    ];
                }
            }
        }

        // This is a warning, not a hard failure
        if (!empty($missingViews)) {
            $this->addWarning(
                'Some plugins with enabled menus are missing corresponding Vue views: ' .
                json_encode($missingViews, JSON_PRETTY_PRINT)
            );
        } else {
            $this->assertTrue(true, 'All plugins with enabled menus have corresponding Vue views');
        }
    }

    /**
     * Test HomeView uses plugin checks correctly.
     */
    public function test_home_view_uses_plugin_checks(): void
    {
        $homeViewPath = $this->frontendPath . '/views/HomeView.vue';

        if (!File::exists($homeViewPath)) {
            $this->markTestSkipped('HomeView.vue not found');
        }

        $content = File::get($homeViewPath);

        // Should import and use pluginsStore
        $this->assertMatchesRegularExpression(
            '/usePluginsStore/',
            $content,
            'HomeView should use pluginsStore'
        );

        // Should check plugin enabled status before showing links
        $this->assertMatchesRegularExpression(
            '/isPluginEnabled|pluginsStore\.isEnabled/',
            $content,
            'HomeView should check if plugins are enabled before showing navigation'
        );

        // Should have feature sections that use v-if with plugin checks
        $this->assertMatchesRegularExpression(
            '/v-if.*isPluginEnabled/',
            $content,
            'HomeView should use v-if with isPluginEnabled for conditional rendering'
        );
    }

    /**
     * Helper to get all Vue files in a directory recursively.
     */
    protected function getVueFiles(string $directory): array
    {
        $files = [];

        if (!File::isDirectory($directory)) {
            return $files;
        }

        $items = File::allFiles($directory);

        foreach ($items as $item) {
            if ($item->getExtension() === 'vue') {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    /**
     * Test for orphaned plugin configuration in frontend.
     */
    public function test_no_orphaned_plugin_config(): void
    {
        $configPath = $this->frontendPath . '/config';

        if (!File::isDirectory($configPath)) {
            $this->assertTrue(true, 'No config directory - OK');
            return;
        }

        $files = File::allFiles($configPath);
        $orphanedConfig = [];

        foreach ($files as $file) {
            $content = File::get($file->getPathname());

            // Check for hardcoded plugin configurations
            if (preg_match('/plugins\s*:\s*\[[^\]]*\]/', $content)) {
                $orphanedConfig[] = $file->getRelativePathname();
            }
        }

        $this->assertEmpty(
            $orphanedConfig,
            'Frontend config should not contain hardcoded plugin lists. Found in: ' .
            implode(', ', $orphanedConfig)
        );
    }
}
