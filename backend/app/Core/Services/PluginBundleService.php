<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Plugin Bundle Service
 *
 * Handles exporting, importing, and removing plugin bundles.
 * Each bundle contains both backend PHP files and frontend Vue components.
 */
class PluginBundleService
{
    /**
     * Backend plugins directory
     */
    protected string $backendPluginsPath;

    /**
     * Frontend plugins directory
     */
    protected string $frontendPluginsPath;

    /**
     * Frontend views/plugins directory (legacy location)
     */
    protected string $frontendViewsPath;

    /**
     * Files/directories to exclude from bundles
     */
    protected array $excludePatterns = [
        '.DS_Store',
        '.git',
        '.gitignore',
        '__pycache__',
        'node_modules',
        '*.test.php',
        '*.spec.php',
    ];

    public function __construct()
    {
        $this->backendPluginsPath = app_path('Plugins');
        $this->frontendPluginsPath = base_path('frontend/src/plugins');
        $this->frontendViewsPath = base_path('frontend/src/views/plugins');
    }

    /**
     * Export a plugin to a bundle ZIP file.
     *
     * @param string $pluginSlug Plugin slug (e.g., 'bank', 'mini-rpg')
     * @param string|null $outputPath Output directory path (defaults to user's Downloads)
     * @return string Path to the created bundle
     * @throws \Exception
     */
    public function export(string $pluginSlug, ?string $outputPath = null): string
    {
        // Find plugin directory (case-insensitive)
        $backendPath = $this->findPluginDirectory($pluginSlug);

        if (!$backendPath) {
            throw new \Exception("Plugin '{$pluginSlug}' not found in backend.");
        }

        // Load plugin manifest
        $manifest = $this->loadManifest($backendPath);
        $actualSlug = $manifest['slug'] ?? basename($backendPath);

        // Determine output path
        if (!$outputPath) {
            $outputPath = getenv('HOME') . '/Downloads';
        }

        // Ensure output directory exists
        if (!File::isDirectory($outputPath)) {
            File::makeDirectory($outputPath, 0755, true);
        }

        $bundleFilename = "{$actualSlug}-bundle.zip";
        $bundlePath = rtrim($outputPath, '/') . '/' . $bundleFilename;

        // Create ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($bundlePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Failed to create bundle at '{$bundlePath}'.");
        }

        // Add bundle metadata
        $bundleMeta = [
            'version' => '1.0.0',
            'format' => 'laravelcp-plugin-bundle',
            'created_at' => now()->toIso8601String(),
            'plugin_slug' => $actualSlug,
            'plugin_name' => $manifest['name'] ?? $actualSlug,
            'plugin_version' => $manifest['version'] ?? '1.0.0',
            'checksums' => [],
        ];

        // Add backend files
        $backendFilesAdded = $this->addDirectoryToZip(
            $zip,
            $backendPath,
            'backend',
            $bundleMeta['checksums']
        );

        // Find and add frontend files
        $frontendPath = $this->findFrontendPluginDirectory($actualSlug);
        if ($frontendPath) {
            $this->addDirectoryToZip(
                $zip,
                $frontendPath,
                'frontend',
                $bundleMeta['checksums']
            );
        }

