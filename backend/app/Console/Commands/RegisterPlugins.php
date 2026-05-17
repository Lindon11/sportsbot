<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Core\Models\InstalledPlugin;

class RegisterPlugins extends Command
{
    protected $signature = 'app:register-plugins {--migrate : Run plugin migrations if present} {--force : Overwrite existing records}';

    protected $description = 'Register plugins found in app/Plugins into installed_plugins table';

    public function handle()
    {
        $pluginsPath = app_path('Plugins');

        if (!File::exists($pluginsPath)) {
            $this->error('Plugins directory does not exist: ' . $pluginsPath);
            return 1;
        }

        $dirs = File::directories($pluginsPath);
        $count = 0;

        foreach ($dirs as $dir) {
            $slug = strtolower(basename($dir));
            $jsonPath = $dir . '/plugin.json';

            if (!File::exists($jsonPath)) {
                $this->warn("Skipping {$slug}: plugin.json not found");
                continue;
            }

            $data = json_decode(File::get($jsonPath), true);
            if (!is_array($data)) {
                $this->warn("Skipping {$slug}: invalid plugin.json");
                continue;
            }

            // Prefer explicit slug from plugin.json when present (preserves hyphens),
            // but fall back to directory name if missing.
            $manifestSlug = isset($data['slug']) ? strtolower($data['slug']) : $slug;
            // Also keep a folder-based slug for legacy compatibility
            $folderSlug = $slug;

            // Check both possible slug forms for existing records
            $exists = InstalledPlugin::where('slug', $manifestSlug)->orWhere('slug', $folderSlug)->first();
            if ($exists && !$this->option('force')) {
                $this->line("Already registered: {$manifestSlug}");
                continue;
            }

            $record = [
                'name' => $data['name'] ?? ucfirst($manifestSlug),
                'slug' => $manifestSlug,
                'version' => $data['version'] ?? '1.0.0',
                'type' => 'plugin',
                'description' => $data['description'] ?? '',
                'dependencies' => $data['requires']['plugins'] ?? [],
                'config' => $data['settings'] ?? [],
                'enabled' => $data['enabled'] ?? false,
                'installed_at' => now(),
            ];

            if ($exists) {
                $exists->update($record);
                $this->info("Updated plugin record: {$slug}");
                $count++;
            } else {
                InstalledPlugin::create($record);
                $this->info("Registered plugin: {$slug}");
                $count++;
            }

            // Optionally run plugin migrations
            if ($this->option('migrate')) {
                $migrationsPath = "app/Plugins/" . basename($dir) . "/database/migrations";
                if (File::exists(base_path($migrationsPath))) {
                    $this->call('migrate', ['--path' => $migrationsPath, '--force' => true]);
                    $this->info("Ran migrations for: {$slug}");
                }
            }
        }

        $this->info("Registered/updated {$count} plugins.");
        return 0;
    }
}
