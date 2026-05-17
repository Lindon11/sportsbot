# Plugin Bundle System

The LaravelCP Plugin Bundle System enables true modularity by packaging plugins as distributable ZIP files containing both backend PHP code and frontend Vue components.

## Overview

Plugins can be:
- **Exported** to distributable ZIP bundles
- **Imported** from bundle files
- **Removed** completely from both backend and frontend

## CLI Commands

### List Plugins

List all installed plugins with their status:

```bash
php artisan hub:list
```

Options:
- `--enabled` - Show only enabled plugins
- `--disabled` - Show only disabled plugins
- `--json` - Output as JSON

Example output:
```
┌─────────────┬─────────────┬─────────┬──────────────┬─────────┬──────────┬──────────────┐
│ Name        │ Slug        │ Version │ Status       │ Backend │ Frontend │ Dependencies │
├─────────────┼─────────────┼─────────┼──────────────┼─────────┼──────────┼──────────────┤
│ Bank        │ bank        │ 1.0.0   │ ✓ Enabled    │ ✓       │ ✓        │ -            │
│ Casino      │ casino      │ 1.0.0   │ ✗ Disabled   │ ✓       │ ✗        │ -            │
│ Core RPG    │ mini-rpg    │ 1.0.0   │ ✓ Enabled    │ ✓       │ ✓        │ -            │
└─────────────┴─────────────┴─────────┴──────────────┴─────────┴──────────┴──────────────┘
```

### Export Single Plugin

Export a plugin to a ZIP bundle:

```bash
php artisan hub:export Bank
php artisan hub:export mini-rpg --output=/path/to/bundles
```

Arguments:
- `plugin` - Plugin slug or name

Options:
- `--output=PATH` - Output directory (defaults to Downloads folder)

The bundle will be created as `{slug}-bundle.zip`.

### Export All Plugins

Export all installed plugins:

```bash
php artisan hub:export-all
php artisan hub:export-all --output=/path/to/bundles
```

### Import Plugin

Import a plugin from a bundle:

```bash
php artisan hub:import /path/to/bank-bundle.zip
php artisan hub:import /path/to/bank-bundle.zip --enable
```

Arguments:
- `bundle` - Path to the bundle ZIP file

Options:
- `--enable` - Enable the plugin after import

### Remove Plugin

Remove a plugin completely:

```bash
php artisan hub:remove Bank
php artisan hub:remove Bank --force
php artisan hub:remove Bank --keep-migrations
```

Arguments:
- `plugin` - Plugin slug or name

Options:
- `--force` - Force removal even if plugin is enabled
- `--keep-migrations` - Keep database migrations (prevents data loss)

## Bundle Format

A plugin bundle is a ZIP file with the following structure:

```
{slug}-bundle.zip
├── bundle.json           # Bundle metadata
├── backend/              # Backend PHP files
│   ├── plugin.json       # Plugin manifest
│   ├── {PluginName}Plugin.php
│   ├── hooks.php
│   ├── routes/
│   │   ├── api.php
│   │   ├── web.php
│   │   └── admin.php
│   ├── Controllers/
│   ├── Services/
│   ├── Models/
│   └── database/
│       └── migrations/
└── frontend/             # Frontend Vue files
    ├── index.ts          # Plugin entry point
    ├── routes.ts         # Route definitions
    ├── views/
    └── components/
```

### bundle.json

The bundle manifest contains metadata about the bundle:

```json
{
  "version": "1.0.0",
  "format": "laravelcp-plugin-bundle",
  "created_at": "2024-01-15T10:30:00Z",
  "plugin_slug": "bank",
  "plugin_name": "Bank",
  "plugin_version": "1.0.0",
  "checksums": {
    "backend/plugin.json": "abc123...",
    "backend/BankPlugin.php": "def456..."
  }
}
```

## Plugin Structure

### Backend Structure

```
backend/app/Plugins/Bank/
├── plugin.json           # Required: Plugin manifest
├── BankPlugin.php        # Required: Main plugin class
├── hooks.php             # Optional: Hook listeners
├── routes/
│   ├── api.php           # API routes (prefixed with /api/v1/p/bank)
│   ├── web.php           # Web routes
│   └── admin.php         # Admin routes
├── Controllers/
│   └── Api/
│       └── BankController.php
├── Services/
├── Models/
└── database/
    └── migrations/
```

### Frontend Structure

