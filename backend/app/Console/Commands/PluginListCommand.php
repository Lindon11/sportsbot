<?php

namespace App\Console\Commands;

use App\Core\Services\PluginBundleService;
use Illuminate\Console\Command;

/**
 * Plugin List Command
 *
 * Lists all available plugins with their status, version, and paths.
 *
 * Usage:
 *   php artisan hub:list
 *   php artisan hub:list --enabled
 *   php artisan hub:list --disabled
 *   php artisan hub:list --json
 */
class PluginListCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hub:list
                            {--enabled : Show only enabled plugins}
                            {--disabled : Show only disabled plugins}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'List all available plugins with their status';

    /**
     * Execute the console command.
     */
    public function handle(PluginBundleService $bundleService): int
    {
        $plugins = $bundleService->list();

        // Filter based on options
        if ($this->option('enabled')) {
            $plugins = array_filter($plugins, fn($p) => $p['enabled']);
        }

        if ($this->option('disabled')) {
            $plugins = array_filter($plugins, fn($p) => !$p['enabled']);
        }

        if (empty($plugins)) {
            $this->info("No plugins found.");
            return 0;
        }

        // Output as JSON if requested
        if ($this->option('json')) {
            $this->line(json_encode($plugins, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        // Display as table
        $this->displayTable($plugins);

        // Summary
        $this->newLine();
        $enabledCount = count(array_filter($plugins, fn($p) => $p['enabled']));
        $totalCount = count($plugins);
        $this->info("Total: {$totalCount} plugins ({$enabledCount} enabled, " . ($totalCount - $enabledCount) . " disabled)");

        return 0;
    }

    /**
     * Display plugins as a formatted table.
     */
    protected function displayTable(array $plugins): void
    {
        $headers = ['Name', 'Slug', 'Version', 'Status', 'Backend', 'Frontend', 'Dependencies'];
        $rows = [];

        foreach ($plugins as $plugin) {
            $status = $plugin['enabled']
                ? '<info>✓ Enabled</info>'
                : '<comment>✗ Disabled</comment>';

            $backend = $plugin['has_backend'] ? '<info>✓</info>' : '<comment>✗</comment>';
            $frontend = $plugin['has_frontend'] ? '<info>✓</info>' : '<comment>✗</comment>';

            $dependencies = [];
            foreach ($plugin['dependencies'] as $dep => $version) {
                $dependencies[] = "{$dep} ({$version})";
            }
            $depsStr = empty($dependencies) ? '-' : implode(', ', $dependencies);

            $rows[] = [
                $plugin['name'],
                $plugin['slug'],
                $plugin['version'],
                $status,
                $backend,
                $frontend,
                $depsStr,
            ];
        }

        $this->table($headers, $rows);
    }
}
