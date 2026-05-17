<?php

namespace App\Plugins\TestPlugin;

use App\Plugins\Plugin;
use App\Core\Contracts\PluginInterface;
use Illuminate\Support\Facades\Route;

/**
 * TestPlugin Plugin
 *
 * A new plugin created with hub:make
 */
class TestPluginPlugin extends Plugin implements PluginInterface
{
    /**
     * Plugin constructor.
     */
    public function __construct()
    {
        parent::__construct(app_path('Plugins/testplugin'));
    }

    // ==========================================
    // PluginInterface Implementation
    // ==========================================

    /**
     * Register the plugin's services.
     * Called during Laravel's "register" phase.
     */
    public function register(): void
    {
        // Register any services or bindings
        // $this->app->singleton('testplugin.service', function ($app) {
        //     return new Services\TestPluginService();
        // });
    }

    /**
     * Boot the plugin's functionality.
     * Called during Laravel's "boot" phase.
     */
    public function boot(): void
    {
        // Register hooks from hooks.php
        $this->registerHooks();
    }

    // ==========================================
    // PluginLifecycleInterface Implementation
    // ==========================================

    /**
     * Called when plugin is first installed.
     */
    public function install(): void
    {
        $this->log('info', 'TestPlugin plugin installed');
    }

    /**
     * Called when plugin is enabled.
     */
    public function enable(): void
    {
        $this->log('info', 'TestPlugin plugin enabled');
    }

    /**
     * Called when plugin is disabled.
     */
    public function disable(): void
    {
        $this->log('info', 'TestPlugin plugin disabled');
    }

    /**
     * Called when plugin is uninstalled.
     */
    public function uninstall(): void
    {
        $this->log('info', 'TestPlugin plugin uninstalled');
    }

    /**
     * Called when upgrading versions.
     */
    public function upgrade(string $fromVersion, string $toVersion): void
    {
        $this->log('info', "TestPlugin upgraded from {$fromVersion} to {$toVersion}");
    }
}