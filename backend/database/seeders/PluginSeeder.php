<?php

namespace Database\Seeders;

use App\Core\Models\Plugin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PluginSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting Plugin seeding...');

        $pluginsPath = app_path('Plugins');

        if (!File::exists($pluginsPath)) {
            $this->command->warn("Plugins directory does not exist: {$pluginsPath}");
            return;
        }

        $pluginDirs = File::directories($pluginsPath);
        $seededCount = 0;
        $skippedCount = 0;

        foreach ($pluginDirs as $pluginDir) {
            $pluginName = basename($pluginDir);

            // Skip the base Plugin.php file
            if ($pluginName === 'Plugin.php') {
                $skippedCount++;
                continue;
            }

            $pluginJsonPath = $pluginDir . '/plugin.json';

            // Also check for module.json for backwards compatibility
            if (!File::exists($pluginJsonPath)) {
                $pluginJsonPath = $pluginDir . '/module.json';
            }

            if (!File::exists($pluginJsonPath)) {
                $this->command->warn("No plugin.json found for: {$pluginName}");
                $skippedCount++;
                continue;
            }

            $pluginData = json_decode(File::get($pluginJsonPath), true);

            if ($pluginData === null) {
                $this->command->error("Invalid JSON in {$pluginName}/plugin.json");
                $skippedCount++;
                continue;
            }

            $this->seedPlugin($pluginName, $pluginData, $pluginJsonPath);
            $seededCount++;
        }

        $this->command->info("Plugin seeding complete. Seeded: {$seededCount}, Skipped: {$skippedCount}");
    }

    /**
     * Seed a single plugin from its plugin.json data
     */
    protected function seedPlugin(string $pluginName, array $pluginData, string $pluginJsonPath): void
    {
        // Extract navigation config from settings.menu if it exists
        $navigationConfig = null;
        $menuSettings = $pluginData['settings']['menu'] ?? null;

        if ($menuSettings) {
            $navigationConfig = [
                'section' => $menuSettings['section'] ?? 'main',
                'order' => $menuSettings['order'] ?? 100,
                'color' => $pluginData['settings']['color'] ?? 'bg-gray-600',
                'icon' => $pluginData['settings']['icon'] ?? null,
                'enabled' => $menuSettings['enabled'] ?? true,
            ];
        }

        // Extract route from settings
        $routeName = null;
        if (!empty($pluginData['settings']['route'])) {
            $routeName = $pluginData['settings']['route'];
        }

        // Get icon from settings
        $icon = $pluginData['settings']['icon'] ?? null;

        // Determine enabled status (default to true if not specified)
        $enabled = $pluginData['enabled'] ?? true;

        // Get order from menu settings
        $order = $menuSettings['order'] ?? $pluginData['order'] ?? 100;

        // Check if plugin already exists
        $existingPlugin = Plugin::where('name', $pluginName)->first();

        if ($existingPlugin) {
            // Update existing plugin
            $existingPlugin->update([
                'display_name' => $pluginData['name'] ?? $pluginName,
                'description' => $pluginData['description'] ?? '',
                'icon' => $icon,
                'route_name' => $routeName,
                'enabled' => $enabled,
                'order' => $order,
                'navigation_config' => $navigationConfig,
            ]);

            $this->command->info("Updated plugin: {$pluginName}");
            Log::info("PluginSeeder: Updated plugin {$pluginName}");
        } else {
            // Create new plugin
            Plugin::create([
                'name' => $pluginName,
                'display_name' => $pluginData['name'] ?? $pluginName,
                'description' => $pluginData['description'] ?? '',
                'icon' => $icon,
                'route_name' => $routeName,
                'enabled' => $enabled,
                'order' => $order,
                'required_level' => $pluginData['requires']['level'] ?? 1,
                'navigation_config' => $navigationConfig,
                'settings' => $pluginData['settings'] ?? [],
            ]);

            $this->command->info("Created plugin: {$pluginName}");
            Log::info("PluginSeeder: Created plugin {$pluginName}");
        }
    }
}
