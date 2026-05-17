# Plugin System Bug Tracking

This document tracks current bugs and issues with the LaravelCP plugin management system.

---

## Resolved Bugs

### BUG-001: All Plugins Show as Disabled in Admin Panel

**Status:** ✅ RESOLVED  
**Severity:** High  
**Discovered:** 2026-02-24  
**Resolved:** 2026-02-24  
**Affected URL:** `http://localhost:8001/admin/plugin-settings`

#### Description
In the admin panel plugin settings page, all installed plugins were displayed as "disabled" despite being functionally active on the frontend.

#### Root Cause
The `installed_plugins` database table was empty. `PluginManagerService::getAllPlugins()` checked for records in this table to determine enabled status.

#### Resolution
- Implemented auto-registration in `PluginManagerService::getAllPlugins()` that creates `InstalledPlugin` records for plugins discovered in `app/Plugins/` that don't have a database record
- Added `InstalledPlugin::createFromManifest()` method for easy plugin registration from `plugin.json`
- Consolidated plugin tracking to use `InstalledPlugin` model exclusively

---

### BUG-002: "Module not installed" Error on Enable Action

**Status:** ✅ RESOLVED  
**Severity:** High  
**Discovered:** 2026-02-24  
**Resolved:** 2026-02-24  
**Affected URL:** `http://localhost:8001/admin/plugin-settings`

#### Description
When clicking "Enable" on any plugin in the admin panel, the system returned an error: "Module not installed."

#### Root Cause
`PluginManagerService::enablePlugin()` checked for `InstalledPlugin` record before allowing enable action. Since no records existed, the check always failed.

#### Resolution
Auto-registration (see BUG-001) ensures all discovered plugins have database records, so the enable action now works correctly.

---

### BUG-003: "Module not installed" Error on Uninstall Action

**Status:** ✅ RESOLVED  
**Severity:** High  
**Discovered:** 2026-02-24  
**Resolved:** 2026-02-24  
**Affected URL:** `http://localhost:8001/admin/plugin-settings`

#### Description
When clicking "Uninstall" on any plugin in the admin panel, the system returned an error: "Module not installed."

#### Root Cause
Same as BUG-002 - `PluginManagerService::uninstallPlugin()` checked for `InstalledPlugin` record.

#### Resolution
Auto-registration ensures all discovered plugins have database records, so the uninstall action now works correctly.

---

### BUG-004: Frontend Plugins Display and Work Correctly

**Status:** ✅ RESOLVED (Consolidated)  
**Severity:** N/A

#### Description
Frontend plugins worked correctly because they used a different model (`Plugin` vs `InstalledPlugin`).

#### Resolution
Consolidated to use `InstalledPlugin` model across both frontend (`PluginService`) and admin (`PluginManagerService`).

---

## Current Bugs

### BUG-005: Dashboard Plugin Links Not Displaying

**Status:** ✅ RESOLVED  
**Severity:** High  
**Discovered:** 2026-02-25  
**Resolved:** 2026-02-25  
**Affected URL:** `http://localhost:5175/dashboard`

#### Description
On the dashboard, all plugin module links were hidden because the frontend's `isPluginEnabled()` check returned `false` for all plugins. The dashboard uses `v-if="isPluginEnabled('hospital')"` directives to conditionally display plugin navigation links, but these conditions always evaluated to `false`.

#### Root Cause
The `PluginManifestService::getEnabledPluginsForFrontend()` method queries only the `installed_plugins` database table:

```php
public function getEnabledPluginsForFrontend(): Collection
{
    $plugins = InstalledPlugin::plugins()
        ->where('enabled', true)  // ← Only returns DB records with enabled=true
        ->orderBy('order')
        ->get();
    // ...
}
```

