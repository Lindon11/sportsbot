<?php
namespace Tests\Unit;

use App\Core\Services\PluginManagerService;
use App\Core\Models\InstalledPlugin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginManagerServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_plugin_json_schema_validation_fails_on_missing_fields()
    {
        $service = new PluginManagerService();
        $pluginPath = base_path('tests/fixtures/InvalidPluginMissingFields');
        $pluginData = [
            'name' => 'InvalidPlugin',
            // 'slug' => missing
            'version' => '1.0.0',
            'description' => 'desc',
            'author' => 'test',
        ];
        File::shouldReceive('exists')->andReturn(true);
        File::shouldReceive('isDirectory')->andReturn(true);
        $this->assertFalse($service->validatePluginStructure($pluginData, $pluginPath));
    }

    public function test_plugin_json_schema_validation_passes_on_valid_plugin()
    {
        $service = new PluginManagerService();
        $pluginPath = base_path('tests/fixtures/ValidPlugin');
        $pluginData = [
            'name' => 'ValidPlugin',
            'slug' => 'validplugin',
            'version' => '1.0.0',
            'description' => 'desc',
            'author' => 'test',
        ];
        File::shouldReceive('exists')->andReturn(true);
        File::shouldReceive('isDirectory')->andReturn(true);
        $this->assertTrue($service->validatePluginStructure($pluginData, $pluginPath));
    }

    public function test_check_dependencies_reports_missing_when_not_installed()
    {
        $service = new PluginManagerService();

        // checkDependencies expects a full plugin.json structure
        $pluginJson = ['requires' => ['plugins' => ['depmod' => '^1.0.0']]];

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('checkDependencies');
        $method->setAccessible(true);

        $result = $method->invokeArgs($service, [$pluginJson]);

        $this->assertFalse($result['satisfied']);
        $this->assertNotEmpty($result['missing']);
        $this->assertStringContainsString('not installed', $result['missing'][0]);
    }

    public function test_check_dependencies_reports_unsatisfied_version()
    {
        InstalledPlugin::create([
            'name' => 'Dep Mod',
            'slug' => 'depmod',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
        ]);

        $service = new PluginManagerService();
        // checkDependencies expects a full plugin.json structure
        $pluginJson = ['requires' => ['plugins' => ['depmod' => '^2.0.0']]];

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('checkDependencies');
        $method->setAccessible(true);

        $result = $method->invokeArgs($service, [$pluginJson]);

        $this->assertFalse($result['satisfied']);
        $this->assertNotEmpty($result['missing']);
        $this->assertStringContainsString('requires', $result['missing'][0]);
    }

    public function test_enable_plugin_not_installed_returns_error()
    {
        $service = new PluginManagerService();
        $res = $service->enablePlugin('nosuch');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('not installed', strtolower($res['message']));
    }

    public function test_enable_plugin_already_enabled_returns_error()
    {
        InstalledPlugin::create([
            'name' => 'Existing',
            'slug' => 'existing',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
        ]);

        $service = new PluginManagerService();
        $res = $service->enablePlugin('existing');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('already enabled', strtolower($res['message']));
    }

    public function test_disable_plugin_not_installed_returns_error()
    {
        $service = new PluginManagerService();
        $res = $service->disablePlugin('nosuch');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('not installed', strtolower($res['message']));
    }

    public function test_disable_plugin_already_disabled_returns_error()
    {
        InstalledPlugin::create([
            'name' => 'Disabled',
            'slug' => 'disabled',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => false,
        ]);

        $service = new PluginManagerService();
        $res = $service->disablePlugin('disabled');
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('already disabled', strtolower($res['message']));
    }

    public function test_install_plugin_from_staging_creates_database_record()
    {
        // Arrange: mock filesystem to simulate staging plugin
        File::shouldReceive('exists')->andReturnUsing(function ($path) {
            // Avoid trying to require an Installer.php that doesn't exist in tests
            if (str_ends_with($path, '/src/Installer.php')) {
                return false;
            }
            return true;
        });
        File::shouldReceive('get')->andReturn(json_encode([
            'name' => 'New Mod',
            'slug' => 'newmod',
            'version' => '1.0.0',
            'description' => 'desc',
            'author' => 'test',
            'dependencies' => [],
        ]));
        File::shouldReceive('move')->andReturn(true);
        File::shouldReceive('copyDirectory')->andReturn(true);
        File::shouldReceive('deleteDirectory')->andReturn(true);

        Artisan::shouldReceive('call')->andReturnNull();

        $service = new PluginManagerService();

        // Act
        $res = $service->installPlugin('newmod');

        // Assert
        $this->assertTrue($res['success']);
        $this->assertDatabaseHas('installed_plugins', ['slug' => 'newmod']);
    }

    public function test_uninstall_plugin_removes_database_record()
    {
        // Arrange: create installed plugin record
        InstalledPlugin::create([
            'name' => 'ToRemove',
            'slug' => 'toremove',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
        ]);

        // Ensure filesystem checks don't try to roll back migrations or delete assets
        File::shouldReceive('exists')->andReturn(false);
        Artisan::shouldReceive('call')->andReturnNull();

        $service = new PluginManagerService();

        // Act
        $res = $service->uninstallPlugin('toremove');

        // Assert
        $this->assertTrue($res['success']);
        $this->assertDatabaseMissing('installed_plugins', ['slug' => 'toremove']);
    }

    public function test_install_plugin_upgrade_rolls_back_on_move_failure()
    {
        // Arrange: existing installed module
        InstalledPlugin::create([
            'name' => 'Up Mod',
            'slug' => 'upmod',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
        ]);

        // Filesystem: staging + installed exist, but move will fail
        File::shouldReceive('exists')->andReturnUsing(function ($path) {
            if (str_ends_with($path, '/src/Installer.php')) {
                return false;
            }
            return true;
        });

        File::shouldReceive('get')->andReturn(json_encode([
            'name' => 'Up Mod',
            'slug' => 'upmod',
            'version' => '2.0.0',
            'description' => 'upgrade',
            'author' => 'test',
            'dependencies' => [],
        ]));

        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('copyDirectory')->andReturn(true);
        File::shouldReceive('deleteDirectory')->andReturn(true);
        File::shouldReceive('move')->andThrow(new \Exception('move failed'));

        Artisan::shouldReceive('call')->andReturnNull();

        $service = new PluginManagerService();

        // Act
        $res = $service->installPlugin('upmod');

        // Assert: install failed and original DB record still has old version
        $this->assertFalse($res['success']);
        $this->assertDatabaseHas('installed_plugins', ['slug' => 'upmod', 'version' => '1.0.0']);
    }

    // ── checkDependencies happy paths ──────────────────────────────────────────

    public function test_check_dependencies_satisfied_when_version_matches()
    {
        InstalledPlugin::create([
            'name' => 'Dep Mod Sat',
            'slug' => 'depmodsat',
            'version' => '2.3.0',
            'type' => 'plugin',
            'enabled' => true,
        ]);

        $service = new PluginManagerService();
        // checkDependencies expects a full plugin.json structure
        $pluginJson = ['requires' => ['plugins' => ['depmodsat' => '^2.0.0']]];

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('checkDependencies');
        $method->setAccessible(true);

        $result = $method->invokeArgs($service, [$pluginJson]);

        $this->assertTrue($result['satisfied']);
        $this->assertEmpty($result['missing']);
    }

    public function test_check_dependencies_satisfied_when_empty()
    {
        $service = new PluginManagerService();

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('checkDependencies');
        $method->setAccessible(true);

        // checkDependencies expects a full plugin.json structure
        $pluginJson = ['requires' => ['plugins' => []]];

        $result = $method->invokeArgs($service, [$pluginJson]);

        $this->assertTrue($result['satisfied']);
        $this->assertEmpty($result['missing']);
    }

    // ── enablePlugin success ───────────────────────────────────────────────────

    public function test_enable_plugin_succeeds_for_disabled_plugin()
    {
        InstalledPlugin::create([
            'name' => 'Dormant',
            'slug' => 'dormant',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => false,
        ]);

        // No plugin directory on disk — findModuleDirectory returns null,
        // so the dependency re-check is skipped and enable() proceeds directly.
        File::shouldReceive('exists')->andReturn(false);
        Artisan::shouldReceive('call')->andReturnNull();

        $service = new PluginManagerService();
        $res = $service->enablePlugin('dormant');

        $this->assertTrue($res['success']);
        $this->assertStringContainsString('enabled successfully', strtolower($res['message']));
        $this->assertDatabaseHas('installed_plugins', ['slug' => 'dormant', 'enabled' => true]);
    }

    // ── installPlugin error paths ──────────────────────────────────────────────

    public function test_install_plugin_returns_error_when_staging_not_found()
    {
        File::shouldReceive('exists')->andReturn(false);

        $service = new PluginManagerService();
        $res = $service->installPlugin('ghostplugin');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('not found in staging', strtolower($res['message']));
    }

    // ── installPlugin upgrade: success ────────────────────────────────────────

    public function test_install_plugin_upgrade_success_updates_record()
    {
        // Arrange: existing installed module
        InstalledPlugin::create([
            'name' => 'Up Mod 2',
            'slug' => 'upmod2',
            'version' => '1.0.0',
            'type' => 'plugin',
            'enabled' => true,
        ]);

        // Filesystem: staging and installed exist; move succeeds
        File::shouldReceive('exists')->andReturnUsing(function ($path) {
            if (str_ends_with($path, '/src/Installer.php')) {
                return false;
            }
            return true;
        });

        File::shouldReceive('get')->andReturn(json_encode([
            'name' => 'Up Mod 2',
            'slug' => 'upmod2',
            'version' => '2.0.0',
            'description' => 'upgrade 2',
            'author' => 'test',
            'dependencies' => [],
        ]));

        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('copyDirectory')->andReturn(true);
        File::shouldReceive('deleteDirectory')->andReturn(true);
        File::shouldReceive('move')->andReturn(true);

        Artisan::shouldReceive('call')->andReturnNull();

        $service = new PluginManagerService();

        // Act
        $res = $service->installPlugin('upmod2');

        // Assert: install succeeded and DB updated to new version
        $this->assertTrue($res['success']);
        $this->assertDatabaseHas('installed_plugins', ['slug' => 'upmod2', 'version' => '2.0.0']);
    }
}
