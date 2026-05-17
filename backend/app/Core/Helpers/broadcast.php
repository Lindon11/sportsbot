<?php

/**
 * Plugin Broadcast Helpers
 *
 * Helper functions for broadcasting plugin-specific WebSocket events.
 */

use App\Core\Events\PluginBroadcastEvent;
use Illuminate\Support\Facades\Broadcast;

if (!function_exists('broadcastToPlugin')) {
    /**
     * Broadcast an event to a plugin's channel.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string $event The event name
     * @param array $data The data to broadcast
     * @param string $channelType Channel type: 'public', 'private', or 'presence'
     * @return \Illuminate\Broadcasting\PendingBroadcast|null
     */
    function broadcastToPlugin(
        string $pluginId,
        string $event,
        array $data = [],
        string $channelType = 'public'
    ): ?\Illuminate\Broadcasting\PendingBroadcast {
        return broadcast(new PluginBroadcastEvent($pluginId, $event, $data, $channelType));
    }
}

if (!function_exists('broadcastToPluginPrivate')) {
    /**
     * Broadcast an event to a plugin's private channel.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string $event The event name
     * @param array $data The data to broadcast
     * @return \Illuminate\Broadcasting\PendingBroadcast|null
     */
    function broadcastToPluginPrivate(
        string $pluginId,
        string $event,
        array $data = []
    ): ?\Illuminate\Broadcasting\PendingBroadcast {
        return broadcast(PluginBroadcastEvent::private($pluginId, $event, $data));
    }
}

if (!function_exists('broadcastToPluginPresence')) {
    /**
     * Broadcast an event to a plugin's presence channel.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string $event The event name
     * @param array $data The data to broadcast
     * @return \Illuminate\Broadcasting\PendingBroadcast|null
     */
    function broadcastToPluginPresence(
        string $pluginId,
        string $event,
        array $data = []
    ): ?\Illuminate\Broadcasting\PendingBroadcast {
        return broadcast(PluginBroadcastEvent::presence($pluginId, $event, $data));
    }
}

if (!function_exists('broadcastToPluginUser')) {
    /**
     * Broadcast an event to a specific user's plugin channel.
     *
     * @param int $userId The user ID
     * @param string $pluginId The plugin identifier (slug)
     * @param string $event The event name
     * @param array $data The data to broadcast
     * @return \Illuminate\Broadcasting\PendingBroadcast|null
     */
    function broadcastToPluginUser(
        int $userId,
        string $pluginId,
        string $event,
        array $data = []
    ): ?\Illuminate\Broadcasting\PendingBroadcast {
        return broadcast(PluginBroadcastEvent::forUser($userId, $pluginId, $event, $data));
    }
}

if (!function_exists('getPluginChannelName')) {
    /**
     * Get the channel name for a plugin.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string|null $suffix Optional suffix (e.g., 'user.1')
     * @return string The channel name
     */
    function getPluginChannelName(string $pluginId, ?string $suffix = null): string
    {
        $base = "plugin-{$pluginId}";

        if ($suffix) {
            return "{$base}.{$suffix}";
        }

        return $base;
    }
}

if (!function_exists('getPluginPrivateChannelName')) {
    /**
     * Get the private channel name for a plugin.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string|null $suffix Optional suffix
     * @return string The private channel name (with private- prefix)
     */
    function getPluginPrivateChannelName(string $pluginId, ?string $suffix = null): string
    {
        return 'private-' . getPluginChannelName($pluginId, $suffix);
    }
}

if (!function_exists('getPluginPresenceChannelName')) {
    /**
     * Get the presence channel name for a plugin.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string|null $suffix Optional suffix
     * @return string The presence channel name (with presence- prefix)
     */
    function getPluginPresenceChannelName(string $pluginId, ?string $suffix = null): string
    {
        return 'presence-' . getPluginChannelName($pluginId, $suffix);
    }
}
