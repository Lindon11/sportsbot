<?php

namespace App\Core\Services;

use App\Core\Models\InstalledPlugin;
use App\Core\Models\User;
use Illuminate\Support\Collection;

class PluginService
{
    /**
     * Get all enabled plugins.
     */
    public function getEnabledPlugins(): Collection
    {
        return InstalledPlugin::plugins()
            ->where('enabled', true)
            ->orderBy('order')
            ->get();
    }

    /**
     * Get plugins for a player (all enabled plugins for now).
     */
    public function getPluginsForPlayer(User $player): Collection
    {
        return InstalledPlugin::plugins()
            ->where('enabled', true)
            ->orderBy('order')
            ->get();
    }

    /**
     * Get navigation items for dashboard.
     */
    public function getNavigationItems(User $player): Collection
    {
        return $this->getPluginsForPlayer($player)
            ->filter(fn($plugin) => $plugin->hasNavigation())
            ->map(function ($plugin) {
                $config = $plugin->getNavigationConfig();

                return [
                    'name' => $plugin->name,
                    'display_name' => $plugin->name,
                    'description' => $plugin->description,
                    'icon' => $plugin->icon,
                    'route_name' => $plugin->route_name,
                    'route_url' => $plugin->route_name ? route($plugin->route_name) : null,
                    'color' => $config['color'] ?? 'bg-gray-600',
                    'order' => $config['order'] ?? $plugin->order ?? 100,
                    'section' => $config['section'] ?? 'main',
                    'icon_svg' => null,
                ];
            })
            ->groupBy('section');
    }

    /**
     * Check if a plugin is enabled by name or slug.
     */
    public function isPluginEnabled(string $pluginName): bool
    {
        $plugin = InstalledPlugin::plugins()
            ->where('enabled', true)
            ->where(function ($query) use ($pluginName) {
                $query->where('name', $pluginName)
                    ->orWhere('slug', $pluginName);
            })
            ->first();

        return $plugin !== null;
    }

    /**
     * Check if player can access a plugin.
     */
    public function canPlayerAccessPlugin(User $player, string $pluginName): bool
    {
        $plugin = InstalledPlugin::plugins()
            ->where('enabled', true)
            ->where(function ($query) use ($pluginName) {
                $query->where('name', $pluginName)
                    ->orWhere('slug', $pluginName);
            })
            ->first();

        return $plugin !== null;
    }

    /**
     * Toggle plugin enabled state.
     */
    public function togglePlugin(string $pluginName): bool
    {
        $plugin = InstalledPlugin::plugins()
            ->where(function ($query) use ($pluginName) {
                $query->where('name', $pluginName)
                    ->orWhere('slug', $pluginName);
            })
            ->first();

        if ($plugin) {
            $plugin->enabled = !$plugin->enabled;
            $plugin->save();
            return $plugin->enabled;
        }

        return false;
    }

    /**
     * Update plugin settings.
     */
    public function updatePluginSettings(string $pluginName, array $settings): bool
    {
        $plugin = InstalledPlugin::plugins()
            ->where(function ($query) use ($pluginName) {
                $query->where('name', $pluginName)
                    ->orWhere('slug', $pluginName);
            })
            ->first();

        if ($plugin) {
            $plugin->config = array_merge($plugin->config ?? [], $settings);
            $plugin->save();
            return true;
        }

        return false;
    }

    /**
     * Reorder plugins.
     */
    public function reorderPlugins(array $order): void
    {
        foreach ($order as $pluginSlug => $position) {
            InstalledPlugin::where('slug', $pluginSlug)->update(['order' => $position]);
        }
    }

    /**
     * Get plugin by slug.
     */
    public function getPlugin(string $slug): ?InstalledPlugin
    {
        return InstalledPlugin::where('slug', $slug)->first();
    }

    /**
     * Get all plugins.
     */
    public function getAllPlugins(): Collection
    {
        return InstalledPlugin::plugins()->orderBy('order')->get();
    }

    // Backwards compatibility aliases
    public function getEnabledModules(): Collection
    {
        return $this->getEnabledPlugins();
    }

    public function isModuleEnabled(string $name): bool
    {
        return $this->isPluginEnabled($name);
    }

    public function canPlayerAccessModule(User $player, string $name): bool
    {
        return $this->canPlayerAccessPlugin($player, $name);
    }
}
