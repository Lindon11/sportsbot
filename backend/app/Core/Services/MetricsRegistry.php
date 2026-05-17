<?php

namespace App\Core\Services;

/**
 * Lightweight static registry that lets plugins publish live metrics
 * without Core importing any plugin class directly.
 *
 * Plugins call MetricsRegistry::register() from their hooks.php.
 * Core code calls MetricsRegistry::get() with a safe default.
 */
class MetricsRegistry
{
    protected static array $providers = [];

    public static function register(string $key, callable $provider): void
    {
        static::$providers[$key] = $provider;
    }

    public static function get(string $key, mixed $default = 0): mixed
    {
        if (! isset(static::$providers[$key])) {
            return $default;
        }
        try {
            return (static::$providers[$key])();
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function all(): array
    {
        $result = [];
        foreach (static::$providers as $key => $provider) {
            try {
                $result[$key] = $provider();
            } catch (\Throwable) {
                $result[$key] = 0;
            }
        }
        return $result;
    }

    /** Return the list of registered metric keys without executing callables. */
    public static function keys(): array
    {
        return array_keys(static::$providers);
    }
}
