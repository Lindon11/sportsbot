<?php

namespace App\Core\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Plugin Broadcast Event
 *
 * A generic event class for broadcasting plugin-specific messages
 * over WebSocket. Use the broadcastToPlugin() helper for convenience.
 *
 * Usage:
 *   broadcastToPlugin('rpg', 'gold_updated', ['gold' => 100]);
 *   broadcastToPlugin('crimes', 'crime_completed', ['crime_id' => 1]);
 */
class PluginBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The plugin identifier.
     */
    public string $pluginId;

    /**
     * The event name.
     */
    public string $eventName;

    /**
     * The data to broadcast.
     */
    public array $data;

    /**
     * The channel type (public, private, presence).
     */
    public string $channelType;

    /**
     * Optional channel suffix for more specific targeting.
     */
    public ?string $channelSuffix;

    /**
     * Create a new event instance.
     *
     * @param string $pluginId The plugin identifier (slug)
     * @param string $eventName The event name
     * @param array $data The data to broadcast
     * @param string $channelType Channel type: 'public', 'private', or 'presence'
     * @param string|null $channelSuffix Optional suffix for the channel name
     */
    public function __construct(
        string $pluginId,
        string $eventName,
        array $data = [],
        string $channelType = 'public',
        ?string $channelSuffix = null
    ) {
        $this->pluginId = $pluginId;
        $this->eventName = $eventName;
        $this->data = $data;
        $this->channelType = $channelType;
        $this->channelSuffix = $channelSuffix;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channelName = $this->getChannelName();

        return match ($this->channelType) {
            'private' => [new PrivateChannel($channelName)],
            'presence' => [new PresenceChannel($channelName)],
            default => [new Channel($channelName)],
        };
    }

    /**
     * Get the event name for broadcast.
     */
    public function broadcastAs(): string
    {
        return "{$this->pluginId}:{$this->eventName}";
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return array_merge($this->data, [
            'app_prefix' => $this->pluginId,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the channel name.
     */
    protected function getChannelName(): string
    {
        $base = "plugin-{$this->pluginId}";

        if ($this->channelSuffix) {
            return "{$base}.{$this->channelSuffix}";
        }

        return $base;
    }

    /**
     * Create a private channel version.
     */
    public static function private(
        string $pluginId,
        string $eventName,
        array $data = [],
        ?string $channelSuffix = null
    ): self {
        return new self($pluginId, $eventName, $data, 'private', $channelSuffix);
    }

    /**
     * Create a presence channel version.
     */
    public static function presence(
        string $pluginId,
        string $eventName,
        array $data = [],
        ?string $channelSuffix = null
    ): self {
        return new self($pluginId, $eventName, $data, 'presence', $channelSuffix);
    }

    /**
     * Create a user-specific broadcast.
     */
    public static function forUser(
        int $userId,
        string $pluginId,
        string $eventName,
        array $data = []
    ): self {
        return new self($pluginId, $eventName, $data, 'private', "user.{$userId}");
    }
}
