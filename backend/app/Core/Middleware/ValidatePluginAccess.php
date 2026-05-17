<?php

namespace App\Core\Middleware;

use App\Core\Models\InstalledPlugin;
use App\Core\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate Plugin Access Middleware
 *
 * Checks if the plugin associated with a route is active and licensed.
 * This creates a sandbox around plugin routes to prevent unauthorized access.
 *
 * Usage:
 *   Route::middleware(['plugin.access:crimes'])->group(function () {
 *       // Plugin routes here
 *   });
 *
 *   Or in plugin routes:
 *   Route::group(['middleware' => ['plugin.access:' . $pluginId]], function () {
 *       // Plugin routes
 *   });
 */
class ValidatePluginAccess
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $pluginId Optional plugin ID. If not provided, will be extracted from route.
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?string $pluginId = null): Response
    {
        // Get plugin ID from parameter or extract from route
        $pluginId = $pluginId ?? $this->extractPluginId($request);

        if (!$pluginId) {
            // No plugin specified - allow through (not a plugin route)
            return $next($request);
        }

        // Check if plugin is installed and enabled
        $plugin = InstalledPlugin::where('slug', $pluginId)->first();

        if (!$plugin) {
            return $this->pluginNotInstalled($request, $pluginId);
        }

        if (!$plugin->enabled) {
            return $this->pluginDisabled($request, $pluginId);
        }

        // Check if plugin requires a license
        if ($this->pluginRequiresLicense($pluginId)) {
            $licenseValid = $this->validateLicense($pluginId);

            if (!$licenseValid) {
                return $this->licenseInvalid($request, $pluginId);
            }
        }

        // Store plugin info in request for later use
        $request->attributes->set('current_plugin', $plugin);

        return $next($request);
    }

    /**
     * Extract plugin ID from the request route.
     *
     * @param Request $request
     * @return string|null
     */
    protected function extractPluginId(Request $request): ?string
    {
        // Try to get from route parameter
        $plugin = $request->route('plugin');
        if ($plugin) {
            return is_string($plugin) ? $plugin : $plugin->slug ?? null;
        }

        // Try to get from route name prefix (e.g., 'crimes.index' -> 'crimes')
        $routeName = $request->route()?->getName();
        if ($routeName) {
            $parts = explode('.', $routeName);
            if (count($parts) > 1) {
                $potentialPluginId = $parts[0];
                // Verify it's actually a plugin
                if (InstalledPlugin::where('slug', $potentialPluginId)->exists()) {
                    return $potentialPluginId;
                }
            }
        }

        // Try to get from route path prefix (e.g., '/crimes/...' -> 'crimes')
        $path = $request->path();
        $segments = explode('/', trim($path, '/'));
        if (count($segments) > 0) {
            $potentialPluginId = $segments[0];
            // Skip common non-plugin prefixes
            $skipPrefixes = ['api', 'admin', 'dashboard', 'user', 'settings', 'auth'];
            if (!in_array($potentialPluginId, $skipPrefixes)) {
                if (InstalledPlugin::where('slug', $potentialPluginId)->exists()) {
                    return $potentialPluginId;
                }
            }
        }

        return null;
    }

    /**
     * Check if a plugin requires a license.
     *
     * @param string $pluginId
     * @return bool
     */
    protected function pluginRequiresLicense(string $pluginId): bool
    {
        // Check plugin.json for license_required flag
        $pluginPath = app_path('Plugins/' . ucfirst($pluginId) . '/plugin.json');

        if (!file_exists($pluginPath)) {
            // Try lowercase
            $pluginPath = app_path('Plugins/' . $pluginId . '/plugin.json');
        }

        if (file_exists($pluginPath)) {
            $manifest = json_decode(file_get_contents($pluginPath), true);
            return $manifest['license_required'] ?? false;
        }

        return false;
    }

    /**
     * Validate the license for a plugin.
     *
     * @param string $pluginId
     * @return bool
     */
    protected function validateLicense(string $pluginId): bool
    {
        // Check if system has a valid license
        if (!LicenseService::isLicensed()) {
            return false;
        }

        $details = LicenseService::getDetails();

        if (!$details['valid'] ?? false) {
            return false;
        }

        // Check if license covers this specific plugin
        $payload = $details['payload'] ?? [];
        $allowedPlugins = $payload['plugins'] ?? 'all';

        // 'all' means all plugins are allowed
        if ($allowedPlugins === 'all') {
            return true;
        }

        // Check if this specific plugin is in the allowed list
        if (is_array($allowedPlugins)) {
            return in_array($pluginId, $allowedPlugins);
        }

        return false;
    }

    /**
     * Response for plugin not installed.
     *
     * @param Request $request
     * @param string $pluginId
     * @return Response
     */
    protected function pluginNotInstalled(Request $request, string $pluginId): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'success' => false,
                'error' => 'plugin_not_installed',
                'message' => "The plugin '{$pluginId}' is not installed.",
                'plugin_id' => $pluginId,
            ], 404);
        }

        abort(404, "The plugin '{$pluginId}' is not installed.");
    }

    /**
     * Response for plugin disabled.
     *
     * @param Request $request
     * @param string $pluginId
     * @return Response
     */
    protected function pluginDisabled(Request $request, string $pluginId): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'success' => false,
                'error' => 'plugin_disabled',
                'message' => "The plugin '{$pluginId}' is currently disabled.",
                'plugin_id' => $pluginId,
            ], 403);
        }

        abort(403, "The plugin '{$pluginId}' is currently disabled.");
    }

    /**
     * Response for invalid license.
     *
     * @param Request $request
     * @param string $pluginId
     * @return Response
     */
    protected function licenseInvalid(Request $request, string $pluginId): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'success' => false,
                'error' => 'license_required',
                'message' => "The plugin '{$pluginId}' requires a valid license.",
                'plugin_id' => $pluginId,
            ], 402); // 402 Payment Required
        }

        abort(402, "The plugin '{$pluginId}' requires a valid license.");
    }
}