However, the `PluginServiceProvider` that discovers plugins from the filesystem (`app/Plugins/`) during bootstrap **never created corresponding database records**. This caused a disconnect:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     FRONTEND (Dashboard)                                     │
│  HomeView.vue → pluginsStore.isEnabled('hospital')                          │
│                              ↓                                               │
│              fetchPlugins() → GET /api/v1/plugins/enabled                   │
└─────────────────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                     BACKEND (PluginManifestService)                          │
│  getEnabledPluginsForFrontend() → InstalledPlugin::where('enabled', true)   │
│                                              ↓                               │
│                                    installed_plugins TABLE                   │
│                                    (EMPTY - No records)                      │
│                                                                              │
│  Returns: [] (empty array) → No plugins to display                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Resolution
Added `ensurePluginsRegistered()` method to `PluginServiceProvider::boot()` that:

1. Checks if the `installed_plugins` table exists (graceful handling during migrations)
2. Iterates through already-discovered plugins (`$this->sortedPlugins`)
3. Creates missing database records using `InstalledPlugin::createFromManifest()`
4. Syncs existing records with latest manifest data via `syncFromManifest()`
5. **Clears the `plugins.frontend_manifest` cache** after registration

**Important:** The `PluginManifestService` caches plugin data for 60 minutes. After the fix is deployed, you must either:
- Restart the backend application
- Run `php artisan cache:clear`
- Or wait for the cache to expire (60 minutes)

**Frontend Caching:** The frontend also has multiple caching layers:
1. **API Service Cache**: 5-minute response cache in `frontend/src/services/api.ts`
2. **Pinia Store Cache**: `loaded` flag prevents re-fetching

**Frontend Fix:** Changed `HomeView.vue` to use `refreshPlugins()` instead of `fetchPlugins()` on mount, which bypasses both caches and forces a fresh API call.

**File Modified:** `backend/app/Core/Providers/PluginServiceProvider.php`

```php
protected function ensurePluginsRegistered(): void
{
    // Skip if database not ready (e.g., during migrations)
    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('installed_plugins')) {
            return;
        }
    } catch (\Exception $e) {
        return;
    }

    foreach ($this->sortedPlugins as $plugin) {
        $slug = strtolower($plugin['id']);
        $existing = \App\Core\Models\InstalledPlugin::where('slug', $slug)->first();

        if (!$existing) {
            $manifest = $this->buildManifestFromDiscovery($plugin);
            \App\Core\Models\InstalledPlugin::createFromManifest($slug, $manifest);
        } else {
            $manifest = $this->buildManifestFromDiscovery($plugin);
            $existing->syncFromManifest($manifest);
        }
    }
}
```

#### Data Flow After Fix

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     APPLICATION BOOTSTRAP                                    │
│  PluginServiceProvider::boot()                                               │
│       ↓                                                                      │
│  ensurePluginsRegistered() → Syncs filesystem plugins to DB                 │
│       ↓                                                                      │
│  installed_plugins TABLE now has records for all discovered plugins         │
└─────────────────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                     FRONTEND API REQUEST                                     │
│  GET /api/v1/plugins/enabled                                                 │
│       ↓                                                                      │
│  PluginManifestService::getEnabledPluginsForFrontend()                      │
│       ↓                                                                      │
│  InstalledPlugin::where('enabled', true)->get()                             │
│       ↓                                                                      │
│  Returns: [hospital, combat, crimes, ...] → Links display correctly!        │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Why This Fix is Better Than Previous Approaches

1. **Single source of truth**: Plugin discovery happens once during bootstrap
2. **Performance**: No duplicate filesystem scans (reuses `$this->sortedPlugins`)
3. **Self-healing**: New plugins are automatically registered on next page load
4. **Consistent**: Both admin panel and frontend API see the same data
5. **Non-breaking**: Existing functionality remains unchanged
6. **Graceful**: Handles missing database table during migrations

---

---

## Root Cause Analysis

### Dual Plugin Model Architecture Issue

The system has **TWO separate plugin tracking mechanisms** that are not synchronized:

#### 1. `InstalledPlugin` Model (`App\Core\Models\InstalledPlugin`)

**Database Table:** `installed_plugins`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `name` | string | Plugin display name |
| `slug` | string | Unique identifier (lowercase) |
| `version` | string | Plugin version |
| `type` | string | 'plugin' or 'theme' |
| `description` | text | Plugin description |
| `dependencies` | json | Plugin dependencies |
| `config` | json | Plugin configuration |
| `enabled` | boolean | Enabled state |
| `installed_at` | timestamp | Installation date |

