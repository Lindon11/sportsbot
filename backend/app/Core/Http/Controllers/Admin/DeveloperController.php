<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use App\Core\Services\HookRegistry;
use App\Core\Services\MetricsRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Developer tools endpoint.
 * Exposes the registered hooks and metrics for plugin developers and the admin UI.
 */
class DeveloperController extends Controller
{
    /**
     * List all defined hooks with their schemas, versions, and stability.
     *
     * Hooks are grouped by their first dot-segment for readability:
     *   before.crime.commit → group 'before'
     *   after.bank.deposit  → group 'after'
     *   admin.sidebar       → group 'admin'
     *   OnCrimeCommit       → group 'legacy' (no dot)
     */
    public function hooks(): JsonResponse
    {
        $all = HookRegistry::all();
        $grouped = [];

        foreach ($all as $name => $definition) {
            $prefix = str_contains($name, '.') ? explode('.', $name)[0] : 'legacy';
            $grouped[$prefix][$name] = $definition;
        }

        ksort($grouped);

        return response()->json([
            'total'  => count($all),
            'groups' => $grouped,
        ]);
    }

    /**
     * List all registered metric keys (the keys plugins publish via MetricsRegistry::register()).
     * Does NOT execute the callables — just lists what's available.
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'keys' => MetricsRegistry::keys(),
        ]);
    }
}
