<?php

namespace App\Console\Commands;

use App\Core\Services\PluginBundleService;
use Illuminate\Console\Command;

/**
 * Plugin Export All Command
 *
 * Exports all plugins to distributable ZIP bundles.
 *
 * Usage:
 *   php artisan hub:export-all
 *   php artisan hub:export-all --output=/path/to/bundles
 */
class PluginExportAllCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hub:export-all
                            {--output= : Output directory path (defaults to Downloads folder)}';

    /**
     * The console command description.
     */
    protected $description = 'Export all plugins to distributable ZIP bundles';

    /**
     * Execute the console command.
     */
    public function handle(PluginBundleService $bundleService): int
    {
        $outputPath = $this->option('output');

        $this->info("Exporting all plugins...");

        if ($outputPath) {
            $this->line("  Output: <comment>{$outputPath}</comment>");
        }

        $this->newLine();

        try {
            $results = $bundleService->exportAll($outputPath);

            // Display exported plugins
            if (!empty($results['exported'])) {
                $this->info("Exported plugins:");
                foreach ($results['exported'] as $exported) {
                    $size = filesize($exported['bundle_path']);
                    $this->line("  ✓ <info>{$exported['name']}</info> ({$this->formatBytes($size)})");
                    $this->line("    <comment>{$exported['bundle_path']}</comment>");
                }
            }

            // Display failed exports
            if (!empty($results['failed'])) {
                $this->newLine();
                $this->error("Failed to export:");
                foreach ($results['failed'] as $failed) {
                    $this->line("  ✗ <comment>{$failed['name']}</comment>: {$failed['error']}");
                }
            }

            $this->newLine();
            $this->info("Summary:");
            $this->line("  Exported: <info>" . count($results['exported']) . "</info> plugins");

            if (!empty($results['failed'])) {
                $this->line("  Failed: <comment>" . count($results['failed']) . "</comment> plugins");
            }

            return empty($results['failed']) ? 0 : 1;
        } catch (\Exception $e) {
            $this->error("Failed to export plugins: {$e->getMessage()}");
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
