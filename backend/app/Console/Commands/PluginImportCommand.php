<?php

namespace App\Console\Commands;

use App\Core\Services\PluginBundleService;
use Illuminate\Console\Command;

/**
 * Plugin Import Command
 *
 * Imports a plugin bundle from a ZIP file.
 * Extracts both backend PHP files and frontend Vue components.
 *
 * Usage:
 *   php artisan hub:import /path/to/bank-bundle.zip
 *   php artisan hub:import /path/to/bank-bundle.zip --enable
 */
class PluginImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hub:import
                            {bundle : Path to the plugin bundle ZIP file}
                            {--enable : Enable the plugin after import}';

    /**
     * The console command description.
     */
    protected $description = 'Import a plugin bundle from a ZIP file';

    /**
     * Execute the console command.
     */
    public function handle(PluginBundleService $bundleService): int
    {
        $bundlePath = $this->argument('bundle');
        $enable = $this->option('enable');

        // Resolve relative path
        if (!file_exists($bundlePath)) {
            $absolutePath = realpath($bundlePath);
            if (!$absolutePath) {
                $this->error("Bundle file not found: {$bundlePath}");
                return 1;
            }
            $bundlePath = $absolutePath;
        }

        $this->info("Importing plugin bundle...");
        $this->line("  Bundle: <comment>{$bundlePath}</comment>");

        try {
            $result = $bundleService->import($bundlePath, $enable);

            $this->newLine();
            $this->info("✓ Plugin imported successfully!");
            $this->line("  Name: <comment>{$result['plugin_name']}</comment>");
            $this->line("  Slug: <comment>{$result['plugin_slug']}</comment>");
            $this->line("  Version: <comment>{$result['plugin_version']}</comment>");
            $this->line("  Backend: <comment>{$result['backend_path']}</comment>");

            if ($result['frontend_path']) {
                $this->line("  Frontend: <comment>{$result['frontend_path']}</comment>");
            }

            if ($result['enabled']) {
                $this->line("  Status: <info>Enabled</info>");
            } else {
                $this->line("  Status: <comment>Disabled</comment>");
                $this->newLine();
                $this->info("To enable the plugin:");
                $this->line("  1. Edit the plugin.json and set 'enabled' to true");
                $this->line("  2. Or run: php artisan hub:enable {$result['plugin_slug']}");
            }

            $this->newLine();
            $this->info("Next steps:");
            $this->line("  1. Run <comment>php artisan migrate</comment> if the plugin has migrations");
            $this->line("  2. Run <comment>npm run build</comment> to compile frontend assets");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to import plugin: {$e->getMessage()}");
            return 1;
        }
    }
}
