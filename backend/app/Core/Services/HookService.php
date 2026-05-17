<?php

namespace App\Core\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hook Service - Mimics Gangster Legends V2 Hook System
 * Allows modules to register and execute hooks for extensibility
 */
class HookService
{
    /**
     * Registered hooks
     * @var array<string, array<Closure>>
     */
    protected array $hooks = [];

    /**
     * Tracks which hook arrays need re-sorting
     * @var array<string, bool>
     */
    protected array $dirty = [];

    /**
     * Hook execution counts (for debugging)
     * @var array<string, int>
     */
    protected array $executionCounts = [];

    /**
     * Register a hook callback
     *
     * @param string $hookName
     * @param Closure $callback
     * @param int $priority Higher numbers run first
     * @return void
     */
    public function register(string $hookName, Closure $callback, int $priority = 10): void
    {
        if (!isset($this->hooks[$hookName])) {
            $this->hooks[$hookName] = [];
        }

        $this->hooks[$hookName][] = [
            'callback' => $callback,
            'priority' => $priority,
        ];

        // Mark as needing sort — actual sort happens lazily before execution
        $this->dirty[$hookName] = true;
    }

    /**
     * Remove all callbacks for a specific hook name
     *
     * @param string $hookName
     * @return void
     */
    public function removeCallbacks(string $hookName): void
    {
        unset($this->hooks[$hookName], $this->dirty[$hookName]);
    }

    /**
     * Sort hooks by priority if dirty (lazy sorting)
     */
    protected function sortIfNeeded(string $hookName): void
    {
        if (!empty($this->dirty[$hookName])) {
            usort($this->hooks[$hookName], fn($a, $b) => $b['priority'] <=> $a['priority']);
            unset($this->dirty[$hookName]);
        }
    }

    /**
     * Run a hook and collect results
     *
     * @param string $hookName
     * @param mixed $data Initial data
     * @param bool $returnSingle Return single modified value instead of array
     * @return mixed
     */
    public function run(string $hookName, mixed $data = null, bool $returnSingle = false): mixed
    {
        if (!isset($this->hooks[$hookName])) {
            return $returnSingle ? $data : [];
        }

        $this->sortIfNeeded($hookName);
        $this->executionCounts[$hookName] = ($this->executionCounts[$hookName] ?? 0) + 1;

        $results = [];

        foreach ($this->hooks[$hookName] as $hook) {
            try {
                $callback = $hook['callback'];

                if ($returnSingle) {
                    // Pass data through each callback (WordPress filter style)
                    $data = $callback($data);
                } else {
                    // Collect results from each callback (WordPress action style)
                    $result = $callback($data);
                    if ($result !== null) {
                        $results[] = $result;
                    }
                }
            } catch (\Throwable $e) {
                // Log the error but continue executing remaining callbacks
                Log::error("Hook '{$hookName}' callback failed: " . $e->getMessage(), [
                    'hook' => $hookName,
                    'priority' => $hook['priority'],
                    'exception' => $e,
                ]);
            }
        }

        return $returnSingle ? $data : $results;
    }

    /**
     * Check if a hook has any callbacks registered
     *
     * @param string $hookName
     * @return bool
     */
    public function has(string $hookName): bool
    {
        return isset($this->hooks[$hookName]) && count($this->hooks[$hookName]) > 0;
    }

    /**
     * Get count of callbacks for a hook
     *
     * @param string $hookName
     * @return int
     */
    public function count(string $hookName): int
    {
        return isset($this->hooks[$hookName]) ? count($this->hooks[$hookName]) : 0;
    }

    /**
     * Clear all callbacks for a hook
     *
     * @param string $hookName
     * @return void
     */
    public function clear(string $hookName): void
    {
        unset($this->hooks[$hookName]);
    }

    /**
     * Clear all hooks
     *
     * @return void
     */
    public function clearAll(): void
    {
        $this->hooks = [];
        $this->executionCounts = [];
    }

    /**
     * Get all registered hook names
     *
     * @return array<string>
     */
    public function getHookNames(): array
    {
        return array_keys($this->hooks);
    }

    /**
     * Get debug information about hooks.
     * Only available in non-production environments.
     *
     * @return array
     */
    public function getDebugInfo(): array
    {
        if (app()->isProduction()) {
            return ['message' => 'Debug info is not available in production.'];
        }

        $info = [];

        foreach ($this->hooks as $hookName => $callbacks) {
            $info[$hookName] = [
                'callback_count' => count($callbacks),
                'execution_count' => $this->executionCounts[$hookName] ?? 0,
                'priorities' => array_column($callbacks, 'priority'),
            ];
        }

        return $info;
    }

    /**
     * Apply a filter hook (alias for run with returnSingle = true)
     *
     * @param string $hookName
     * @param mixed $value
     * @return mixed
     */
    public function filter(string $hookName, mixed $value): mixed
    {
        return $this->run($hookName, $value, true);
    }

    /**
     * Execute an action hook (alias for run with returnSingle = false)
     *
     * @param string $hookName
     * @param mixed $data
     * @return array
     */
    public function action(string $hookName, mixed $data = null): array
    {
        return $this->run($hookName, $data, false);
    }
}
