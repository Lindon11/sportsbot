<?php
// Auto-load all plugin hooks at boot
namespace App\Core\Providers;

use App\Core\Models\InstalledPlugin;
use App\Core\Services\GameHooks;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class AutoPluginHookLoader extends ServiceProvider
{
    public function boot(): void
    {
        // Load enabled slugs from DB once to avoid N+1 queries
        $enabledSlugs = [];
        try {
            $enabledSlugs = InstalledPlugin::plugins()
                ->where('enabled', true)
                ->pluck('slug')
                ->flip()
                ->toArray();
        } catch (\Throwable $e) {
            // DB may not be available (e.g. during migration runs) — fall back to allowing all
        }
        $dbAvailable = !empty($enabledSlugs) || $this->dbIsAccessible();

        // Use discovered plugin metadata if available
        $plugins = app()->bound('plugins') ? app('plugins') : null;
        if (is_array($plugins) && count($plugins)) {
            foreach ($plugins as $plugin) {
                $slug = strtolower($plugin['slug'] ?? basename($plugin['path'] ?? ''));
                if ($dbAvailable && !isset($enabledSlugs[$slug])) {
                    continue;
                }
                $hooksFile = $plugin['path'] . '/hooks.php';
                if (File::exists($hooksFile)) {
                    $this->loadHooksFile($hooksFile);
                }
            }
            return;
        }

        // Fallback: scan plugin directories (legacy, if no metadata)
        $pluginsPath = app_path('Plugins');
        if (!File::exists($pluginsPath)) return;
        $pluginDirs = File::directories($pluginsPath);
        foreach ($pluginDirs as $pluginDir) {
            $slug = strtolower(basename($pluginDir));
            if ($dbAvailable && !isset($enabledSlugs[$slug])) {
                continue;
            }
            $hooksFile = $pluginDir . '/hooks.php';
            if (File::exists($hooksFile)) {
                require_once $hooksFile;
            }
        }
    }

    /**
     * Load a hooks.php file, supporting both formats:
     * - Declarative (return ['hookName' => closure]) — registers on both GameHooks and HookService
     * - Side-effect (calls Hook::register() directly) — already registered during require_once
     */
    private function loadHooksFile(string $path): void
    {
        $result = require_once $path;

        if (is_array($result)) {
            foreach ($result as $hookName => $callback) {
                if (is_string($hookName) && is_callable($callback)) {
                    GameHooks::listen($hookName, $callback);
                    if (app()->bound('hook')) {
                        app('hook')->register($hookName, \Closure::fromCallable($callback));
                    }
                }
            }
        }
        // Side-effect format (Hook::register() calls in file body) is handled by the require_once above
    }

    private function dbIsAccessible(): bool
    {
        try {
            \DB::connection()->getPdo();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
