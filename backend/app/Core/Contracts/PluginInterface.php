<?php

namespace App\Core\Contracts;

use Illuminate\Support\Facades\Route;

/**
 * Plugin Interface
 *
 * Contract that all plugins must implement.
 * Defines the lifecycle methods and configuration accessors
 * for a modular plugin system.
 */
interface PluginInterface
{
    /**
     * Get the plugin's unique identifier (slug).
     * Should match the directory name and plugin.json slug.
     */
    public function getId(): string;

    /**
     * Get the plugin's display name.
     */
    public function getName(): string;

    /**
     * Get the plugin's version string.
     */
    public function getVersion(): string;

    /**
     * Get the parsed plugin.json manifest.
     */
    public function getManifest(): array;

    /**
     * Get the plugin's filesystem path.
     */
    public function getPath(): string;

    /**
     * Get the plugin's namespace.
     */
    public function getNamespace(): string;

    /**
     * Register the plugin's services, routes, views, etc.
     * Called during the "register" phase of Laravel's lifecycle.
     * Should NOT use other services that aren't registered yet.
     */
    public function register(): void;

    /**
     * Boot the plugin's runtime functionality.
     * Called during the "boot" phase of Laravel's lifecycle.
     * Safe to use other services and register hooks here.
     */
    public function boot(): void;

    /**
     * Get route definitions for this plugin.
     * Returns an array of route configuration:
     * [
     *     'web' => ['middleware' => ['web', 'auth'], 'path' => 'routes/web.php'],
     *     'api' => ['middleware' => ['api', 'auth:sanctum'], 'path' => 'routes/api.php'],
     *     'admin' => ['middleware' => ['web', 'auth', 'admin'], 'path' => 'routes/admin.php'],
     * ]
     */
    public function getRoutes(): array;

    /**
     * Get middleware stack for this plugin.
     * These will be applied to all plugin routes.
     */
    public function getMiddleware(): array;

    /**
     * Get plugin dependencies.
     * Returns array of plugin slugs that must be enabled.
     * ['rpg-core' => '^1.0', 'economy' => '*']
     */
    public function getDependencies(): array;

    /**
     * Get the views namespace for this plugin.
     * Usage: view('plugin-id::view-name')
     */
    public function getViewNamespace(): string;

    /**
     * Get the migrations path for this plugin.
     */
    public function getMigrationsPath(): string;

    /**
     * Get frontend component slots this plugin provides.
     * Returns array of slot names and their Vue components:
     * [
     *     'dashboard-widget' => ['GoldWidget.vue', 'StatsWidget.vue'],
     *     'header-link' => ['RpgNav.vue'],
     * ]
     */
    public function getFrontendSlots(): array;

    /**
     * Check if this plugin requires a license.
     */
    public function requiresLicense(): bool;

    /**
     * Get the permission groups this plugin defines.
     * Returns array of permission definitions:
     * [
     *     'rpg.view' => 'View RPG content',
     *     'rpg.play' => 'Play RPG game',
     *     'rpg.admin' => 'Administer RPG settings',
     * ]
     */
    public function getPermissions(): array;

    /**
     * Get admin settings defined by this plugin.
     * Returns array of setting groups with their fields:
     * [
     *     'combat' => [
     *         'label' => 'Combat',
     *         'icon' => 'FireIcon',
     *         'order' => 10,
     *         'settings' => [
     *             'attack_cooldown' => [
     *                 'type' => 'number',
     *                 'label' => 'Attack Cooldown (seconds)',
     *                 'default' => 300,
     *                 'description' => 'Cooldown between attacks',
     *             ],
     *         ],
     *     ],
     * ]
     */
    public function getAdminSettings(): array;
}
