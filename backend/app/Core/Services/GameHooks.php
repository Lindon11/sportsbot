<?php

namespace App\Core\Services;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Compatibility wrapper around the HookService container binding.
 *
 * Older code and plugins call GameHooks::listen/apply/define; this class
 * keeps that API stable while delegating to the current hook system.
 */
class GameHooks
{
    /**
     * Backward-compatible listener store for older tests and plugin code that
     * reset GameHooks state directly.
     *
     * @var array<string, array<int, array{callback: Closure, priority: int}>>
     */
    protected static array $listeners = [];

    public static function listen(string $hookName, callable $callback, int $priority = 10): void
    {
        $closure = Closure::fromCallable($callback);

        self::$listeners[$hookName][] = [
            'callback' => $closure,
            'priority' => $priority,
        ];

        app('hook')->register($hookName, $closure, $priority);
    }

    public static function apply(string $hookName, mixed $data = null): mixed
    {
        if (!empty(self::$listeners[$hookName])) {
            usort(self::$listeners[$hookName], fn($a, $b) => $b['priority'] <=> $a['priority']);

            $result = $data;

            foreach (self::$listeners[$hookName] as $listener) {
                try {
                    $next = $listener['callback']($result);
                    $result = $next ?? $result;
                } catch (\Throwable $e) {
                    Log::error("Hook '{$hookName}' callback failed: " . $e->getMessage(), [
                        'hook' => $hookName,
                        'priority' => $listener['priority'],
                        'exception' => $e,
                    ]);
                }
            }

            return $result;
        }

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