**Used by:** `PluginManagerService` for admin panel operations

#### 2. `Plugin` Model (`App\Core\Models\Plugin`)

**Database Table:** `plugins`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `name` | string | Plugin name |
| `display_name` | string | Display name |
| `description` | text | Description |
| `icon` | string | Icon identifier |
| `route_name` | string | Route name |
| `enabled` | boolean | Enabled state |
| `order` | integer | Menu order |
| `settings` | json | Settings |
| `required_level` | integer | Required level |
| `navigation_config` | json | Navigation config |

**Used by:** `PluginService` for frontend navigation

### The Disconnect

```
┌─────────────────────────────────────────────────────────────────────┐
│                         ADMIN PANEL                                  │
│  PluginController → PluginManagerService → InstalledPlugin Model    │
│                                              ↓                       │
│                                     installed_plugins TABLE          │
│                                     (EMPTY - No records)             │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                          FRONTEND                                    │
│  PluginService → Plugin Model → plugins TABLE                       │
│                                   ↓                                  │
│                            plugins TABLE                             │
│                            (HAS records)                             │
└─────────────────────────────────────────────────────────────────────┘
```

### Code Evidence

#### `PluginManagerService::getAllPlugins()` (Line ~38-75)

```php
public function getAllPlugins(): array
{
    $installed = InstalledPlugin::plugins()->get()->keyBy('slug');
    // ...
    foreach ($directories as $dir) {
        $slug = strtolower(basename($dir));
        $pluginJson = $this->loadPluginJson($dir);

        if ($pluginJson) {
            $available[] = [
                // ...
                'installed' => $installed->has($slug),  // FALSE if no DB record
                'enabled' => $installed->has($slug) ? $installed[$slug]->enabled : false,
                // ...
            ];
        }
    }
}
```

#### `PluginManagerService::enablePlugin()` (Line ~270-285)

```php
public function enablePlugin(string $slug): array
{
    $module = InstalledPlugin::where('slug', $slug)->first();

    if (!$module) {
        return ['success' => false, 'message' => 'Module not installed.'];
    }
    // ...
}
```

#### `PluginManagerService::uninstallPlugin()` (Line ~195-200)

```php
public function uninstallPlugin(string $slug): array
{
    $module = InstalledPlugin::where('slug', $slug)->first();

    if (!$module) {
        return ['success' => false, 'message' => 'Module not installed.'];
    }
    // ...
}
```

---

## Affected Components

### Primary Files

| File | Purpose | Bug Impact |
|------|---------|------------|
| `app/Core/Services/PluginManagerService.php` | Admin plugin management | Core of the bug |
| `app/Core/Models/InstalledPlugin.php` | Installed plugin model | Used for tracking |
| `app/Core/Http/Controllers/PluginController.php` | Admin plugin endpoints | Returns incorrect data |

### Secondary Files

| File | Purpose | Notes |
|------|---------|-------|
| `app/Core/Services/PluginService.php` | Frontend plugin service | Uses different model |
| `app/Core/Models/Plugin.php` | Plugin model for frontend | Works correctly |
| `app/Core/Services/PluginRegistry.php` | Static plugin registry | Simple registry, not DB-backed |

### API Routes

```
GET    /api/v1/admin/plugins          → PluginController@index
POST   /api/v1/admin/plugins/upload   → PluginController@upload
POST   /api/v1/admin/plugins/{slug}/install → PluginController@install
DELETE /api/v1/admin/plugins/{slug}   → PluginController@uninstall
PUT    /api/v1/admin/plugins/{slug}/enable → PluginController@enable
PUT    /api/v1/admin/plugins/{slug}/disable → PluginController@disable
```

---

## Proposed Solutions

### Option A: Migrate Existing Plugins to `installed_plugins` Table

**Description:** Create a seeder or migration that registers all existing plugins in `app/Plugins/` into the `installed_plugins` table.

**Pros:**
- Quick fix
- Minimal code changes
- Aligns with intended architecture

**Cons:**
- May have version tracking issues
- Doesn't address the dual-model confusion

