<?php

namespace App\Core\Admin;

use App\Core\Services\GameHooks;
use Illuminate\Support\Facades\Gate;

class AdminSidebarService
{
    /**
     * Get the final sidebar items, after applying hooks and permission checks.
     */
    public static function getSidebarItems($user): array
    {
        $coreSections = CoreAdminMenu::items();
        $coreIds = array_column($coreSections, 'id');

        // Apply hook — plugins may append their sections here
        $sections = GameHooks::apply('admin.sidebar', $coreSections);

        // Partition into core sections (trusted) and plugin sections (validated)
        $pluginSections = array_values(array_filter(
            $sections,
            fn($s) => !in_array($s['id'] ?? null, $coreIds, true)
        ));
        $corePart = array_values(array_filter(
            $sections,
            fn($s) => in_array($s['id'] ?? null, $coreIds, true)
        ));

        $validatedPluginSections = SidebarItemValidator::validate($pluginSections, $coreIds);
        $sections = array_merge($corePart, $validatedPluginSections);

        // Filter out sections and children from disabled plugins
        $enabledPlugins = [];
        if (app()->bound('plugins')) {
            foreach (app('plugins') as $plugin) {
                if (!empty($plugin['enabled'])) {
                    $enabledPlugins[] = strtolower($plugin['id'] ?? $plugin['name'] ?? '');
                }
            }
        }

        $sections = array_filter($sections, function ($section) use ($enabledPlugins) {
            // If section is from a plugin, it should have a plugin_id or similar
            if (isset($section['plugin']) && !in_array(strtolower($section['plugin']), $enabledPlugins)) {
                return false;
            }
            // Optionally, filter children
            if (isset($section['children']) && is_array($section['children'])) {
                $section['children'] = array_filter($section['children'], function ($child) use ($enabledPlugins) {
                    if (isset($child['plugin']) && !in_array(strtolower($child['plugin']), $enabledPlugins)) {
                        return false;
                    }
                    return true;
                });
            }
            return true;
        });

        // Sort sections by 'order'
        usort($sections, function ($a, $b) {
            return ($a['order'] ?? 100) <=> ($b['order'] ?? 100);
        });
        return array_values($sections);
    }
}
