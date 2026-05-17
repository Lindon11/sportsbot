<?php

namespace App\Core\Traits;

use App\Core\Models\PluginMetadata;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * HasPluginMetadata Trait
 *
 * Add this trait to any model that needs to store plugin-specific metadata.
 * This allows plugins to store custom data without modifying core schemas.
 *
 * Usage:
 *   class User extends Model {
 *       use HasPluginMetadata;
 *   }
 *
 *   // In a plugin:
 *   $user->setPluginMeta('rpg', 'gold', 100);
 *   $user->getPluginMeta('rpg', 'gold', 0);
 *   $user->incrementPluginMeta('rpg', 'gold', 50);
 *
 *   // Get all metadata for a plugin:
 *   $rpgData = $user->getAllPluginMeta('rpg');
 */
trait HasPluginMetadata
{
    /**
     * Get all plugin metadata for this model.
     */
    public function pluginMetadata(): MorphMany
    {
        return $this->morphMany(PluginMetadata::class, 'owner');
    }

    /**
     * Get a plugin metadata value.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @param mixed $default Default value if not found.
     * @return mixed
     */
    public function getPluginMeta(string $pluginId, string $key, mixed $default = null): mixed
    {
        return PluginMetadata::getValue($this, $pluginId, $key, $default);
    }

    /**
     * Set a plugin metadata value.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @param mixed $value The value to store.
     * @return static
     */
    public function setPluginMeta(string $pluginId, string $key, mixed $value): static
    {
        PluginMetadata::setValue($this, $pluginId, $key, $value);
        return $this;
    }

    /**
     * Check if a plugin metadata key exists.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @return bool
     */
    public function hasPluginMeta(string $pluginId, string $key): bool
    {
        return $this->pluginMetadata()
            ->where('plugin_id', $pluginId)
            ->where('key', $key)
            ->exists();
    }

    /**
     * Delete a plugin metadata value.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @return bool
     */
    public function deletePluginMeta(string $pluginId, string $key): bool
    {
        return PluginMetadata::deleteValue($this, $pluginId, $key);
    }

    /**
     * Get all metadata for a specific plugin.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @return array Key-value pairs of metadata.
     */
    public function getAllPluginMeta(string $pluginId): array
    {
        return PluginMetadata::getAllForPlugin($this, $pluginId);
    }

    /**
     * Set multiple metadata values for a plugin at once.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param array $data Key-value pairs to store.
     * @return static
     */
    public function setManyPluginMeta(string $pluginId, array $data): static
    {
        foreach ($data as $key => $value) {
            PluginMetadata::setValue($this, $pluginId, $key, $value);
        }
        return $this;
    }

    /**
     * Delete all metadata for a specific plugin.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @return int Number of records deleted.
     */
    public function deleteAllPluginMeta(string $pluginId): int
    {
        return PluginMetadata::deleteAllForPlugin($this, $pluginId);
    }

    /**
     * Increment a numeric plugin metadata value.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @param int $amount Amount to increment by.
     * @return int The new value.
     */
    public function incrementPluginMeta(string $pluginId, string $key, int $amount = 1): int
    {
        return PluginMetadata::incrementValue($this, $pluginId, $key, $amount);
    }

    /**
     * Decrement a numeric plugin metadata value.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @param int $amount Amount to decrement by.
     * @return int The new value.
     */
    public function decrementPluginMeta(string $pluginId, string $key, int $amount = 1): int
    {
        return PluginMetadata::decrementValue($this, $pluginId, $key, $amount);
    }

    /**
     * Get plugin metadata with a callback for default value.
     *
     * @param string $pluginId The plugin identifier (slug).
     * @param string $key The metadata key.
     * @param callable $default Callback to generate default value.
     * @return mixed
     */
    public function getOrSetPluginMeta(string $pluginId, string $key, callable $default): mixed
    {
        $value = $this->getPluginMeta($pluginId, $key);

        if ($value === null) {
            $value = $default($this);
            $this->setPluginMeta($pluginId, $key, $value);
        }

        return $value;
    }

    /**
     * Scope to filter models that have specific plugin metadata.
     *
     * @param $query
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @param mixed $value Optional value to match.
     * @return mixed
     */
    public function scopeWithPluginMeta($query, string $pluginId, string $key, mixed $value = null)
    {
        return $query->whereHas('pluginMetadata', function ($q) use ($pluginId, $key, $value) {
            $q->where('plugin_id', $pluginId)->where('key', $key);
            if ($value !== null) {
                $q->where('value', json_encode($value));
            }
        });
    }

    /**
     * Scope to filter models that do NOT have specific plugin metadata.
     *
     * @param $query
     * @param string $pluginId The plugin identifier.
     * @param string $key The metadata key.
     * @return mixed
     */
    public function scopeWithoutPluginMeta($query, string $pluginId, string $key)
    {
        return $query->whereDoesntHave('pluginMetadata', function ($q) use ($pluginId, $key) {
            $q->where('plugin_id', $pluginId)->where('key', $key);
        });
    }
}
