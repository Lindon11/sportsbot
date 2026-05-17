<?php

namespace App\Console\Commands;

use App\Core\Services\PluginBundleService;
use Illuminate\Console\Command;

/**
 * Plugin Export Command
 *
 * Exports a plugin to a distributable ZIP bundle containing
 * both backend PHP files and frontend Vue components.
 *
 * Usage:
 *   php artisan hub:export Bank
 *   php artisan hub:export mini-rpg --output=/path/to/bundles
 */
class PluginExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hub:export
                            {plugin : The plugin slug or name to export}
                            {--output= : Output directory path (defaults to Downloads folder)}';

    /**
     * The console command description.
     */
    protected $description = 'Export a plugin to a distributable ZIP bundle';

    /**
     * Execute the console command.
     */
    public function handle(PluginBundleService $bundleService): int
    {
        $pluginSlug = $this->argument('plugin');
        $outputPath = $this->option('output');

        $this->info("Exporting plugin '{$pluginSlug}'...");

        try {
            $bundlePath = $bundleService->export($pluginSlug, $outputPath);

            $this->newLine();
            $this->info("✓ Plugin exported successfully!");
            $this->line("  Bundle: <comment>{$bundlePath}</comment>");

            // Get file size
            $size = filesize($bundlePath);
            $this->line("  Size: <comment>" . $this->formatBytes($size) . "</comment>");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to export plugin: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Format bytes to human-readable size.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor] ?? 'TB');
    }
}