        // Add bundle.json metadata
        $zip->addFromString('bundle.json', json_encode($bundleMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip->close();

        Log::info("Plugin bundle exported", [
            'plugin' => $actualSlug,
            'bundle_path' => $bundlePath,
            'backend_files' => $backendFilesAdded,
        ]);

        return $bundlePath;
    }

    /**
     * Import a plugin bundle from a ZIP file.
     *
     * @param string $bundlePath Path to the bundle ZIP file
     * @param bool $enable Whether to enable the plugin after import
     * @return array Import result with plugin info
     * @throws \Exception
     */
    public function import(string $bundlePath, bool $enable = false): array
    {
        if (!File::exists($bundlePath)) {
            throw new \Exception("Bundle file not found: {$bundlePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($bundlePath) !== true) {
            throw new \Exception("Failed to open bundle: {$bundlePath}");
        }

        // Extract bundle metadata
        $bundleJson = $zip->getFromName('bundle.json');
        if (!$bundleJson) {
            $zip->close();
            throw new \Exception("Invalid bundle: missing bundle.json");
        }

        $bundleMeta = json_decode($bundleJson, true);
        $pluginSlug = $bundleMeta['plugin_slug'] ?? null;

        if (!$pluginSlug) {
            $zip->close();
            throw new \Exception("Invalid bundle: missing plugin_slug");
        }

        // Verify checksums if present
        if (!empty($bundleMeta['checksums'])) {
            $this->verifyChecksums($zip, $bundleMeta['checksums']);
        }

        // Check if plugin already exists
        $existingBackend = $this->findPluginDirectory($pluginSlug);
        $existingFrontend = $this->findFrontendPluginDirectory($pluginSlug);

        // Extract backend files
        $backendTarget = $this->backendPluginsPath . '/' . Str::studly($pluginSlug);
        $this->extractFromZip($zip, 'backend/', $backendTarget);

        // Extract frontend files
        $frontendTarget = $this->frontendPluginsPath . '/' . $pluginSlug;
        $this->extractFromZip($zip, 'frontend/', $frontendTarget);

        $zip->close();

        // Load manifest
        $manifest = $this->loadManifest($backendTarget);

        // Update manifest enabled state if needed
        if ($enable) {
            $manifest['enabled'] = true;
            File::put(
                $backendTarget . '/plugin.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        // Create or update InstalledPlugin record
        $installedPlugin = \App\Core\Models\InstalledPlugin::updateOrCreate(
            ['slug' => $pluginSlug],
            [
                'name' => $manifest['name'] ?? $pluginSlug,
                'version' => $manifest['version'] ?? '1.0.0',
                'type' => 'plugin',
                'description' => $manifest['description'] ?? '',
                'author' => $manifest['author'] ?? 'Unknown',
                'license_required' => $manifest['license_required'] ?? false,
                'dependencies' => $manifest['requires']['plugins'] ?? [],
                'config' => $manifest['settings'] ?? [],
                'enabled' => $enable ?: ($manifest['enabled'] ?? true),
                'installed_at' => now(),
                'icon' => $manifest['settings']['icon'] ?? null,
                'color' => $manifest['settings']['color'] ?? null,
                'route_name' => $manifest['settings']['route'] ?? null,
                'order' => $manifest['settings']['menu']['order'] ?? 100,
                'permissions' => $manifest['permissions'] ?? [],
                'hooks' => $manifest['hooks'] ?? [],
                'frontend_slots' => $manifest['frontend']['slots'] ?? [],
                'frontend_routes' => $manifest['frontend']['routes'] ?? [],
                'has_web_routes' => $manifest['routes']['web'] ?? false,
                'has_api_routes' => $manifest['routes']['api'] ?? false,
                'has_admin_routes' => $manifest['routes']['admin'] ?? false,
            ]
        );

        Log::info("Plugin bundle imported", [
            'plugin' => $pluginSlug,
            'bundle_path' => $bundlePath,
            'backend_path' => $backendTarget,
            'frontend_path' => $frontendTarget,
            'enabled' => $installedPlugin->enabled,
            'db_record' => $installedPlugin->id,
        ]);

        return [
            'success' => true,
            'plugin_slug' => $pluginSlug,
            'plugin_name' => $manifest['name'] ?? $pluginSlug,
            'plugin_version' => $manifest['version'] ?? '1.0.0',
            'backend_path' => $backendTarget,
            'frontend_path' => $frontendTarget,
            'enabled' => $installedPlugin->enabled,
        ];
    }

    /**
     * Remove a plugin completely (backend + frontend).
     *
     * @param string $pluginSlug Plugin slug
     * @param bool $force Force removal even if plugin is enabled
     * @param bool $keepMigrations Keep database migrations
     * @return array Removal result
     * @throws \Exception
     */
    public function remove(string $pluginSlug, bool $force = false, bool $keepMigrations = false): array
    {
        // Find plugin directories
        $backendPath = $this->findPluginDirectory($pluginSlug);
        $frontendPath = $this->findFrontendPluginDirectory($pluginSlug);

        if (!$backendPath && !$frontendPath) {
            throw new \Exception("Plugin '{$pluginSlug}' not found.");
        }

        $manifest = null;
        $removedPaths = [];

        // Remove backend
        if ($backendPath) {
            // Load manifest before removal
            $manifest = $this->loadManifest($backendPath);

            // Check if plugin is enabled
            if (!$force && ($manifest['enabled'] ?? false)) {
                throw new \Exception("Plugin '{$pluginSlug}' is enabled. Disable it first or use --force.");
            }

            // Call plugin's uninstall method if available
            $this->callPluginUninstall($pluginSlug, $backendPath);

            // Remove plugin metadata from database
            \App\Core\Models\PluginMetadata::where('plugin_id', $pluginSlug)->delete();

            // Optionally run down migrations
            if (!$keepMigrations) {
                $this->rollbackMigrations($backendPath);
            }

            // Remove directory
            File::deleteDirectory($backendPath);
            $removedPaths[] = $backendPath;
        }

        // Remove frontend
        if ($frontendPath) {
            File::deleteDirectory($frontendPath);
            $removedPaths[] = $frontendPath;
        }

        // Also check for legacy frontend views
        $legacyFrontendPath = $this->frontendViewsPath . '/' . Str::studly($pluginSlug) . 'View.vue';
        if (File::exists($legacyFrontendPath)) {
            File::delete($legacyFrontendPath);
            $removedPaths[] = $legacyFrontendPath;
        }

        Log::info("Plugin removed", [
            'plugin' => $pluginSlug,
            'removed_paths' => $removedPaths,
        ]);

        return [
            'success' => true,
            'plugin_slug' => $pluginSlug,
            'plugin_name' => $manifest['name'] ?? $pluginSlug,
            'removed_paths' => $removedPaths,
        ];
    }

    /**
     * List all available plugins with their status.
     *
     * @return array List of plugins
     */
    public function list(): array
    {
        $plugins = [];

        // Scan backend plugins
        if (File::isDirectory($this->backendPluginsPath)) {
            $directories = File::directories($this->backendPluginsPath);

            foreach ($directories as $dir) {
                $slug = basename($dir);

                // Skip base Plugin.php
                if ($slug === 'Plugin.php' || $slug === 'README.md') {
                    continue;
                }

                $manifest = $this->loadManifest($dir);
                $frontendPath = $this->findFrontendPluginDirectory($slug);

                $plugins[] = [
                    'slug' => $manifest['slug'] ?? Str::kebab($slug),
                    'name' => $manifest['name'] ?? $slug,
                    'version' => $manifest['version'] ?? '1.0.0',
                    'description' => $manifest['description'] ?? '',
                    'author' => $manifest['author'] ?? 'Unknown',
                    'enabled' => $manifest['enabled'] ?? false,
                    'has_backend' => true,
                    'has_frontend' => $frontendPath !== null,
                    'backend_path' => $dir,
                    'frontend_path' => $frontendPath,
                    'dependencies' => $manifest['requires']['plugins'] ?? [],
                ];
            }
        }

        // Sort by name
        usort($plugins, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $plugins;
    }

    /**
     * Export all plugins to a directory.
     *
     * @param string|null $outputPath Output directory
     * @return array Results with exported bundles
     */
    public function exportAll(?string $outputPath = null): array
    {
        $plugins = $this->list();
        $results = [
            'exported' => [],
            'failed' => [],
        ];

        foreach ($plugins as $plugin) {
            try {
                $bundlePath = $this->export($plugin['slug'], $outputPath);
                $results['exported'][] = [
                    'slug' => $plugin['slug'],
                    'name' => $plugin['name'],
                    'bundle_path' => $bundlePath,
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'slug' => $plugin['slug'],
                    'name' => $plugin['name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Find plugin directory (case-insensitive).
     */
    protected function findPluginDirectory(string $pluginSlug): ?string
    {
        // Try exact match first
        $exactPath = $this->backendPluginsPath . '/' . $pluginSlug;
        if (File::isDirectory($exactPath)) {
            return $exactPath;
        }

        // Try studly case
        $studlyPath = $this->backendPluginsPath . '/' . Str::studly($pluginSlug);
        if (File::isDirectory($studlyPath)) {
            return $studlyPath;
        }

        // Try case-insensitive search
        if (File::isDirectory($this->backendPluginsPath)) {
            $directories = File::directories($this->backendPluginsPath);
            foreach ($directories as $dir) {
                if (strtolower(basename($dir)) === strtolower($pluginSlug)) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * Find frontend plugin directory.
     */
    protected function findFrontendPluginDirectory(string $pluginSlug): ?string
    {
        // Try kebab-case (e.g., mini-rpg)
        $kebabPath = $this->frontendPluginsPath . '/' . Str::kebab($pluginSlug);
        if (File::isDirectory($kebabPath)) {
            return $kebabPath;
        }

        // Try studly case (e.g., MiniRpg)
        $studlyPath = $this->frontendPluginsPath . '/' . Str::studly($pluginSlug);
        if (File::isDirectory($studlyPath)) {
            return $studlyPath;
        }

        return null;
    }

    /**
     * Load plugin manifest.
     */
    protected function loadManifest(string $pluginPath): array
    {
        $manifestPath = $pluginPath . '/plugin.json';

        if (!File::exists($manifestPath)) {
            return [];
        }

        $content = File::get($manifestPath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Add a directory to ZIP archive.
     *
     * @return int Number of files added
     */
    protected function addDirectoryToZip(
        ZipArchive $zip,
        string $directory,
        string $zipPrefix,
        array &$checksums
    ): int {
        $count = 0;
        $baseDir = realpath($directory);

        if (!$baseDir || !is_dir($baseDir)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($baseDir) + 1);

            // Skip excluded files
            if ($this->shouldExclude($relativePath)) {
                continue;
            }

            $zipPath = $zipPrefix . '/' . $relativePath;
            $zip->addFile($filePath, $zipPath);

            // Add checksum
            $checksums[$zipPath] = md5_file($filePath);
            $count++;
        }

        return $count;
    }

    /**
     * Check if file should be excluded from bundle.
     */
    protected function shouldExclude(string $relativePath): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify checksums for extracted files.
     *
     * @throws \Exception
     */
    protected function verifyChecksums(ZipArchive $zip, array $checksums): void
    {
        foreach ($checksums as $zipPath => $expectedHash) {
            $content = $zip->getFromName($zipPath);
            if ($content === false) {
                throw new \Exception("Missing file in bundle: {$zipPath}");
            }

            $actualHash = md5($content);
            if ($actualHash !== $expectedHash) {
                throw new \Exception("Checksum mismatch for: {$zipPath}");
            }
        }
    }

    /**
     * Extract files from ZIP with a prefix to a target directory.
     */
    protected function extractFromZip(ZipArchive $zip, string $prefix, string $targetPath): void
    {
        // Check if any files exist with this prefix
        $hasFiles = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, $prefix)) {
                $hasFiles = true;
                break;
            }
        }

        if (!$hasFiles) {
            return;
        }

        // Create target directory
        if (!File::isDirectory($targetPath)) {
            File::makeDirectory($targetPath, 0755, true);
        }

        // Extract files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            // Get relative path (remove prefix)
            $relativePath = substr($name, strlen($prefix));

            if (empty($relativePath)) {
                continue;
            }

            // Check if it's a directory
            if (str_ends_with($name, '/')) {
                $dirPath = $targetPath . '/' . $relativePath;
                if (!File::isDirectory($dirPath)) {
                    File::makeDirectory($dirPath, 0755, true);
                }
                continue;
            }

            // Extract file
            $content = $zip->getFromIndex($i);
            $filePath = $targetPath . '/' . $relativePath;
            $fileDir = dirname($filePath);

            if (!File::isDirectory($fileDir)) {
                File::makeDirectory($fileDir, 0755, true);
            }

            File::put($filePath, $content);
        }
    }

    /**
     * Call plugin's uninstall lifecycle method.
     */
    protected function callPluginUninstall(string $pluginSlug, string $backendPath): void
    {
        // Try to find and instantiate the plugin class
        $className = Str::studly($pluginSlug) . 'Plugin';
        $namespace = "App\\Plugins\\{$className}";

        // Try different namespace patterns
        $namespaces = [
            $namespace,
            "App\\Plugins\\{$pluginSlug}\\{$className}",
            "App\\Plugins\\" . Str::studly($pluginSlug) . "\\{$className}",
        ];

        foreach ($namespaces as $ns) {
            if (class_exists($ns)) {
                try {
                    $plugin = app($ns);
                    if (method_exists($plugin, 'uninstall')) {
                        $plugin->uninstall();
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to call uninstall on plugin class", [
                        'class' => $ns,
                        'error' => $e->getMessage(),
                    ]);
                }
                break;
            }
        }
    }

    /**
     * Roll back plugin migrations.
     */
    protected function rollbackMigrations(string $backendPath): void
    {
        $migrationsPath = $backendPath . '/database/migrations';

        if (!File::isDirectory($migrationsPath)) {
            return;
        }

        $migrations = File::glob($migrationsPath . '/*.php');

        foreach ($migrations as $migrationFile) {
            $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);

            try {
                // Find and run migration down
                $migration = \Illuminate\Support\Facades\DB::table('migrations')
                    ->where('migration', $migrationName)
                    ->first();

                if ($migration) {
                    // Include the migration file
                    require_once $migrationFile;

                    // Get the class name
                    $className = $this->getMigrationClassName($migrationFile);

                    if ($className && class_exists($className)) {
                        $instance = new $className();
                        if (method_exists($instance, 'down')) {
                            $instance->down();
                        }
                    }

                    // Remove from migrations table
                    \Illuminate\Support\Facades\DB::table('migrations')
                        ->where('migration', $migrationName)
                        ->delete();
                }
            } catch (\Exception $e) {
                Log::warning("Failed to rollback migration", [
                    'migration' => $migrationName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get migration class name from file.
     */
    protected function getMigrationClassName(string $file): ?string
    {
        $content = file_get_contents($file);

        if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
