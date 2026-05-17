<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plugin Metadata Model
 *
 * Stores plugin-specific data on any model in a polymorphic way.
 * This allows plugins to add custom data to users, players, etc.
 * without modifying core database schemas.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $plugin_id
 * @property string $key
 * @property mixed $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PluginMetadata extends Model
{
    protected $table = 'plugin_metadata';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'plugin_id',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    /**
     * Get the owning model (User, Player, etc.).
     */
    public function owner()
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter by plugin.
     */
    public function scopeForPlugin($query, string $pluginId)
    {
        return $query->where('plugin_id', $pluginId);
    }

    /**
     * Scope to filter by key.
     */
    public function scopeWithKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Scope to filter by owner.
     */
    public function scopeForOwner($query, Model $owner)
    {
        return $query->where('owner_type', get_class($owner))
            ->where('owner_id', $owner->getKey());
    }

    /**
     * Get a metadata value for a specific owner/plugin/key.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @param mixed $default Default value if not found.
     * @return mixed
     */
    public static function getValue(Model $owner, string $pluginId, string $key, mixed $default = null): mixed
    {
        $record = static::where('owner_type', get_class($owner))
            ->where('owner_id', $owner->getKey())
            ->where('plugin_id', $pluginId)
            ->where('key', $key)
            ->first();

        return $record ? $record->value : $default;
    }

    /**
     * Set a metadata value for a specific owner/plugin/key.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @param mixed $value The value to store.
     * @return static
     */
    public static function setValue(Model $owner, string $pluginId, string $key, mixed $value): static
    {
        return static::updateOrCreate(
            [
                'owner_type' => get_class($owner),
                'owner_id' => $owner->getKey(),
                'plugin_id' => $pluginId,
                'key' => $key,
            ],
            ['value' => $value]
        );
    }

    /**
     * Delete a metadata value.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @return bool
     */
    public static function deleteValue(Model $owner, string $pluginId, string $key): bool
    {
        return static::where('owner_type', get_class($owner))
            ->where('owner_id', $owner->getKey())
            ->where('plugin_id', $pluginId)
            ->where('key', $key)
            ->delete() > 0;
    }

    /**
     * Get all metadata for a specific owner and plugin.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @return array Key-value pairs of metadata.
     */
    public static function getAllForPlugin(Model $owner, string $pluginId): array
    {
        return static::where('owner_type', get_class($owner))
            ->where('owner_id', $owner->getKey())
            ->where('plugin_id', $pluginId)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Delete all metadata for a specific owner and plugin.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @return int Number of records deleted.
     */
    public static function deleteAllForPlugin(Model $owner, string $pluginId): int
    {
        return static::where('owner_type', get_class($owner))
            ->where('owner_id', $owner->getKey())
            ->where('plugin_id', $pluginId)
            ->delete();
    }

    /**
     * Increment a numeric metadata value.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @param int $amount Amount to increment by.
     * @return int The new value.
     */
    public static function incrementValue(Model $owner, string $pluginId, string $key, int $amount = 1): int
    {
        $current = (int) static::getValue($owner, $pluginId, $key, 0);
        $new = $current + $amount;
        static::setValue($owner, $pluginId, $key, $new);
        return $new;
    }

    /**
     * Decrement a numeric metadata value.
     *
     * @param Model $owner The model that owns this metadata.
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @param int $amount Amount to decrement by.
     * @return int The new value.
     */
    public static function decrementValue(Model $owner, string $pluginId, string $key, int $amount = 1): int
    {
        return static::incrementValue($owner, $pluginId, $key, -$amount);
    }
}
