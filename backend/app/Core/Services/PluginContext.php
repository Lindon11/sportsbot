<?php

namespace App\Core\Services;

use App\Core\Exceptions\PluginPermissionException;

/**
 * Plugin permission enforcement layer.
 *
 * Plugins declare their active context before calling protected APIs (e.g. WalletService).
 * The protected API calls assertPermission() to confirm the plugin declared the required
 * permission in its plugin.json before being allowed to proceed.
 *
 * Usage in a plugin:
 *
 *   PluginContext::run('my-plugin', function () use ($user, $amount) {
 *       Economy::credit($user, $amount, 'reward', 'my-plugin');
 *   });
 *
 * If no plugin context is active (core code), assertPermission() always passes.
 * This ensures backward compatibility — existing code that does not use PluginContext
 * continues to work without modification.
 */
class PluginContext
{
    /**
     * Execution stack — each entry is a plugin slug.
     * Supports nested calls: the innermost (most recently entered) context is authoritative.
     */
    protected static array $stack = [];

    /** Runtime permission cache, keyed by plugin slug. */
    protected static array $permissionsCache = [];

    /**
     * Push a plugin context onto the stack.
     */
    public static function enter(string $pluginSlug): void
    {
        // Pre-load permissions so assertPermission() never does I/O mid-call
        static::loadPermissions($pluginSlug);
        static::$stack[] = $pluginSlug;
    }

    /**
     * Pop the most recently entered plugin context off the stack.
     */
    public static function exit(): void
    {
        array_pop(static::$stack);
    }

    /**
     * Run a callable within a plugin context, restoring the previous context on completion.
     * Safe for nested / re-entrant calls.
     */
    public static function run(string $pluginSlug, callable $fn): mixed
    {
        static::enter($pluginSlug);
        try {
            return $fn();
        } finally {
            static::exit();
        }
    }

    /**
     * Assert that the currently active plugin has declared the given permission.
     * If no plugin context is active (core code), this always passes.
     *
     * @throws PluginPermissionException
     */
    public static function assertPermission(string $permission): void
    {
        $slug = static::active();

        if ($slug === null) {
            return; // Core context — always permitted
        }

        $permissions = static::$permissionsCache[$slug] ?? [];
        if (!in_array($permission, $permissions, true)) {
            throw new PluginPermissionException($slug, $permission);
        }
    }

    /**
     * Get the currently active (innermost) plugin slug, or null if in core context.
     */
    public static function active(): ?string
    {
        return end(static::$stack) ?: null;
    }

    /**
     * Alias for active() — kept for backward compatibility.
     */
    public static function getActiveSlug(): ?string
    {
        return static::active();
    }

    /**
     * Check if the currently active plugin has the given permission (non-throwing).
     */
    public static function hasPermission(string $permission): bool
    {
        $slug = static::active();

        if ($slug === null) {
            return true;
        }

        return in_array($permission, static::$permissionsCache[$slug] ?? [], true);
    }

    /**
     * Load and cache the declared permissions for a plugin from its plugin.json.
     */
    protected static function loadPermissions(string $slug): array
    {
        if (isset(static::$permissionsCache[$slug])) {
            return static::$permissionsCache[$slug];
        }

        // Resolve the plugin directory (title-case convention: "my-plugin" → "MyPlugin")
        $pascal = str_replace(['-', '_'], '', ucwords($slug, '-_'));
        $jsonPath = app_path("Plugins/{$pascal}/plugin.json");

        if (!file_exists($jsonPath)) {
            // Fallback: try the slug as-is
            $jsonPath = app_path("Plugins/{$slug}/plugin.json");
        }

        $permissions = [];
        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);
            $permissions = $data['permissions'] ?? [];
        }

        static::$permissionsCache[$slug] = $permissions;

        return $permissions;
    }
}
