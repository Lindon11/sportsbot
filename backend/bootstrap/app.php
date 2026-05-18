<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$basePath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

$app = Application::configure(basePath: $basePath)
    ->withRouting(
        web: $basePath . '/routes/web.php',
        api: $basePath . '/routes/api.php',
        commands: $basePath . '/routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Web middleware
        $middleware->web(append: [
            \App\Core\Middleware\CheckUserRank::class, // Auto-check rank progression
        ]);

        // API middleware configuration
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Enable Sanctum stateful authentication for API
        $middleware->statefulApi();

        // Exclude installer routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'install/*',
            'sportsbot/telegram/webhook',
        ]);

        // Register Spatie Permission middleware aliases
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'game.throttle' => \App\Core\Middleware\GameRateLimiter::class,
            'force.password.change' => \App\Core\Middleware\ForcePasswordChange::class,
            'verify.license' => \App\Core\Middleware\VerifyLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Log all exceptions to database for admin review
        $exceptions->report(function (\Throwable $e) {
            // Skip logging for certain exception types
            $skipTypes = [
                \Illuminate\Auth\AuthenticationException::class,
                \Illuminate\Validation\ValidationException::class,
                \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
                \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
            ];

            // Don't log if it's a skipped type
            // Only attempt database logging if app is booted (not during composer scripts)
            if (!in_array(get_class($e), $skipTypes) && app()->isBooted()) {
                try {
                    \App\Core\Models\ErrorLog::logError($e, request());
                } catch (\Throwable $logError) {
                    // Silently fail if logging doesn't work
                }
            }
        });
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Configure API authentication to return JSON instead of redirecting
        $middleware->redirectGuestsTo(fn () => abort(401, 'Unauthenticated'));
    })
    ->create();

$app->usePublicPath($basePath . '/public');
$app->useStoragePath($basePath . '/storage');

return $app;
