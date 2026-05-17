<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;

class InstalledPlugin extends Model
{
    protected $table = 'installed_plugins';

    protected $fillable = [
        'name',
        'slug',
        'version',
        'type',
        'description',
        'author',
        'dependencies',
        'config',
        'enabled',
        'installed_at',
        // Plugin Contract fields
        'license_required',
        'icon',
        'color',
        'route_name',
        'order',
        'permissions',
        'hooks',
        'frontend_slots',
        'frontend_routes',
        'has_web_routes',
        'has_api_routes',
        'has_admin_routes',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'config' => 'array',
        'enabled' => 'boolean',
        'installed_at' => 'datetime',
        'license_required' => 'boolean',
        'order' => 'integer',
        'permissions' => 'array',
        'hooks' => 'array',
        'frontend_slots' => 'array',
        'frontend_routes' => 'array',
        'has_web_routes' => 'boolean',
        'has_api_routes' => 'boolean',
        'has_admin_routes' => 'boolean',
    ];

    /**
     * Scope for enabled plugins.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope for plugins only (not themes).
     */
    public function scopePlugins($query)
    {
        return $query->where('type', 'plugin');
    }

    /**
     * Scope for themes only.
     */
    public function scopeThemes($query)
    {
        return $query->where('type', 'theme');
    }

    /**
     * Check if plugin is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable the plugin.
     */
    public function enable(): void
    {
        $this->update(['enabled' => true]);
    }

    /**
     * Disable the plugin.
     */
    public function disable(): void
    {
        $this->update(['enabled' => false]);
    }

    /**
     * Get menu items for navigation.
     */
    public function getMenuItems(): array
    {
        $menuConfig = $this->config['menu'] ?? [];

        if (empty($menuConfig['enabled'] ?? false)) {
            return [];
        }

        return [
            'section' => $menuConfig['section'] ?? 'main',
            'order' => $menuConfig['order'] ?? $this->order ?? 100,
            'parent' => $menuConfig['parent'] ?? null,
            'icon' => $this->icon,
            'color' => $this->color,
            'route' => $this->route_name,
            'title' => $this->name,
        ];
    }

    /**
     * Get navigation configuration.
     */
    public function getNavigationConfig(): array
    {
        $config = $this->config ?? [];
        $menuSettings = $config['menu'] ?? [];

        if ($menuSettings) {
            return [
                'section' => $menuSettings['section'] ?? 'main',
                'order' => $menuSettings['order'] ?? $this->order ?? 100,
                'color' => $this->color ?? 'bg-gray-600',
                'icon' => $this->icon,
                'enabled' => $menuSettings['enabled'] ?? true,
            ];
        }

        return [];
    }

    /**
     * Check if plugin has navigation enabled.
     */
    public function hasNavigation(): bool
    {
        $config = $this->config ?? [];
        return !empty($config['menu']['enabled']);
    }

    /**
     * Get the route URL.
     */
    public function getRoute(): ?string
    {
        return $this->route_name ? route($this->route_name) : null;
    }

    /**
     * Check if plugin requires a license.
     */
    public function requiresLicense(): bool
    {
        return $this->license_required;
    }

    /**
     * Get plugin dependencies.
     */
    public function getDependencies(): array
    {
        return $this->dependencies ?? [];
    }

    /**
     * Sync data from plugin.json manifest.
     */
    public function syncFromManifest(array $manifest): void
    {
        $this->fill([
            'name' => $manifest['name'] ?? $this->name,
            'version' => $manifest['version'] ?? $this->version,
            'description' => $manifest['description'] ?? $this->description,
            'author' => $manifest['author'] ?? $this->author,
            'license_required' => $manifest['license_required'] ?? false,
            'dependencies' => $manifest['requires']['plugins'] ?? [],
            'config' => $manifest['settings'] ?? [],
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
        ]);

        $this->save();
    }

    /**
     * Create from plugin.json manifest.
     */
    public static function createFromManifest(string $slug, array $manifest): self
    {
        return self::create([
            'name' => $manifest['name'] ?? $slug,
            'slug' => $slug,
            'version' => $manifest['version'] ?? '1.0.0',
            'type' => 'plugin',
            'description' => $manifest['description'] ?? '',
            'author' => $manifest['author'] ?? 'Unknown',
            'license_required' => $manifest['license_required'] ?? false,
            'dependencies' => $manifest['requires']['plugins'] ?? [],
            'config' => $manifest['settings'] ?? [],
            'enabled' => $manifest['enabled'] ?? true,
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
        ]);
    }
}
