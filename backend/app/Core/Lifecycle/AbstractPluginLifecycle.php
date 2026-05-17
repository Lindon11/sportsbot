<?php

namespace App\Core\Lifecycle;

use App\Core\Contracts\PluginLifecycleInterface;

/**
 * Base class for plugin lifecycle handlers.
 * All methods are no-ops by default — plugins override only what they need.
 *
 * Usage:
 *   class MyPlugin extends AbstractPluginLifecycle
 *   {
 *       public function install(): void
 *       {
 *           // seed default config, permissions, etc.
 *       }
 *   }
 */
abstract class AbstractPluginLifecycle implements PluginLifecycleInterface
{
    public function install(): void {}

    public function enable(): void {}

    public function disable(): void {}

    public function uninstall(): void {}

    public function upgrade(string $fromVersion, string $toVersion): void {}
}
