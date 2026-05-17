<?php

namespace App\Core\Middleware;

use App\Core\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;

class VerifyLicense
{
    /**
     * Routes that bypass license verification.
     * These must remain accessible so admins can activate a license.
     */
    protected array $except = [
        'install/*',
        'api/login',
        'api/register',
        'api/forgot-password',
        'api/validate-reset-token',
        'api/reset-password',
        'api/2fa/verify',
        'api/oauth/*/callback',
        'api/license/callback',
        'api/admin/license/status',
        'api/admin/license/activate',
    ];

    /**
     * Handle an incoming request.
     *
     * When no valid license is stored the entire admin panel is locked.
     * Only the license status / activate endpoints are reachable so the
     * admin can enter a key and unlock the panel.
     */
    public function handle(Request $request, Closure $next)
    {
        // Always allow excluded routes through
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        // Skip if the app hasn't been installed yet (installer needs to work)
        if (!file_exists(storage_path('installed'))) {
            return $next($request);
        }

        // If a valid license exists, continue normally
        if (LicenseService::isLicensed()) {
            return $next($request);
        }

        // --- No valid license — lock the panel ---

        // API / JSON requests → 423 Locked
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'license_required',
                'message' => 'This panel is locked. Please activate a valid license key to continue.',
            ], 423);
        }

        // Web requests → let the SPA handle it (the Vue router will redirect)
        return $next($request);
    }
}