```
frontend/src/plugins/bank/
├── index.ts              # Plugin entry point
├── routes.ts             # Route definitions
├── views/
│   ├── BankView.vue
│   ├── TransferView.vue
│   └── HistoryView.vue
└── components/
    ├── BankWidget.vue
    └── QuickBalance.vue
```

### index.ts (Frontend Entry Point)

```typescript
export const name = 'Bank'
export const slug = 'bank'
export const version = '1.0.0'
export const description = 'Banking system'

export const config = {
  icon: '🏦',
  color: 'green',
  menu: {
    enabled: true,
    order: 10,
    section: 'actions',
  },
}

export const slots = {
  'dashboard-widget': ['components/BankWidget.vue'],
}

export async function initialize() {
  console.log('[bank] Plugin initialized')
}

export default {
  name,
  slug,
  version,
  description,
  config,
  slots,
  initialize,
}
```

### routes.ts (Frontend Routes)

```typescript
import type { PluginRouteDefinition } from '@/types/plugin-route'

const routes: PluginRouteDefinition[] = [
  {
    path: '/bank',
    name: 'bank',
    component: 'BankView.vue',
    meta: {
      title: 'Bank',
      requiresAuth: true,
    },
  },
]

export default routes
```

## Workflow Examples

### Developing a New Plugin

1. Create the plugin using the scaffold command:
   ```bash
   php artisan hub:make MyPlugin --api --frontend --migration
   ```

2. Develop the backend in `backend/app/Plugins/MyPlugin/`

3. Develop the frontend in `frontend/src/plugins/my-plugin/`

4. Test the plugin locally

5. Export for distribution:
   ```bash
   php artisan hub:export MyPlugin
   ```

### Sharing a Plugin

1. Export the plugin:
   ```bash
   php artisan hub:export Bank --output=./dist
   ```

2. Share the `bank-bundle.zip` file

3. Recipients can import:
   ```bash
   php artisan hub:import bank-bundle.zip --enable
   ```

### Removing Unused Plugins

1. List plugins to see what's installed:
   ```bash
   php artisan hub:list
   ```

2. Remove unwanted plugins:
   ```bash
   php artisan hub:remove Stocks --force
   ```

### Migrating Legacy Plugins

If you have plugins in the old `frontend/src/views/plugins/` location:

1. Create a new plugin structure in `frontend/src/plugins/{slug}/`

2. Move Vue components to the `views/` subdirectory

3. Create `index.ts` and `routes.ts` files

4. Update the plugin manifest with frontend settings

5. Test and export as a bundle

## Programmatic Usage

### Using PluginBundleService

```php
use App\Core\Services\PluginBundleService;

$bundleService = app(PluginBundleService::class);

// Export
$bundlePath = $bundleService->export('bank', '/output/path');

// Import
$result = $bundleService->import('/path/to/bundle.zip', enable: true);

// Remove
$result = $bundleService->remove('bank', force: false, keepMigrations: false);

// List
$plugins = $bundleService->list();

// Export all
$results = $bundleService->exportAll('/output/path');
```

### Checking Plugin Status

```php
use App\Core\Services\PluginBundleService;

$bundleService = app(PluginBundleService::class);
$plugins = $bundleService->list();

foreach ($plugins as $plugin) {
    if ($plugin['enabled'] && $plugin['has_frontend']) {
        // Plugin is active with frontend
    }
}
```

## Best Practices

1. **Always disable plugins before removal** unless using `--force`

2. **Use `--keep-migrations`** when removing a plugin temporarily

3. **Test bundles** by importing them into a fresh installation

4. **Version your bundles** - include version info in the manifest

5. **Document dependencies** in `plugin.json` under `requires.plugins`

6. **Keep frontend components modular** - avoid hardcoded routes

## Troubleshooting

### Bundle Import Fails

- Check that the bundle is a valid ZIP file
- Ensure `bundle.json` exists and is valid JSON
- Verify checksums match

### Plugin Not Loading

- Check the plugin is enabled in `plugin.json`
- Verify dependencies are installed
- Check for PHP errors in logs

### Frontend Components Not Found

- Ensure frontend files exist in `frontend/src/plugins/{slug}/`
- Check `routes.ts` references correct component paths
- Verify dynamic imports are working

## Related Documentation

- [Plugin Development Guide](../app/Plugins/README.md)
- [Plugin Hooks System](./PLUGIN_HOOKS.md)
- [Plugin Registry](./PLUGIN_REGISTRY.md)
- [HUB CLI Reference](./HUB_CLI.md)
