<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Hub CLI - Make Plugin Command
 *
 * Generates a new plugin with the full directory structure,
 * stubs for common files, and proper naming conventions.
 *
 * Usage:
 *   php artisan hub:make MyPlugin
 *   php artisan hub:make MyPlugin --api
 *   php artisan hub:make MyPlugin --web
 *   php artisan hub:make MyPlugin --frontend
 *   php artisan hub:make MyPlugin --migration
 */
class MakePluginCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'hub:make
                            {name : The name of the plugin (e.g., MyPlugin, mini-rpg)}
                            {--api : Generate API routes and controller}
                            {--web : Generate web routes}
                            {--frontend : Generate frontend Vue components}
                            {--migration : Generate database migration stub}
                            {--force : Overwrite existing files}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new plugin with full directory structure';

    /**
     * Plugin name (slugified).
     */
    protected string $slug = '';

    /**
     * Plugin display name (from argument).
     */
    protected string $pluginName = '';

    /**
     * Namespace for the plugin classes.
     */
    protected string $namespace = '';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->pluginName = $this->argument('name');

        // Convert to slug (e.g., MyPlugin -> my-plugin)
        $this->slug = Str::slug($this->pluginName, '-');

        // Convert to namespace (e.g., mini-rpg -> MiniRpg)
        $this->namespace = Str::studly($this->pluginName);

        // Validate plugin name
        if (!$this->validatePluginName()) {
            return 1;
        }

        // Determine plugins directory
        $pluginsPath = app_path('Plugins');

        // If name was provided as slug (mini-rpg), use it directly
        // Otherwise, create a directory with the slugified name
        $pluginPath = $pluginsPath . '/' . $this->slug;

        // Check if plugin already exists
        if (File::exists($pluginPath) && !$this->option('force')) {
            $this->error("Plugin '{$this->slug}' already exists! Use --force to overwrite.");
            return 1;
        }

        // Create directory structure
        $this->info("Creating plugin structure for '{$this->slug}'...");

        if (!$this->createPluginStructure($pluginPath)) {
            return 1;
        }

        // Generate files
        $this->generatePluginJson($pluginPath);
        $this->generatePluginClass($pluginPath);
        $this->generateHooksFile($pluginPath);

        // Generate optional files
        if ($this->option('api')) {
            $this->generateApiRoutes($pluginPath);
            $this->generateController($pluginPath);
        }

        if ($this->option('web')) {
            $this->generateWebRoutes($pluginPath);
        }

        if ($this->option('frontend')) {
            $this->generateFrontendComponents($pluginPath);
        }

        if ($this->option('migration')) {
            $this->generateMigrationStub($pluginPath);
        }

        $this->info("✓ Plugin '{$this->slug}' created successfully!");
        $this->line("");
        $this->info("Next steps:");
        $this->line("  1. Enable the plugin in your config/plugins.php");
        $this->line("  2. Run 'php artisan app:register-plugins' to register it");
        $this->line("  3. Run 'php artisan migrate' if you added migrations");

        return 0;
    }

    /**
     * Validate the plugin name.
     */
    protected function validatePluginName(): bool
    {
        // Check for empty name
        if (empty($this->pluginName)) {
            $this->error('Plugin name cannot be empty.');
            return false;
        }

        // Check for valid characters (alphanumeric, dashes, underscores)
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $this->pluginName)) {
            $this->error('Plugin name must start with a letter and contain only letters, numbers, dashes, and underscores.');
            return false;
        }

        // Check minimum length
        if (strlen($this->slug) < 2) {
            $this->error('Plugin name must be at least 2 characters.');
            return false;
        }

        return true;
    }

    /**
     * Create the plugin directory structure.
     */
    protected function createPluginStructure(string $pluginPath): bool
    {
        try {
            // Create main plugin directory
            if (!File::makeDirectory($pluginPath, 0755, true)) {
                $this->error("Failed to create plugin directory: {$pluginPath}");
                return false;
            }

            // Create Controllers directory
            if ($this->option('api') || $this->option('web')) {
                File::makeDirectory($pluginPath . '/Controllers/Api', 0755, true);
                if ($this->option('web')) {
                    File::makeDirectory($pluginPath . '/Controllers/Web', 0755, true);
                }
            }

            // Create database/migrations directory
            if ($this->option('migration')) {
                File::makeDirectory($pluginPath . '/database/migrations', 0755, true);
            }

            // Create routes directories
            if ($this->option('api')) {
                File::makeDirectory($pluginPath . '/routes', 0755, true);
            }

            // Create Services directory (optional, but common)
            File::makeDirectory($pluginPath . '/Services', 0755, true);

            // Create Models directory
            File::makeDirectory($pluginPath . '/Models', 0755, true);

            // Create resources/views directory
            File::makeDirectory($pluginPath . '/resources/views', 0755, true);

            // Create lang directory
            File::makeDirectory($pluginPath . '/lang', 0755, true);

            $this->info("  ✓ Created directory structure");

            return true;
        } catch (\Exception $e) {
            $this->error("Error creating directory structure: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate the plugin.json manifest file.
     */
    protected function generatePluginJson(string $pluginPath): void
    {
        $json = [
            'name' => $this->namespace,
            'slug' => $this->slug,
            'version' => '1.0.0',
            'description' => 'A new plugin created with hub:make',
            'author' => 'Developer',
            'enabled' => false,
            'license_required' => false,
            'requires' => [
                'laravel' => '^11.0',
                'plugins' => new \stdClass(),
            ],
            'settings' => [
                'icon' => '📦',
                'color' => '#6366f1',
                'menu' => [
                    'enabled' => true,
                    'order' => 99,
                    'section' => 'actions',
                ],
            ],
            'permissions' => [
                "{$this->slug}.view" => "View {$this->namespace} content",
                "{$this->slug}.use" => "Use {$this->namespace} features",
                "{$this->slug}.admin" => "Administer {$this->namespace}",
            ],
            'hooks' => new \stdClass(),
            'routes' => [
                'web' => $this->option('web'),
                'api' => $this->option('api'),
                'admin' => false,
            ],
        ];

        if ($this->option('frontend')) {
            $json['frontend'] = [
                'slots' => new \stdClass(),
                'routes' => [],
            ];
        }

        $jsonContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        File::put($pluginPath . '/plugin.json', $jsonContent);

        $this->info("  ✓ Created plugin.json");
    }

    /**
     * Generate the main Plugin class.
     */
    protected function generatePluginClass(string $pluginPath): void
    {
        $className = $this->namespace . 'Plugin';

        $content = <<<PHP
<?php

namespace App\Plugins\\{$this->namespace};

use App\Plugins\Plugin;
use App\Core\Contracts\PluginInterface;
use Illuminate\Support\Facades\Route;

/**
 * {$this->namespace} Plugin
 *
 * A new plugin created with hub:make
 */
class {$className} extends Plugin implements PluginInterface
{
    /**
     * Plugin constructor.
     */
    public function __construct()
    {
        parent::__construct(app_path('Plugins/{$this->slug}'));
    }

    // ==========================================
    // PluginInterface Implementation
    // ==========================================

    /**
     * Register the plugin's services.
     * Called during Laravel's "register" phase.
     */
    public function register(): void
    {
        // Register any services or bindings
        // \$this->app->singleton('{$this->slug}.service', function (\$app) {
        //     return new Services\\{$this->namespace}Service();
        // });
    }

    /**
     * Boot the plugin's functionality.
     * Called during Laravel's "boot" phase.
     */
    public function boot(): void
    {
        // Register hooks from hooks.php
        \$this->registerHooks();
    }

    // ==========================================
    // PluginLifecycleInterface Implementation
    // ==========================================

    /**
     * Called when plugin is first installed.
     */
    public function install(): void
    {
        \$this->log('info', '{$this->namespace} plugin installed');
    }

    /**
     * Called when plugin is enabled.
     */
    public function enable(): void
    {
        \$this->log('info', '{$this->namespace} plugin enabled');
    }

    /**
     * Called when plugin is disabled.
     */
    public function disable(): void
    {
        \$this->log('info', '{$this->namespace} plugin disabled');
    }

    /**
     * Called when plugin is uninstalled.
     */
    public function uninstall(): void
    {
        \$this->log('info', '{$this->namespace} plugin uninstalled');
    }

    /**
     * Called when upgrading versions.
     */
    public function upgrade(string \$fromVersion, string \$toVersion): void
    {
        \$this->log('info', "{$this->namespace} upgraded from {\$fromVersion} to {\$toVersion}");
    }
}
PHP;

        File::put($pluginPath . '/' . $className . '.php', $content);

        $this->info("  ✓ Created {$className}.php");
    }

    /**
     * Generate the hooks.php file.
     */
    protected function generateHooksFile(string $pluginPath): void
    {
        $content = <<<PHP
<?php

/**
 * {$this->namespace} Plugin Hooks
 *
 * Register hooks for plugin integration with the core system.
 *
 * Available hook types:
 * - Action hooks (side-effects): Run code when events occur
 * - Filter hooks (transform): Modify data and return modified values
 *
 * Hooks are fired from the Hook facade:
 *   Hook::fire('hook.name', ['key' => 'value']);
 */

use App\Facades\Hook;

// Example: Initialize plugin data when a new user is created
// Hook::register('user.created', function (\$user) {
//     \$user->setManyPluginMeta('{$this->slug}', [
//         'some_data' => 'default_value',
//     ]);
// }, 10);

// Example: Add navigation menu items
// Hook::register('customMenus', function (\$user) {
//     if (!\$user) return [];
//
//     return [
//         '{$this->slug}' => [
//             'title' => '{$this->namespace}',
//             'items' => [
//                 [
//                     'url' => '/{$this->slug}',
//                     'text' => 'Dashboard',
//                     'icon' => '📦',
//                     'sort' => 50,
//                 ],
//             ],
//         ],
//     ];
// }, 10);

// Example: Add widget to user profile
// Hook::register('user.profile.widgets', function (\$widgets) {
//     \$widgets['{$this->slug}'] = [
//         'title' => '{$this->namespace}',
//         'component' => '{$this->namespace}Widget.vue',
//         'order' => 20,
//     ];
//     return \$widgets;
// }, 10);

// Example: Modify data (filter hook)
// Hook::register('some.filter', function (\$data) {
//     \$data['modified'] = true;
//     return \$data;
// }, 10);
PHP;

        File::put($pluginPath . '/hooks.php', $content);

        $this->info("  ✓ Created hooks.php");
    }

    /**
     * Generate API routes file.
     */
    protected function generateApiRoutes(string $pluginPath): void
    {
        $controllerName = $this->namespace . 'Controller';

        $content = <<<PHP
<?php

/**
 * {$this->namespace} API Routes
 *
 * Plugin API endpoint definitions.
 * These routes are automatically prefixed with /api/{plugin-slug}
 */

use Illuminate\Support\Facades\Route;
use App\Plugins\\{$this->namespace}\Controllers\\Api\\{$controllerName};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Get plugin status/info
Route::get('/', [{$controllerName}::class, 'index'])->name('{$this->slug}.index');

// Example resource endpoints
// Route::get('/data', [{$controllerName}::class, 'getData'])->name('{$this->slug}.data');
// Route::post('/action', [{$controllerName}::class, 'doAction'])->name('{$this->slug}.action');
PHP;

        File::put($pluginPath . '/routes/api.php', $content);

        $this->info("  ✓ Created routes/api.php");
    }

    /**
     * Generate the API controller.
     */
    protected function generateController(string $pluginPath): void
    {
        $controllerName = $this->namespace . 'Controller';

        $content = <<<PHP
<?php

namespace App\Plugins\\{$this->namespace}\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * {$this->namespace} API Controller
 *
 * Handles API requests for the {$this->namespace} plugin.
 */
class {$controllerName} extends Controller
{
    /**
     * Get plugin status information.
     */
    public function index(Request \$request): JsonResponse
    {
        \$user = \$request->user();

        return response()->json([
            'success' => true,
            'plugin' => '{$this->slug}',
            'version' => '1.0.0',
            'data' => [
                // Add your plugin data here
            ],
        ]);
    }

    /**
     * Example endpoint: Get user data for this plugin.
     */
    public function getUserData(Request \$request): JsonResponse
    {
        \$user = \$request->user();

        // Example: Get plugin-specific data from user metadata
        // \$data = \$user->getAllPluginMeta('{$this->slug}');

        return response()->json([
            'success' => true,
            'data' => [
                // 'plugin_data' => \$data,
            ],
        ]);
    }

    /**
     * Example endpoint: Perform an action.
     */
    public function doAction(Request \$request): JsonResponse
    {
        \$request->validate([
            'action' => 'required|string',
        ]);

        \$action = \$request->input('action');

        // Handle different actions
        // switch (\$action) {
        //     case 'some_action':
        //         // Do something
        //         break;
        // }

        return response()->json([
            'success' => true,
            'message' => "Action '{\$action}' completed",
        ]);
    }
}
PHP;

        // Ensure directory exists
        File::makeDirectory($pluginPath . '/Controllers/Api', 0755, true);
        File::put($pluginPath . '/Controllers/Api/' . $controllerName . '.php', $content);

        $this->info("  ✓ Created Controllers/Api/{$controllerName}.php");
    }

    /**
     * Generate web routes file.
     */
    protected function generateWebRoutes(string $pluginPath): void
    {
        $controllerName = $this->namespace . 'Controller';

        $content = <<<PHP
<?php

/**
 * {$this->namespace} Web Routes
 *
 * Plugin web route definitions.
 * These routes are automatically available at /{plugin-slug}
 */

use Illuminate\Support\Facades\Route;
use App\Plugins\\{$this->namespace}\Controllers\Web\\{$controllerName};

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Main plugin page
Route::get('/', [{$controllerName}::class, 'index'])->name('{$this->slug}.index');
PHP;

        File::makeDirectory($pluginPath . '/routes', 0755, true);
        File::put($pluginPath . '/routes/web.php', $content);

        $this->info("  ✓ Created routes/web.php");
    }

    /**
     * Generate frontend Vue components.
     */
    protected function generateFrontendComponents(string $pluginPath): void
    {
        // Create frontend directory
        $frontendPath = $pluginPath . '/frontend';
        File::makeDirectory($frontendPath . '/components', 0755, true);

        // Main plugin component
        $mainComponent = <<<VUE
<template>
  <div class="{$this->slug}-plugin">
    <div class="plugin-header">
      <h2>{{ pluginName }}</h2>
    </div>
    <div class="plugin-content">
      <slot></slot>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'

const pluginName = ref('{$this->namespace}')
const pluginSlug = '{$this->slug}'

// Add your component logic here
</script>

<style scoped>
.{$this->slug}-plugin {
  padding: 1rem;
}

.plugin-header h2 {
  margin: 0 0 1rem 0;
  color: var(--text-primary);
}
</style>
VUE;

        File::put($frontendPath . '/{$this->namespace}Plugin.vue', $mainComponent);

        $this->info("  ✓ Created frontend/{$this->namespace}Plugin.vue");

        // Widget component example
        $widgetComponent = <<<VUE
<template>
  <div class="{$this->slug}-widget">
    <h4>{$this->namespace}</h4>
    <div class="widget-content">
      <!-- Add widget content here -->
    </div>
  </div>
</template>

<script setup lang="ts">
// Widget component logic
</script>

<style scoped>
.{$this->slug}-widget {
  padding: 0.5rem;
}
</style>
VUE;

        File::put($frontendPath . '/components/{$this->namespace}Widget.vue', $widgetComponent);

        $this->info("  ✓ Created frontend/components/{$this->namespace}Widget.vue");
    }

    /**
     * Generate database migration stub.
     */
    protected function generateMigrationStub(string $pluginPath): void
    {
        $timestamp = date('Y_m_d_His');
        $filename = $timestamp . '_create_' . $this->slug . '_tables.php';

        $content = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * {$this->namespace} Plugin Migrations
 *
 * Create tables for the {$this->namespace} plugin.
 * Run: php artisan migrate --path=app/Plugins/{$this->slug}/database/migrations
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Example: Create a custom table
        // Schema::create('{$this->slug}_items', function (Blueprint \$table) {
        //     \$table->id();
        //     \$table->unsignedBigInteger('user_id');
        //     \$table->string('name');
        //     \$table->integer('quantity')->default(1);
        //     \$table->timestamps();
        //
        //     \$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('{$this->slug}_items');
    }
};
PHP;

        File::put($pluginPath . '/database/migrations/' . $filename, $content);

        $this->info("  ✓ Created database/migrations/{$filename}");
    }
}
