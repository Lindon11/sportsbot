<?php

namespace App\Core\Services;

use Closure;

/**
 * Compatibility wrapper around the HookService container binding.
 *
 * Older code and plugins call GameHooks::listen/apply/define; this class
 * keeps that API stable while delegating to the current hook system.
 */
class GameHooks
{
    public static function listen(string $hookName, callable $callback, int $priority = 10): void
    {
        app('hook')->register($hookName, Closure::fromCallable($callback), $priority);
    }

    public static function apply(string $hookName, mixed $data = null): mixed
    {
        $result = app('hook')->filter($hookName, $data);

        // Keep previous behavior tolerant of callbacks that forget to return.
        return $result ?? $data;
    }

    public static function define(
        string $hook,
        array $schema = [],
        string $version = '1.0',
        string $stability = 'stable'
    ): void {
        HookRegistry::define($hook, $schema, $version, $stability);
    }
}
