# Plugin Development Guide

The LaravelCP plugin system enables modular feature development without modifying core code. This comprehensive guide covers everything you need to build, deploy, and maintain plugins for your game server.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Quick Start](#quick-start)
3. [Plugin Structure](#plugin-structure)
4. [Plugin Manifest (plugin.json)](#plugin-manifest-pluginjson)
5. [Plugin Class](#plugin-class)
6. [Hook System](#hook-system)
7. [Metadata System](#metadata-system)
8. [Frontend Integration](#frontend-integration)
9. [API Routes](#api-routes)
10. [WebSocket Broadcasting](#websocket-broadcasting)
11. [Middleware & Permissions](#middleware--permissions)
12. [Lifecycle Methods](#lifecycle-methods)
13. [Best Practices](#best-practices)
14. [Example: Complete Plugin](#example-complete-plugin)

---

## Architecture Overview

The plugin system is built on several key components:

```
┌─────────────────────────────────────────────────────────────────┐
│                        Laravel Application                        │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌────────────────┐ │
│  │  PluginManager  │  │  HookService    │  │  HubStore      │ │
│  │  (Loads/Enables)│  │  (Event System) │  │  (Frontend)    │ │
│  └────────┬────────┘  └────────┬────────┘  └───────┬────────┘ │
│           │                     │                    │          │
│  ┌────────▼────────────────────▼────────────────────▼────────┐ │
│  │                    Plugin Instance                         │ │
│  │  ┌─────────────┐ ┌─────────────┐ ┌─────────────────────┐ │ │
│  │  │ plugin.json  │ │ hooks.php   │ │ Vue Components      │ │ │
│  │  │ (Manifest)  │ │ (Listeners) │ │ (Frontend Slots)    │ │ │
│  │  └─────────────┘ └─────────────┘ └─────────────────────┘ │ │
│  └────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

### Key Components

| Component | Description |
|-----------|-------------|
| `Plugin` | Base class all plugins extend |
| `PluginInterface` | Contract defining required methods |
| `PluginLifecycleInterface` | Lifecycle hooks (install/enable/disable/uninstall) |
| `HookService` | Event dispatcher for plugin extensibility |
| `HasPluginMetadata` | Trait for storing user-specific data |
| `HubStore` | Frontend store for plugin state |
| `PluginSlot` | Vue component for rendering plugin UI |

---

## Quick Start

### Step 1: Create Plugin Directory

```bash
mkdir -p app/Plugins/YourPlugin/{routes,views,Controllers/Api,Services,Models,database/migrations}
```

### Step 2: Create plugin.json Manifest

```json
{
    "name": "Your Plugin",
    "slug": "your-plugin",
    "version": "1.0.0",
    "description": "Description of your plugin",
    "author": "Your Name",
    "enabled": true,
    "license_required": false,
    "requires": {
        "laravel": "^11.0",
        "plugins": {}
    },
    "settings": {
        "icon": "🔌",
        "color": "blue",
        "route": "your-plugin.index",
        "menu": {
            "enabled": true,
            "order": 100,
            "section": "main"
        }
    },
    "routes": {
        "web": true,
        "api": true,
        "admin": false
    },
    "permissions": {
        "your-plugin.view": "View plugin content",
        "your-plugin.use": "Use plugin features"
    },
    "frontend": {
        "slots": {
            "dashboard-widget": ["YourWidget.vue"]
        },
        "routes": [
            {
                "path": "/your-plugin",
                "name": "your-plugin-index",
                "component": "Index.vue",
                "meta": { "title": "Your Plugin" }
            }
        ]
    }
}
```

### Step 3: Create Plugin Class

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
        // Register services, bindings, etc.
    }

    public function boot(): void
    {
        // Register hooks, event listeners
        $this->registerHooks();
    }

    public function install(): void
    {
        // First-time setup
    }

    public function enable(): void
    {
        // Enable runtime features
    }

    public function disable(): void
    {
        // Disable runtime features
    }

    public function uninstall(): void
    {
        // Clean up all data
    }
}
```

### Step 4: Create Hooks (Optional)

```php
// app/Plugins/YourPlugin/hooks.php
use App\Facades\Hook;

// Listen for user creation
Hook::register('user.created', function ($user) {
    $user->setPluginMeta('your-plugin', 'initialized', true);
});

// Modify data before display
Hook::register('stats.display', function ($stats) {
    $stats['your_plugin'] = ['custom' => 'data'];
    return $stats;
}, 50); // Higher priority = runs first
```

---

## Plugin Structure

Every plugin must follow this directory structure:

```
app/Plugins/YourPlugin/
├── plugin.json              # Required: Plugin manifest
├── YourPluginPlugin.php    # Required: Main plugin class
├── hooks.php              # Optional: Hook listeners
├── routes/
│   ├── web.php            # Optional: Web routes
│   ├── api.php            # Optional: API routes
│   └── admin.php          # Optional: Admin routes
├── Controllers/
│   ├── Web/
│   │   └── YourController.php
│   └── Api/
│       └── YourApiController.php
├── Services/
│   └── YourService.php
├── Models/
│   └── YourModel.php
├── views/                 # Optional: Blade templates
│   └── index.blade.php
├── lang/                  # Optional: Translation files
│   └── en/
│       └── messages.php
├── database/
│   └── migrations/        # Optional: Plugin migrations
│       └── 2024_01_01_000000_create_your_table.php
└── assets/                # Optional: CSS, JS, images
    └── js/
        └── main.js
```

---

## Plugin Manifest (plugin.json)

The `plugin.json` file is the single source of truth for plugin configuration.

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Human-readable plugin name |
| `slug` | string | Unique identifier (kebab-case) |
| `version` | string | Semantic version (e.g., "1.0.0") |
| `author` | string | Plugin author name |
| `enabled` | boolean | Whether plugin loads on boot |
| `requires.laravel` | string | Minimum Laravel version |
| `requires.plugins` | object | Plugin dependencies |
| `routes.web` | boolean | Has web routes |
| `routes.api` | boolean | Has API routes |
| `routes.admin` | boolean | Has admin routes |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `description` | string | Short description |
| `license_required` | boolean | Requires valid license |
| `settings` | object | UI configuration |
| `settings.icon` | string | Emoji icon |
| `settings.color` | string | UI color theme |
| `settings.route` | string | Main route name |
| `settings.menu` | object | Menu configuration |
| `permissions` | object | Permission definitions |
| `hooks` | object | Hook declarations |
| `frontend` | object | Frontend configuration |
| `frontend.slots` | object | Component slots |
| `frontend.routes` | object | Frontend routes |

### Complete Example

```json
{
    "name": "Advanced RPG",
    "slug": "advanced-rpg",
    "version": "2.0.0",
    "description": "Complete RPG system with quests, achievements, and progression",
    "author": "OpenPBBG",
    "enabled": true,
    "license_required": false,
    "requires": {
        "laravel": "^11.0",
        "plugins": {
            "inventory": "^2.0.0"
        }
    },
    "settings": {
        "icon": "⚔️",
        "color": "purple",
        "route": "rpg.dashboard",
        "menu": {
            "enabled": true,
            "order": 10,
            "section": "actions",
            "parent": null
        }
    },
    "permissions": {
        "rpg.view": "View RPG content",
        "rpg.play": "Play RPG game",
        "rpg.admin": "Administer RPG"
    },
    "hooks": {
        "user.created": true,
        "stats.display": true,
        "combat.end": true
    },
    "routes": {
        "web": true,
        "api": true,
        "admin": true
    },
    "frontend": {
        "slots": {
            "dashboard-widget": ["GoldWidget.vue", "LevelWidget.vue"],
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

---

## Plugin Class

Your plugin class extends the base `Plugin` class and implements `PluginInterface`.

### Required Methods

```php
<?php

namespace App\Plugins\YourPlugin;

use App\Plugins\Plugin;
use App\Core\Contracts\PluginInterface;

class YourPluginPlugin extends Plugin implements PluginInterface
{
    /**
     * Constructor - loads manifest from plugin.json
     */
    public function __construct()
    {
        parent::__construct(app_path('Plugins/YourPlugin'));
    }

    /**
     * Register services - called during Laravel's "register" phase
     * Use for: service bindings, event listeners, middleware
     * DO NOT: use other services that aren't registered yet
     */
    public function register(): void
    {
        // Register singleton services
        $this->app->singleton('yourplugin.service', function ($app) {
            return new Services\YourService();
        });
    }

    /**
     * Boot plugin - called during Laravel's "boot" phase
     * Use for: registering hooks, final setup
     * Safe to use other services here
     */
    public function boot(): void
    {
        $this->registerHooks();
    }
}
```

### Inherited Methods

The base `Plugin` class provides these methods:

| Method | Description |
|--------|-------------|
| `getId()` | Get plugin slug |
| `getName()` | Get plugin name |
| `getVersion()` | Get version string |
| `getManifest()` | Get parsed manifest |
| `getPath()` | Get filesystem path |
| `getNamespace()` | Get PHP namespace |
| `getRoutes()` | Get route definitions |
| `getMiddleware()` | Get middleware stack |
| `getDependencies()` | Get plugin dependencies |
| `getViewNamespace()` | Get view namespace |
| `getFrontendSlots()` | Get frontend slots |
| `requiresLicense()` | Check license requirement |
| `getPermissions()` | Get permission definitions |

---

## Hook System

Hooks allow plugins to listen for and react to game events.

### Registration Methods

#### 1. Declarative Format (Simple)

Return an associative array from `hooks.php`:

```php
// app/Plugins/MyPlugin/hooks.php
return [
    'economy.credit' => function (array $data): array {
        $data['amount'] *= 1.10; // +10% bonus
        return $data;
    },
    'OnCrimeCommit' => function (array $data): void {
        \Log::info('Crime committed', ['player' => $data['player']->id]);
    },
];
```

#### 2. Direct Registration (With Priority)

Use `Hook::register()` for priority control:

```php
use App\Facades\Hook;

// High priority - runs first (modifiers)
Hook::register('alterCrimeRewards', function (array $data): array {
    if ($data['player']->hasBoost('crime_bonus')) {
        $data['cash'] = (int) ($data['cash'] * 1.25);
    }
    return $data;
}, priority: 50);

// Default priority - runs after (observers)
Hook::register('OnCrimeCommit', function (array $data): void {
    \App\Plugins\MyPlugin\Models\CrimeLog::record($data['player'], $data['crime']);
});
```

### Transform vs Side-Effect Hooks

| Type | Return Value | Use Case |
|------|--------------|----------|
| **Transform** | Modified `$data` | Modifying values (bonuses, restrictions) |
| **Side-effect** | `null`/`void` | Logging, notifications, analytics |

```php
// Transform hook - must return modified data
Hook::register('economy.credit', function (array $data): array {
    $data['amount'] = applyTax($data['amount']);
    return $data;
});

// Side-effect hook - return ignored
Hook::register('OnCrimeCommit', function (array $data): void {
    sendNotification($data['player'], 'You committed a crime!');
});
```

### Priority

- **Higher numbers run first**: `priority: 100` runs before `priority: 10`
- **Default is 10**: If not specified
- **Equal priority**: Uses registration order
- **Lazy sorting**: Sorted on first hook fire, then cached

### Core Hooks Catalogue

| Hook | Type | Description |
|------|------|-------------|
| `user.created` | side-effect | New user registered |
| `user.profile.widgets` | transform | Add widgets to profile |
| `stats.display` | transform | Modify stats display |
| `customMenus` | transform | Add menu items |
| `economy.credit` | transform | Player receives money |
| `economy.debit` | transform | Player spends money |
| `OnCrimeCommit` | side-effect | Crime committed |
| `alterCrimeRewards` | transform | Modify crime rewards |
| `OnItemBought` | side-effect | Item purchased |
| `OnItemSold` | side-effect | Item sold |
| `inventory.change` | side-effect | Inventory modified |
| `afterCombat` | side-effect | Combat resolved |
| `alterCombatTarget` | transform | Modify combat target |
| `modifyCombatPower` | transform | Modify combat power |
| `player.experience.gained` | transform | XP awarded |

### Exception Safety

If a hook listener throws an exception:
- The exception is caught and logged
- **Remaining listeners still execute**
- For transform hooks, the chain continues with the last successful value

---

## Metadata System

Store user-specific plugin data without modifying the core database schema.

### Using the HasPluginMetadata Trait

The trait is automatically available on your User model:

```php
// Set a single value
$user->setPluginMeta('your-plugin', 'score', 100);

// Set multiple values at once
$user->setManyPluginMeta('your-plugin', [
    'level' => 5,
    'experience' => 250,
    'achievements' => ['first_win', 'collector'],
]);

// Get a single value (with default)
$score = $user->getPluginMeta('your-plugin', 'score', 0);

// Get all plugin data
$allData = $user->getAllPluginMeta('your-plugin');

// Increment/decrement (atomic operations)
$newScore = $user->incrementPluginMeta('your-plugin', 'score', 50);
$newLevel = $user->incrementPluginMeta('your-plugin', 'level', 1);

// Check if key exists
if ($user->hasPluginMeta('your-plugin', 'tutorial_completed')) {
    // ...
}

// Delete a key
$user->deletePluginMeta('your-plugin', 'temp_data');

// Delete all plugin data
$user->deleteAllPluginMeta('your-plugin');
```

### Example: Initialize User Data on Registration

```php
// In your plugin class or hooks.php
Hook::register('user.created', function ($user) {
    $user->setManyPluginMeta('your-plugin', [
        'gold' => 100,
        'level' => 1,
        'experience' => 0,
    ]);
});
```

---

## Frontend Integration

### Using the PluginSlot Component

```vue
<template>
  <div>
    <!-- Render all widgets for a slot -->
    <PluginSlot slot-name="dashboard-widget" />
    
    <!-- With props -->
    <PluginSlot 
      slot-name="header-link" 
      :user="currentUser"
    />
  </div>
</template>

<script setup>
import PluginSlot from '@/components/PluginSlot.vue'
</script>
```

### Using the Hub Store

```typescript
import { useHubStore } from '@/stores/hub'

const hub = useHubStore()

// Check if plugin is active
if (hub.isPluginActive('mini-rpg')) {
  // Do something
}

// Get components for a slot
const widgets = hub.getComponentsForSlot('dashboard-widget')

// Get plugin setting
const icon = hub.getPluginSetting('mini-rpg', 'icon', '🔌')

// Get menu items
const menuItems = hub.getMenuItems('main')
```

### Using the Plugin Bus

```typescript
import { 
  registerHeaderLink, 
  registerDashboardWidget,
  onPluginEvent 
} from '@/services/plugin-bus'

// Register a navigation link
registerHeaderLink({
  id: 'my-plugin-link',
  title: 'My Plugin',
  icon: '🎮',
  to: '/my-plugin',
})

// Register a dashboard widget
registerDashboardWidget({
  id: 'my-widget',
  title: 'My Widget',
  component: 'MyWidget.vue',
  order: 10,
})

// Listen for plugin events
onPluginEvent('gold_updated', (data) => {
  console.log('Gold changed:', data.gold)
})
```

### WebSocket Subscriptions

```typescript
import { websocketService } from '@/services/websocket'

// Subscribe to plugin channel
const unsubscribe = websocketService.subscribeToPlugin(
  'mini-rpg',
  'updates',
  (data, message) => {
    console.log('Received:', data)
  }
)

// Later: unsubscribe when component unmounts
onUnmounted(() => {
  unsubscribe()
})
```

### Defining Frontend Slots in plugin.json

```json
{
    "frontend": {
        "slots": {
            "dashboard-widget": ["GoldWidget.vue", "StatsWidget.vue"],
            "header-link": ["RpgNav.vue"],
            "sidebar-widget": ["QuickStats.vue"],
            "user-profile": ["ProfileWidget.vue"],
            "combat-panel": ["CombatActions.vue"]
        },
        "routes": [
            {
                "path": "/rpg",
                "name": "rpg-dashboard",
                "component": "Dashboard.vue",
                "meta": { 
                    "title": "RPG Dashboard",
                    "requiresAuth": true
                }
            }
        ]
    }
}
```

---

## API Routes

### Automatic Route Prefixing

Plugin API routes are automatically prefixed:

```php
// routes/api.php
Route::get('/stats', [RpgController::class, 'stats']);
// Becomes: GET /api/v1/p/your-plugin/stats
```

### Full Path Examples

| Type | File | Full URL |
|------|------|----------|
| Web | `routes/web.php` | `/your-plugin` |
| API | `routes/api.php` | `/api/v1/p/your-plugin/...` |
| Admin | `routes/admin.php` | `/admin/your-plugin` |

### Example API Controller

```php
<?php

namespace App\Plugins\YourPlugin\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class YourController extends Controller
{
    public function stats(): JsonResponse
    {
        $user = auth()->user();
        
        return response()->json([
            'success' => true,
            'data' => [
                'score' => $user->getPluginMeta('your-plugin', 'score', 0),
                'level' => $user->getPluginMeta('your-plugin', 'level', 1),
            ]
        ]);
    }
}
```

---

## WebSocket Broadcasting

### Broadcasting to All Users

```php
use function broadcastToPlugin;

broadcastToPlugin('your-plugin', 'event_name', [
    'key' => 'value',
]);
```

### Broadcasting to Specific User

```php
use function broadcastToPluginUser;

broadcastToPluginUser($userId, 'your-plugin', 'stats_updated', [
    'score' => $newScore,
    'level' => $newLevel,
]);
```

### Example: Broadcast on Gold Change

```php
public function addGold($user, int $amount): int
{
    $newGold = $user->incrementPluginMeta('mini-rpg', 'gold', $amount);

    // Broadcast to the specific user
    broadcastToPluginUser($user->id, 'mini-rpg', 'gold_updated', [
        'gold' => $newGold,
        'change' => $amount,
    ]);

    return $newGold;
}
```

---

## Middleware & Permissions

### Plugin Access Middleware

Protect plugin routes with built-in middleware:

```php
// routes/api.php
Route::middleware(['plugin.access:your-plugin'])->group(function () {
    Route::get('/stats', [YourController::class, 'stats']);
    Route::post('/action', [YourController::class, 'action']);
});
```

### License Protection

Set `license_required: true` in `plugin.json` to require a valid license:

```json
{
    "license_required": true
}
```

The `plugin.access` middleware will verify the license before allowing access.

### Custom Permissions

Define permissions in `plugin.json`:

```json
{
    "permissions": {
        "rpg.view": "View RPG content",
        "rpg.play": "Play RPG game",
        "rpg.admin": "Administer RPG settings"
    }
}
```

Use in routes:

```php
Route::middleware(['auth', 'can:rpg.play'])->group(function () {
    Route::get('/play', [RpgController::class, 'play']);
});
```

---

## Lifecycle Methods

The plugin lifecycle follows these stages:

### Installation (One-time)

```php
public function install(): void
{
    // Run migrations
    $this->runMigrations();
    
    // Seed default data
    $this->seedDefaults();
    
    // Log
    $this->log('info', 'Plugin installed');
}
```

### Enabling

```php
public function enable(): void
{
    // Register event listeners
    // Clear caches
    // Initialize features
    
    $this->log('info', 'Plugin enabled');
}
```

### Disabling

```php
public function disable(): void
{
    // Remove event listeners
    // Hide from UI (they won't load anyway)
    
    $this->log('info', 'Plugin disabled');
}
```

### Uninstallation (Cleanup)

```php
public function uninstall(): void
{
    // CRITICAL: Clean up all user data
    \App\Core\Models\PluginMetadata::where('plugin_id', $this->getId())->delete();
    
    // Drop plugin tables
    // Clear caches
    
    $this->log('info', 'Plugin uninstalled');
}
```

### Upgrading

```php
public function upgrade(string $fromVersion, string $toVersion): void
{
    // Handle migration between versions
    if (version_compare($fromVersion, '2.0.0', '<')) {
        // Run v2.0 migration logic
    }
    
    $this->log('info', "Upgraded from {$fromVersion} to {$toVersion}");
}
```

---

## Best Practices

### 1. Use Metadata for User Data

Always use `plugin_metadata` table for user-specific data:

```php
// ✅ Good: Uses metadata
$user->setPluginMeta('my-plugin', 'score', 100);

// ❌ Bad: Creates new columns
User::where('id', $user->id)->update(['my_plugin_score' => 100]);
```

### 2. Namespace Your Hooks

Avoid collisions by prefixing:

```php
// ✅ Good
Hook::register('my-plugin.custom_event', function ($data) { ... });

// ❌ Bad - could conflict
Hook::register('custom_event', function ($data) { ... });
```

### 3. Use Priority Wisely

- **High priority (50-100)**: Modifiers that change data
- **Default priority (10)**: Observers that just react
- **Low priority (1-5)**: Final processing

### 4. Broadcast Real-time Updates

Always notify users of changes:

```php
public function purchaseItem($user, $item): void
{
    // Update data
    $user->decrementPluginMeta('inventory', 'gold', $item->price);
    
    // ✅ Good: User sees instant feedback
    broadcastToPluginUser($user->id, 'shop', 'purchase_complete', [
        'item' => $item->name,
        'remaining_gold' => $user->getPluginMeta('inventory', 'gold'),
    ]);
}
```

### 5. Handle Exceptions

Keep hooks safe - don't break the chain:

```php
Hook::register('alterCombatRewards', function (array $data): array {
    try {
        // Risky operation
        $bonus = calculateBonus($data['player']);
        $data['cash'] += $bonus;
    } catch (\Exception $e) {
        // Log but don't throw
        \Log::error('Bonus calculation failed: ' . $e->getMessage());
    }
    return $data;
});
```

### 6. Clean Up on Uninstall

Always remove all traces:

```php
public function uninstall(): void
{
    // Delete all metadata
    \App\Core\Models\PluginMetadata::where('plugin_id', $this->getId())->delete();
    
    // Delete plugin settings
    app(\App\Core\Services\SettingService::class)->forget("plugins.{$this->getId()}");
}
```

---

## Example: Complete Plugin

For a complete reference implementation, see the **MiniRpg** plugin:

```
app/Plugins/MiniRpg/
├── plugin.json              # Full manifest example
├── MiniRpgPlugin.php       # Complete plugin class
├── hooks.php               # All hook types
├── routes/
│   └── api.php             # API routes
├── Controllers/
│   └── Api/
│       └── RpgController.php
└── Services/
    ├── RpgStatsService.php
    └── CombatService.php
```

This plugin demonstrates:
- ✅ PluginInterface implementation
- ✅ Metadata storage for user stats
- ✅ Hook registration (user.created, stats.display, customMenus)
- ✅ WebSocket broadcasting
- ✅ API routes and controllers
- ✅ Frontend slot definitions
- ✅ Lifecycle methods (install, enable, disable, uninstall)

---

## Admin Settings

Plugins can register their own settings tabs in the admin panel by defining `admin_settings` in `plugin.json`.

### Basic Structure

```json
{
    "admin_settings": {
        "combat": {
            "label": "Combat",
            "icon": "FireIcon",
            "order": 10,
            "settings": {
                "attack_cooldown": {
                    "type": "number",
                    "label": "Attack Cooldown (seconds)",
                    "default": 300,
                    "description": "Cooldown between attacks",
                    "min": 0,
                    "max": 3600
                },
                "allow_friendly_fire": {
                    "type": "boolean",
                    "label": "Allow Friendly Fire",
                    "default": false,
                    "description": "Allow players to attack gang members"
                }
            }
        }
    }
}
```

### Multiple Settings Groups

A plugin can define multiple settings groups:

```json
{
    "admin_settings": {
        "economy": {
            "label": "Economy",
            "icon": "BanknotesIcon",
            "order": 20,
            "settings": {
                "starting_cash": {
                    "type": "number",
                    "label": "Starting Cash",
                    "default": 1000,
                    "min": 0
                },
                "interest_rate": {
                    "type": "number",
                    "label": "Bank Interest Rate (%)",
                    "default": 5,
                    "min": 0,
                    "max": 100,
                    "step": 0.5
                }
            }
        },
        "features": {
            "label": "Feature Toggles",
            "icon": "WrenchScrewdriverIcon",
            "order": 30,
            "settings": {
                "enable_auctions": {
                    "type": "boolean",
                    "label": "Enable Auctions",
                    "default": true
                },
                "enable_trading": {
                    "type": "boolean",
                    "label": "Enable Player Trading",
                    "default": true
                }
            }
        }
    }
}
```

### Setting Types

| Type | Description | Additional Fields |
|------|-------------|-------------------|
| `text` | Text input | `placeholder` |
| `number` | Numeric input | `min`, `max`, `step` |
| `boolean` | Toggle switch | - |
| `select` | Dropdown select | `options` (array of `{value, label}`) |
| `json` | JSON editor | - |

### Select Type Example

```json
{
    "difficulty": {
        "type": "select",
        "label": "Game Difficulty",
        "default": "normal",
        "description": "Overall game difficulty setting",
        "options": [
            {"value": "easy", "label": "Easy"},
            {"value": "normal", "label": "Normal"},
            {"value": "hard", "label": "Hard"},
            {"value": "extreme", "label": "Extreme"}
        ]
    }
}
```

### Available Icons

Use these icon names for your settings groups:

- `Cog6ToothIcon` - General settings
- `FireIcon` - Combat/action
- `BanknotesIcon` - Economy/money
- `ChartBarIcon` - Statistics/progression
- `ClockIcon` - Timers/cooldowns
- `WrenchScrewdriverIcon` - Features/tools
- `ShieldCheckIcon` - Security
- `UserGroupIcon` - Users/teams
- `ServerIcon` - System
- `BoltIcon` - Performance
- `PuzzlePieceIcon` - Plugins
- `GlobeAltIcon` - Global/regional
- `ChatBubbleLeftRightIcon` - Communication
- `Squares2X2Icon` - Dashboard
- `TrophyIcon` - Achievements
- `MapIcon` - Locations
- `TruckIcon` - Transport
- `BuildingOfficeIcon` - Properties
- `CurrencyDollarIcon` - Currency
- `HeartIcon` - Health
- `KeyIcon` - Authentication

### Accessing Settings in Your Plugin

Use the `getSetting()` method inherited from the base Plugin class:

```php
// In your plugin class
$cooldown = $this->getSetting('attack_cooldown', 300);

// Or anywhere using the SettingService
use App\Core\Services\SettingService;

$setting = app(SettingService::class)->get('plugin.your-plugin.attack_cooldown', 300);
```

### Setting Storage

Settings are automatically prefixed with `plugin.{slug}.` in the database to avoid collisions. For example, a setting named `attack_cooldown` in a plugin with slug `combat-plugin` will be stored as:

```
Key: plugin.combat-plugin.attack_cooldown
```

### Complete Admin Settings Example

```json
{
    "name": "Crime System",
    "slug": "crime-system",
    "version": "1.0.0",
    "admin_settings": {
        "crime_settings": {
            "label": "Crime Settings",
            "icon": "FireIcon",
            "order": 10,
            "settings": {
                "crime_cooldown": {
                    "type": "number",
                    "label": "Crime Cooldown (seconds)",
                    "default": 120,
                    "description": "Time between crimes",
                    "min": 30,
                    "max": 600
                },
                "crime_success_base_rate": {
                    "type": "number",
                    "label": "Base Success Rate (%)",
                    "default": 70,
                    "min": 0,
                    "max": 100
                },
                "payout_multiplier": {
                    "type": "number",
                    "label": "Payout Multiplier",
                    "default": 1.0,
                    "min": 0.1,
                    "max": 10,
                    "step": 0.1
                }
            }
        },
        "crime_features": {
            "label": "Crime Features",
            "icon": "WrenchScrewdriverIcon",
            "order": 20,
            "settings": {
                "enable_organized_crime": {
                    "type": "boolean",
                    "label": "Enable Organized Crime",
                    "default": true,
                    "description": "Allow gang-based organized crimes"
                },
                "enable_witnesses": {
                    "type": "boolean",
                    "label": "Enable Witness System",
                    "default": true
                }
            }
        }
    }
}
```

---

## Additional Resources

- [Hook System Documentation](../docs/PLUGIN_HOOKS.md)
- [Plugin Contract](../PLUGIN_CONTRACT.md)
- [Plugin Registry](../docs/PLUGIN_REGISTRY.md)
- [PluginInterface Source](../app/Core/Contracts/PluginInterface.php)
- [Plugin Base Class Source](../app/Plugins/Plugin.php)
