<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InstallerLocked
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow preview in local/dev
        if (file_exists(storage_path('installed')) && !($request->has('preview') && app()->environment('local'))) {
            abort(403, 'Installer is disabled after installation.');
        }
        return $next($request);
    }
}
