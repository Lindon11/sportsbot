<?php

namespace App\Console\Commands;

use App\Core\Services\PluginBundleService;
use Illuminate\Console\Command;

/**
 * Plugin Remove Command
 *
 * Removes a plugin completely from both backend and frontend.
 * Optionally rolls back database migrations.
 *
 * Usage:
 *   php artisan hub:remove Bank
 *   php artisan hub:remove Bank --force
 *   php artisan hub:remove Bank --keep-migrations
 */
class PluginRemoveCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hub:remove
                            {plugin : The plugin slug or name to remove}
                            {--force : Force removal even if plugin is enabled}
                            {--keep-migrations : Keep database migrations}';

    /**
     * The console command description.
     */
    protected $description = 'Remove a plugin completely (backend + frontend)';

    /**
     * Execute the console command.
     */
    public function handle(PluginBundleService $bundleService): int
    {
        $pluginSlug = $this->argument('plugin');
        $force = $this->option('force');
        $keepMigrations = $this->option('keep-migrations');

        $this->info("Removing plugin '{$pluginSlug}'...");

        try {
            // Confirm removal
            if (!$this->confirmRemoval($pluginSlug, $force, $keepMigrations)) {
                $this->info("Removal cancelled.");
                return 0;
            }

            $result = $bundleService->remove($pluginSlug, $force, $keepMigrations);

            $this->newLine();
            $this->info("✓ Plugin removed successfully!");
            $this->line("  Name: <comment>{$result['plugin_name']}</comment>");
            $this->line("  Slug: <comment>{$result['plugin_slug']}</comment>");

            $this->newLine();
            $this->info("Removed paths:");
            foreach ($result['removed_paths'] as $path) {
                $this->line("  - <comment>{$path}</comment>");
            }

            if (!$keepMigrations) {
                $this->newLine();
                $this->warn("Note: Database migrations were rolled back.");
                $this->line("If you had data in plugin tables, it has been removed.");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to remove plugin: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Confirm the removal action with the user.
     */
    protected function confirmRemoval(string $pluginSlug, bool $force, bool $keepMigrations): bool
    {
        $this->warn("This will permanently remove the plugin '{$pluginSlug}'.");
        $this->newLine();

        $warnings = [];

        if (!$keepMigrations) {
            $warnings[] = "Database migrations will be rolled back (data will be lost)";
        } else {
            $warnings[] = "Database migrations will be kept (tables may remain)";
        }

        $warnings[] = "All plugin files will be deleted from backend";
        $warnings[] = "All plugin files will be deleted from frontend";
        $warnings[] = "User plugin metadata will be removed";

        foreach ($warnings as $warning) {
            $this->line("  - <comment>{$warning}</comment>");
        }

        $this->newLine();

        return $this->confirm("Are you sure you want to remove this plugin?", false);
    }
}
