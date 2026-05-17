# Hub CLI - Plugin Development Tool

The Hub CLI is a command-line tool for developers to quickly scaffold and manage plugins in LaravelCP. It provides commands to generate plugin structures, validate manifests, and perform common plugin development tasks.

---

## Table of Contents

1. [Overview](#overview)
2. [Commands](#commands)
3. [Usage Examples](#usage-examples)
4. [Generated Structure](#generated-structure)
5. [Extending the CLI](#extending-the-cli)
6. [Configuration](#configuration)

---

## Overview

The Hub CLI is implemented as Laravel Artisan commands in the `App\Console\Commands` namespace. It simplifies plugin development by:

- **Scaffolding**: Generate complete plugin directory structures
- **Templates**: Create files with proper naming conventions and boilerplate code
- **Validation**: Validate plugin manifests against the contract
- **Registration**: Help register plugins with the system

---

## Commands

### `hub:make`

Creates a new plugin with a complete directory structure and starter files.

```bash
php artisan hub:make <name> [options]
```

#### Arguments

| Argument | Description | Example |
|----------|-------------|---------|
| `name` | Plugin name (PascalCase or kebab-case) | `MyPlugin` or `my-plugin` |

#### Options

| Option | Description |
|--------|-------------|
| `--api` | Generate API routes and controller |
| `--web` | Generate web routes |
| `--frontend` | Generate Vue frontend components |
| `--migration` | Generate database migration stub |
| `--force` | Overwrite existing plugin files |

#### Examples

```bash
# Create a basic plugin
php artisan hub:make MyPlugin

# Create with API support
php artisan hub:make MyPlugin --api

# Create with all features
php artisan hub:make MyPlugin --api --web --frontend --migration

# Create using kebab-case
php artisan hub:make my-awesome-plugin

# Overwrite existing plugin
php artisan hub:make MyPlugin --force
```

---

### `hub:validate`

Validates a plugin's `plugin.json` manifest against the contract schema.

```bash
php artisan hub:validate <slug>
```

#### Arguments

| Argument | Description | Example |
|----------|-------------|---------|
| `slug` | Plugin slug (directory name) | `mini-rpg` |

#### Examples

```bash
# Validate a specific plugin
php artisan hub:validate mini-rpg

# Validate multiple plugins
php artisan hub:validate crimes
php artisan hub:validate bank
```

---

### `hub:list`

Lists all installed plugins with their status.

```bash
php artisan hub:list [options]
```

#### Options

| Option | Description |
|--------|-------------|
| `--enabled` | Show only enabled plugins |
| `--disabled` | Show only disabled plugins |

#### Examples

```bash
# List all plugins
php artisan hub:list

# Show only enabled
php artisan hub:list --enabled

# Show only disabled
php artisan hub:list --disabled
```

---

### `hub:enable`

Enables a plugin.

```bash
php artisan hub:enable <slug>
```

---

### `hub:disable`

Disables a plugin.

```bash
php artisan hub:disable <slug>
```

---

## Usage Examples

### Creating a New Plugin from Scratch

```bash
# 1. Generate the plugin structure with API and migrations
php artisan hub:make InventorySystem --api --migration

# 2. Edit the generated plugin.json to configure settings
#    - Set appropriate permissions
#    - Configure menu items
#    - Add hook declarations

# 3. Implement your plugin logic in the generated controller

# 4. Register the plugin
php artisan app:register-plugins

# 5. Enable the plugin
php artisan hub:enable inventory-system

# 6. Run migrations if needed
php artisan migrate
```

### Creating a Plugin with Full Stack

```bash
# Create a complete plugin with backend and frontend
php artisan hub:make BattleArena --api --web --frontend --migration
```

This generates:
- Backend: Plugin class, API controller, routes, migrations
- Frontend: Vue components for the plugin UI

---

## Generated Structure

When you run `php artisan hub:make MyPlugin --api --migration`, the following structure is created:

```
app/Plugins/my-plugin/
├── plugin.json              # Plugin manifest
├── MyPluginPlugin.php       # Main plugin class
├── hooks.php               # Hook registrations
├── routes/
│   └── api.php             # API routes
├── Controllers/
│   └── Api/
│       └── MyPluginController.php  # API controller
├── database/
│   └── migrations/
│       └── 2026_02_23_xxxxxx_create_my_plugin_tables.php  # Migration stub
├── Services/                # (empty, for custom services)
├── Models/                  # (empty, for Eloquent models)
├── resources/
│   └── views/              # (empty, for Blade views)
└── lang/                   # (empty, for translations)
```

### Generated File Details

#### plugin.json

The manifest includes:
- Basic info (name, slug, version, description)
- Default permissions (`{slug}.view`, `{slug}.use`, `{slug}.admin`)
- Route configuration
- Empty hooks object (fill in as needed)

#### MyPluginPlugin.php

A complete plugin class extending the base `Plugin` class with:
- Constructor calling parent
- `register()` method for service bindings
- `boot()` method for hook registration
- Lifecycle methods: `install()`, `enable()`, `disable()`, `uninstall()`, `upgrade()`

#### hooks.php

A well-commented file with examples for:
- User creation hooks
- Custom menu registration
- Widget registration
- Filter hooks

#### Controller

A basic API controller with:
- `index()` - Get plugin status
- `getUserData()` - Example user data endpoint
- `doAction()` - Example action endpoint

---

## Extending the CLI

The Hub CLI is designed to be extensible. You can add new commands or modify existing ones.

### Adding a New Command

1. Create a new command class in `app/Console/Commands/`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HubCustomCommand extends Command
{
    protected $signature = 'hub:custom {slug : The plugin slug}';
    
    protected $description = 'A custom hub command';
    
    public function handle()
    {
        $slug = $this->argument('slug');
        // Your logic here
    }
}
```

2. Register the command in `app/Console/Kernel.php` (if not auto-discovered):

```php
protected $commands = [
    Commands\HubCustomCommand::class,
];
```

### Modifying Generated Templates

The templates are embedded in the `MakePluginCommand` class. To customize them:

1. Open `app/Console/Commands\MakePluginCommand.php`
2. Find the method for the file you want to modify:
   - `generatePluginJson()` - plugin.json
   - `generatePluginClass()` - Main plugin PHP class
   - `generateHooksFile()` - hooks.php
   - `generateApiRoutes()` - API routes
   - `generateController()` - API controller
   - `generateWebRoutes()` - Web routes
   - `generateFrontendComponents()` - Vue components
   - `generateMigrationStub()` - Migration file
3. Modify the template strings as needed

### Adding New Generator Options

To add a new option (e.g., `--tests`):

1. Add the option to the signature:

```php
protected $signature = 'hub:make
                        {name : The name of the plugin}
                        {--api : Generate API routes}
                        {--tests : Generate test files}';
```

2. Add the generation method:

```php
protected function generateTestFiles(string $pluginPath): void
{
    if (!$this->option('tests')) {
        return;
    }
    
    // Generate test files...
}
```

3. Call it from `handle()`:

```php
if ($this->option('tests')) {
    $this->generateTestFiles($pluginPath);
}
```

---

## Configuration

The Hub CLI uses sensible defaults, but you can configure behavior through environment variables or config files.

### Default Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Plugin Author | `Developer` | Default author in plugin.json |
| Plugin Version | `1.0.0` | Initial version for new plugins |
| Default Icon | `📦` | Emoji icon for menu |
| Default Color | `#6366f1` | Brand color for UI |

### Customizing Defaults

You can modify the default values in `MakePluginCommand.php`:

```php
// In generatePluginJson() method
$json = [
    'author' => 'Your Name',  // Change default
    'version' => '0.1.0',    // Change default
    // ...
];
```

---

## Best Practices

### 1. Use Descriptive Plugin Names

```bash
# ✅ Good
php artisan hub:make PlayerInventory
php artisan hub:make battle-arena

# ❌ Bad
php artisan hub:make plugin1
php artisan hub:make test
```

### 2. Enable Features You Need

Only generate what you need to keep the project clean:

```bash
# If you only need an API
php artisan hub:make QuestLog --api

# If you need full web + API
php artisan hub:make QuestLog --api --web
```

### 3. Review Generated plugin.json

Always review and customize:
- Permissions
- Menu configuration
- Hook declarations
- Dependencies

### 4. Register After Creation

Always run the registration command after creating a new plugin:

```bash
php artisan app:register-plugins
```

---

## Troubleshooting

### "Plugin already exists" Error

Use the `--force` flag to overwrite:

```bash
php artisan hub:make MyPlugin --force
```

### "Invalid plugin name" Error

Plugin names must:
- Start with a letter
- Contain only letters, numbers, dashes, and underscores
- Be at least 2 characters

### Command Not Found

Make sure the command is registered. Try clearing the cache:

```bash
php artisan config:clear
php artisan cache:clear
```

---

## Related Documentation

- [Plugin Hook System](PLUGIN_HOOKS.md)
- [Plugin Registry](PLUGIN_REGISTRY.md)
- [Plugin Contract](PLUGIN_CONTRACT.md)
- [Development Guide](../../DEVELOPMENT.md)
