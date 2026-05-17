<?php

namespace App\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user needs to change password
        if ($user && $user->force_password_change) {
            // Allow access to logout, password change, user info, and CSRF cookie
            if (!$request->is(
                'api/logout',
                'api/user/change-password',
                'api/user',
                'sanctum/csrf-cookie'
            )) {
                return response()->json([
                    'error' => 'Password change required',
                    'message' => 'You must change your password before continuing.',
                    'force_password_change' => true
                ], 403);
            }
        }

        return $next($request);
    }
}