**Implementation:**
```php
// Create a command: php artisan hub:sync-plugins
public function handle()
{
    $pluginPath = app_path('Plugins');
    $directories = File::directories($pluginPath);
    
    foreach ($directories as $dir) {
        $slug = strtolower(basename($dir));
        $pluginJson = json_decode(File::get($dir . '/plugin.json'), true);
        
        InstalledPlugin::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $pluginJson['name'] ?? $slug,
                'version' => $pluginJson['version'] ?? '1.0.0',
                'type' => 'plugin',
                'enabled' => $pluginJson['enabled'] ?? true,
                'installed_at' => now(),
            ]
        );
    }
}
```

### Option B: Consolidate to Single Plugin Model

**Description:** Merge `Plugin` and `InstalledPlugin` models into a single unified model with all necessary fields.

**Pros:**
- Eliminates confusion
- Single source of truth
- Cleaner architecture

**Cons:**
- Requires migration
- May break existing code
- More extensive changes

### Option C: Auto-Install Plugins on Discovery

**Description:** Modify `PluginManagerService::getAllPlugins()` to auto-create `InstalledPlugin` records when a plugin is discovered in `app/Plugins/` without a database record.

**Pros:**
- Self-healing
- No manual intervention
- Works with new plugins

**Cons:**
- May hide configuration issues
- Could create duplicate records

**Implementation:**
```php
public function getAllPlugins(): array
{
    $installed = InstalledPlugin::plugins()->get()->keyBy('slug');
    $available = [];

    foreach ($directories as $dir) {
        $slug = strtolower(basename($dir));
        $pluginJson = $this->loadPluginJson($dir);

        if ($pluginJson) {
            // Auto-install if not tracked
            if (!$installed->has($slug)) {
                $module = InstalledPlugin::create([
                    'name' => $pluginJson['name'],
                    'slug' => $slug,
                    'version' => $pluginJson['version'],
                    'type' => 'plugin',
                    'enabled' => $pluginJson['enabled'] ?? true,
                    'installed_at' => now(),
                ]);
                $installed[$slug] = $module;
            }
            // ... rest of logic
        }
    }
}
```

---

## Recommended Fix

**Short-term:** Implement **Option C** (Auto-Install on Discovery) to provide immediate relief.

**Long-term:** Implement **Option B** (Consolidate Models) as part of a larger refactoring effort.

---

## Investigation Steps

To diagnose this issue on a fresh installation:

### Step 1: Check `installed_plugins` Table

```sql
SELECT * FROM installed_plugins;
```

Expected: Records for all plugins in `app/Plugins/`
Actual: Likely empty or missing plugins

### Step 2: Check `plugins` Table

```sql
SELECT * FROM plugins;
```

Expected: Records for plugins used by frontend
Actual: Likely has records

### Step 3: Check Plugin Directory

```bash
ls -la backend/app/Plugins/
```

Compare directory names with database records.

### Step 4: Check Logs

```bash
tail -f backend/storage/logs/laravel.log
```

Look for plugin-related errors during bootstrap.

### Step 5: Debug PluginManagerService

Add logging to `getAllPlugins()`:

```php
\Log::debug('getAllPlugins: InstalledPlugin DB entries', $installed->toArray());
\Log::debug('getAllPlugins: Found plugin directories', $directories);
```

---

## Related Documentation

- [Plugin Registry Design](./PLUGIN_REGISTRY.md)
- [Plugin Bundle System](./PLUGIN_BUNDLES.md)
- [Plugin Contract](../PLUGIN_CONTRACT.md)
- [Plugin Hooks System](./PLUGIN_HOOKS.md)
- [HUB CLI Reference](./HUB_CLI.md)

---

## Changelog

| Date | Author | Description |
|------|--------|-------------|
| 2026-02-24 | System | Initial bug tracking document created |

---

## Notes

- The plugin system uses filesystem discovery (`app/Plugins/*/plugin.json`)
- The admin panel expects database records in `installed_plugins`
- The frontend uses a different table (`plugins`)
- This architectural discrepancy is the root cause of all reported bugs
- Plugins are functional on the frontend because `PluginService` uses the `Plugin` model which has records
