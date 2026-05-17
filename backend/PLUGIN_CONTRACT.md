# Plugin Contract

This document defines the formal contract that all LaravelCP plugins must follow. Breaking these specifications will break plugin compatibility and may cause runtime errors.

---

## Table of Contents

1. [Required Folder Structure](#1-required-folder-structure)
2. [plugin.json Schema](#2-pluginjson-schema)
3. [Plugin Class Requirements](#3-plugin-class-requirements)
4. [Permission Model](#4-permission-model)
5. [Hook Registration](#5-hook-registration)
6. [Metadata System](#6-metadata-system)
7. [Frontend Integration](#7-frontend-integration)
8. [API Routes](#8-api-routes)
9. [Lifecycle Requirements](#9-lifecycle-requirements)

---

## 1️⃣ Required Folder Structure

Every plugin **must** follow this structure:

```
plugin/
├── plugin.json                  # Required: Plugin manifest
├── {PluginName}Plugin.php      # Required: Main plugin class
├── hooks.php                   # Optional: Hook listeners
├── routes/
│   ├── web.php                # Optional: Web routes
│   ├── api.php                # Optional: API routes
│   └── admin.php              # Optional: Admin routes
├── Controllers/
│   ├── Web/
│   └── Api/
├── Services/
├── Models/
├── views/                      # Optional: Blade templates
├── lang/                       # Optional: Translation files
├── database/
│   └── migrations/            # Optional: Plugin migrations
└── assets/                     # Optional: CSS, JS, images
```

- Anything outside this structure is ignored or rejected
- All plugin logic, assets, and configuration must reside within these folders/files
- The plugin class filename must match the pattern `{PluginName}Plugin.php` (e.g., `MiniRpgPlugin.php`)

---

## 2️⃣ plugin.json Schema

**This schema is locked and must not change after publication.**

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Human-readable plugin name |
| `slug` | string | Unique identifier (lowercase, kebab-case, e.g., `"organized-crime"`) |
| `version` | string | Plugin version (semver, e.g., `"3.0.0"`) |
| `author` | string | Plugin author name |
| `enabled` | boolean | Whether the plugin loads on boot |
| `requires.laravel` | string | Minimum compatible Laravel version constraint |
| `requires.plugins` | object | Other plugin slugs this plugin depends on, keyed by slug with semver version constraint. Use `{}` for no dependencies. |
| `routes.web` | boolean | Plugin registers `routes/web.php` |
| `routes.api` | boolean | Plugin registers `routes/api.php` |
| `routes.admin` | boolean | Plugin registers `routes/admin.php` |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `description` | string | Short description of the plugin |
| `license_required` | boolean | Whether the plugin requires a valid license (default: false) |
| `permissions` | object | LaravelCP permissions this plugin defines (key: permission slug, value: description) |
| `settings` | object | UI metadata |
| `settings.icon` | string | Emoji icon for the plugin |
| `settings.color` | string | UI color theme (e.g., "blue", "purple", "indigo") |
| `settings.route` | string | Main route name for the plugin |
| `settings.menu` | object | Menu configuration |
| `settings.menu.enabled` | boolean | Show in menu |
| `settings.menu.order` | integer | Menu order (lower = higher in list) |
| `settings.menu.section` | string | Menu section (e.g., "main", "actions") |
| `settings.menu.parent` | string\|null | Parent menu item |
| `hooks` | object | Hook names the plugin fires or responds to (key: hook name, value: true) |
| `frontend` | object | Frontend configuration |
| `frontend.slots` | object | Component slots provided by this plugin |
| `frontend.routes` | object | Frontend routes provided by this plugin |

### Module Dependencies

The `requires.plugins` value **must** be an object mapping slugs to semver constraints — never a plain array. This ensures the `SemverResolver` can enforce compatibility at install time.

Supported constraint operators: `^`, `~`, `>=`, `>`, `<=`, `<`, `*` (any version).

```json
"requires": {
    "laravel": "^11.0",
    "plugins": {
        "gang": "^3.0.0",
        "inventory": "^3.0.0"
    }
}
```

Plugins with no module dependencies must use an empty object:

```json
"requires": {
    "laravel": "^11.0",
    "plugins": {}
}
```

### Complete Example

```json
{
    "name": "Organized Crime",
    "slug": "organized-crime",
    "version": "3.0.0",
    "description": "Coordinate gang crimes for big rewards and reputation",
    "author": "OpenPBBG",
    "enabled": true,
    "license_required": false,
    "requires": {
        "laravel": "^11.0",
        "plugins": {
            "gang": "^3.0.0",
            "crimes": "^2.0.0"
        }
    },
    "permissions": {
        "organized-crime.view": "View organized crimes",
        "organized-crime.operate": "Operate organized crimes",
        "organized-crime.admin": "Administer organized crime settings"
    },
    "settings": {
        "icon": "💼",
        "color": "indigo",
        "route": "organized-crimes.index",
        "menu": {
            "enabled": true,
            "order": 45,
            "section": "actions",
            "parent": null
        }
    },
    "hooks": {
        "OnOrganizedCrimeAttempt": true,
        "OnOrganizedCrimeComplete": true,
        "alterModuleData": true,
        "moduleLoad": true,
        "customMenus": true
    },
    "routes": {
        "web": true,
        "api": true,
        "admin": false
    },
    "frontend": {
        "slots": {
            "dashboard-widget": ["OrganizedCrimeWidget.vue"],
            "combat-panel": ["OrganizedCrimeActions.vue"]
        },
        "routes": [
            {
                "path": "/organized-crimes",
                "name": "organized-crimes-index",
                "component": "Index.vue",
                "meta": { "title": "Organized Crimes" }
            }
        ]
    }
}
```

---

## 3️⃣ Plugin Class Requirements

Your plugin class **must** extend the base `Plugin` class and implement `PluginInterface`.

### Minimal Implementation

```php
<?php

namespace App\Plugins\YourPlugin;

use App\Plugins\Plugin;
use App\Core\Contracts\PluginInterface;

class YourPluginPlugin extends Plugin implements PluginInterface
{
    public function __construct()
    {
        parent::__construct(app_path('Plugins/YourPlugin'));
    }

    public function register(): void
    {
        // Register services
    }

    public function boot(): void
    {
        // Register hooks
        $this->registerHooks();
    }
}
```

### Required Methods from PluginInterface

| Method | Description |
|--------|-------------|
| `getId()` | Get the plugin's unique identifier (slug) |
| `getName()` | Get the plugin's display name |
| `getVersion()` | Get the plugin's version string |
| `getManifest()` | Get the parsed plugin.json manifest |
| `getPath()` | Get the plugin's filesystem path |
| `getNamespace()` | Get the plugin's PHP namespace |
| `register()` | Register services during Laravel's register phase |
| `boot()` | Boot plugin during Laravel's boot phase |
| `getRoutes()` | Get route definitions |
| `getMiddleware()` | Get middleware stack |
| `getDependencies()` | Get plugin dependencies |
| `getViewNamespace()` | Get the views namespace |
| `getMigrationsPath()` | Get migrations path |
| `getFrontendSlots()` | Get frontend component slots |
| `requiresLicense()` | Check if license is required |
| `getPermissions()` | Get permission definitions |

---

## 4️⃣ Permission Model

**Initial permission list (expandable, never remove):**

- `economy.read` - Read economy data
- `economy.write` - Modify economy data
- `inventory.read` - Read inventory data
- `inventory.write` - Modify inventory data
- `combat.modify` - Modify combat settings
- `cooldowns.modify` - Modify cooldowns

You may add more later, but never remove or rename existing permissions.

Plugins must declare all permissions they require in `plugin.json`:

```json
{
    "permissions": {
        "my-plugin.view": "View plugin content",
        "my-plugin.use": "Use plugin features",
        "my-plugin.admin": "Administer plugin"
    }
}
```

---

## 5️⃣ Hook Registration

Plugins register hook listeners in `hooks.php`, which is auto-loaded by `AutoPluginHookLoader` only when the plugin is enabled.

### Two Registration Formats

**Declarative** (no priority control — all run at default priority `10`):

```php
return [
    'alterCrimeRewards' => function (array $data): array {
        $data['cash'] = (int) ($data['cash'] * 1.10);
        return $data;
    },
    'OnCrimeCommit' => function (array $data): void {
        \Log::info('Crime committed', ['player' => $data['player']->id]);
    },
];
```

**Direct/Side-effect** (supports priority and multiple listeners per hook):

```php
use App\Facades\Hook;

// Transform hook with priority
Hook::register('alterCrimeRewards', function (array $data): array {
    $data['cash'] = (int) ($data['cash'] * 1.10);
    return $data;
}, priority: 50);

// Side-effect hook with default priority
Hook::register('OnCrimeCommit', function (array $data): void {
    \Log::info('Crime committed', ['player' => $data['player']->id]);
});
```

### Priority Rules

- `Hook::register()` accepts an optional `int $priority` (default: `10`)
- **Higher numbers execute first** — a listener at `100` runs before `50`, which runs before `10`
- Listeners with equal priority run in registration order
- Sorting is lazy — applied the first time a hook fires for each hook name
- Declarative format always uses the default priority (`10`). Use direct registration when order matters

### Exception Isolation

If a listener throws, the exception is caught and logged. The **remaining listeners still execute** — one failing plugin cannot block another's hooks from firing.

See [docs/PLUGIN_HOOKS.md](docs/PLUGIN_HOOKS.md) for the full hook catalogue and usage examples.

---

## 6️⃣ Metadata System

Plugins must use the `plugin_metadata` table for storing user-specific data. **Never create new columns on the users table.**

### Using HasPluginMetadata Trait

The trait is automatically available on the User model:

```php
// Set a single value
$user->setPluginMeta('your-plugin', 'score', 100);

// Set multiple values
$user->setManyPluginMeta('your-plugin', [
    'level' => 5,
    'experience' => 250,
]);

// Get a value
$score = $user->getPluginMeta('your-plugin', 'score', 0);

// Increment/decrement
$newScore = $user->incrementPluginMeta('your-plugin', 'score', 50);

// Check existence
if ($user->hasPluginMeta('your-plugin', 'tutorial_completed')) {
    // ...
}

// Delete
$user->deletePluginMeta('your-plugin', 'temp_data');
```

### Cleanup on Uninstall

Always clean up metadata in your `uninstall()` method:

```php
public function uninstall(): void
{
    \App\Core\Models\PluginMetadata::where('plugin_id', $this->getId())->delete();
}
```

---

## 7️⃣ Frontend Integration

### Defining Slots

Define frontend component slots in `plugin.json`:

```json
{
    "frontend": {
        "slots": {
            "dashboard-widget": ["GoldWidget.vue", "StatsWidget.vue"],
            "header-link": ["RpgNav.vue"],
            "sidebar-widget": ["QuickStats.vue"]
        },
        "routes": [
            {
                "path": "/rpg",
                "name": "rpg-dashboard",
                "component": "Dashboard.vue",
                "meta": { "title": "RPG Dashboard" }
            }
        ]
    }
}
```

### Available Slot Names

| Slot Name | Description |
|-----------|-------------|
| `dashboard-widget` | Widgets shown on the dashboard |
| `header-link` | Links in the header navigation |
| `sidebar-widget` | Widgets in the sidebar |
| `user-profile` | Profile page widgets |
| `combat-panel` | Combat action panels |

---

## 8️⃣ API Routes

### Automatic Prefixing

Plugin API routes are automatically prefixed:

```php
// routes/api.php
Route::get('/stats', [RpgController::class, 'stats']);
// Becomes: GET /api/v1/p/your-plugin/stats
```

### Route Types and URLs

| Type | File | Full URL |
|------|------|----------|
| Web | `routes/web.php` | `/your-plugin` |
| API | `routes/api.php` | `/api/v1/p/your-plugin/...` |
| Admin | `routes/admin.php` | `/admin/your-plugin` |

### Middleware

Plugin routes automatically receive appropriate middleware:

```php
// Web routes: web, auth
// API routes: api, auth:sanctum
// Admin routes: web, auth, admin
```

Use the `plugin.access` middleware for license-protected plugins:

```php
Route::middleware(['plugin.access:your-plugin'])->group(function () {
    // Protected routes
});
```

---

## 9️⃣ Lifecycle Requirements

Plugins must implement these lifecycle methods:

| Method | When Called | Purpose |
|--------|-------------|---------|
| `install()` | First time plugin is installed | Initial setup, migrations, seeding |
| `enable()` | When admin enables the plugin | Register event listeners, initialize |
| `disable()` | When admin disables the plugin | Cleanup, remove listeners |
| `uninstall()` | When plugin is fully removed | **CRITICAL: Clean up ALL data** |
| `upgrade(string $from, string $to)` | When upgrading versions | Migration between versions |

### Required Cleanup on Uninstall

```php
public function uninstall(): void
{
    // Delete all plugin metadata
    \App\Core\Models\PluginMetadata::where('plugin_id', $this->getId())->delete();
    
    // Delete plugin settings
    app(\App\Core\Services\SettingService::class)->forget("plugins.{$this->getId()}");
    
    // Log the cleanup
    $this->log('info', 'Plugin uninstalled and all data cleaned up');
}
```

---

## Summary

This contract is the foundation for all plugin development. Adhering to these specifications ensures:

1. **Compatibility** — Plugins work together without conflicts
2. **Security** — License protection and permission checks work correctly
3. **Maintainability** — Clean upgrade paths and cleanup
4. **Performance** — Lazy loading and caching work as intended
5. **User Experience** — Consistent UI integration

**Breaking this contract will break plugin compatibility.**
