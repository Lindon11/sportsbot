<?php

namespace App\Core\Providers;

use App\Core\Contracts\EconomyInterface;
use App\Core\Models\User;
use App\Core\Policies\UserPolicy;
use App\Core\Services\HookRegistry;
use App\Core\Services\TextFormatterService;
use App\Core\Services\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register TextFormatter service
        $this->app->singleton('text-formatter', function ($app) {
            return new TextFormatterService();
        });

        // Economy facade binding
        $this->app->singleton('economy', fn() => new WalletService());
        $this->app->alias('economy', WalletService::class);
        $this->app->alias('economy', EconomyInterface::class);

        // Note: 'inventory' and 'combat' service bindings are registered by their plugin
        // hooks.php files (loaded by AutoPluginHookLoader). Core never imports plugin classes
        // directly. Use Feature::available('inventory') to check if the plugin is enabled.

        // Register Artisan commands from Core namespace
        $this->commands([
            \App\Core\Console\Commands\InstallCommand::class,
            \App\Plugins\SportsBot\Commands\ScrapeFixturesCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce strict model behaviour in non-production environments
        // Catches N+1 queries, lazy loading, silently discarded attributes, and missing attributes
        Model::shouldBeStrict(! $this->app->environment('production'));

        // Define API rate limiters
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Game action limiters — applied per-route to mutation endpoints.
        // Tighter than the global limiter to prevent spam faster than the UI allows.
        RateLimiter::for('game-actions', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('combat-actions', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Register all built-in hook schemas with HookRegistry
        HookRegistry::defineCoreHooks();

        // Register policies
        Gate::policy(User::class, UserPolicy::class);

        // Warn loudly if LICENSE_CALLBACK_SECRET is empty outside local environments.
        // An empty secret means any HMAC signature will pass verification.
        if (!$this->app->environment('local') && empty(config('app.license_callback_secret'))) {
            \Illuminate\Support\Facades\Log::critical(
                'LICENSE_CALLBACK_SECRET is not set. The license callback endpoint is not secured. ' .
                'Run: php artisan license:generate-callback-secret'
            );
        }

        // Configure frontend directory paths - only add if they exist
        if (is_dir(base_path('frontend/views'))) {
            View::addLocation(base_path('frontend/views'));
        }
        if (is_dir(base_path('frontend/lang'))) {
            $this->app->singleton('path.lang', fn() => base_path('frontend/lang'));
        }

        // Register SocialiteProviders community drivers (Discord, etc.)
        \Illuminate\Support\Facades\Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            [\SocialiteProviders\Discord\DiscordExtendSocialite::class, 'handle']
        );
    }
}

