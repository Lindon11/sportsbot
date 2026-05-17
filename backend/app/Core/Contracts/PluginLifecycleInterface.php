<?php

namespace App\Core\Contracts;

interface PluginLifecycleInterface
{
    /**
     * Called once when a plugin is first installed (after migrations run).
     * Use to seed data, create default config, register permissions, etc.
     */
    public function install(): void;

    /**
     * Called when a plugin is toggled on.
     * Use to register any runtime state (e.g. cache warm-up).
     */
    public function enable(): void;

    /**
     * Called when a plugin is toggled off.
     * Use to release runtime state (e.g. cache flush for plugin data).
     */
    public function disable(): void;

    /**
     * Called when a plugin is fully removed (before migrations are rolled back).
     * Use to clean up any data that migrations won't handle.
     */
    public function uninstall(): void;

    /**
     * Called when upgrading from $fromVersion to $toVersion.
     * Use for data migrations that cannot be expressed as schema migrations.
     */
    public function upgrade(string $fromVersion, string $toVersion): void;
}
