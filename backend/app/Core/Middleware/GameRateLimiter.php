<?php

namespace App\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class GameRateLimiter
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $limit = '60:1'): Response
    {
        [$maxAttempts, $decayMinutes] = explode(':', $limit);
        $maxAttempts = (int) $maxAttempts;
        $decayMinutes = (int) $decayMinutes;

        $key = $this->resolveRequestKey($request);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => "Too many requests. Please wait {$seconds} seconds before trying again.",
                'retry_after' => $seconds,
            ], 429)->withHeaders([
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        // Add rate limit headers
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $maxAttempts),
        ]);

        return $response;
    }

    /**
     * Resolve the rate limit key for the request.
     * Falls back to route URI if route has no name.
     */
    protected function resolveRequestKey(Request $request): string
    {
        $routeIdentifier = $request->route()?->getName() ?? $request->route()?->uri() ?? $request->path();
        $userIdentifier = $request->user()?->id ?? $request->ip();

        return 'game-api:' . $userIdentifier . ':' . $routeIdentifier;
    }
}
