<?php

use App\Core\Services\GameHooks;
use App\Plugins\SportsBot\Models\SportsBotMatchState;
use App\Plugins\SportsBot\Models\SportsBotRun;
use App\Plugins\SportsBot\Models\SportsBotSentAlert;

GameHooks::define('sportsbot.live_rows', ['rows' => 'array'], '0.1', 'experimental');
GameHooks::define('sportsbot.match.normalized', ['match' => 'array'], '0.1', 'experimental');
GameHooks::define('sportsbot.match.allowed', ['match' => 'array', 'allowed' => 'boolean'], '0.1', 'experimental');
GameHooks::define('sportsbot.alerts.detected', ['match' => 'array', 'alerts' => 'array'], '0.1', 'experimental');
GameHooks::define('sportsbot.alert.message', ['alert' => 'array', 'message' => 'string'], '0.1', 'experimental');
GameHooks::define('sportsbot.alert.sent', ['alert' => 'array', 'results' => 'array'], '0.1', 'experimental');

GameHooks::listen('admin.dashboard.widgets', function (array $widgets): array {
    $latestRun = SportsBotRun::latest('id')->first();

    $widgets['sportsbot'] = [
        'title' => 'Sports Bot',
        'status' => $latestRun?->status ?? 'not_run',
        'nativeSending' => (bool) config('plugins.SportsBot.send_messages', false),
        'scheduleEnabled' => (bool) config('plugins.SportsBot.schedule.enabled', false),
        'trackedMatches' => SportsBotMatchState::count(),
        'sentAlerts' => SportsBotSentAlert::count(),
        'lastRunAt' => $latestRun?->finished_at?->toISOString() ?? $latestRun?->created_at?->toISOString(),
    ];

    return $widgets;
}, 10);

GameHooks::listen('admin.sidebar', function (array $sections): array {
    $sections[] = [
        'id' => 'sportsbot',
        'label' => 'SportsBot',
        'icon' => 'BoltIcon',
        'order' => 75,
        'plugin' => 'sportsbot',
        'children' => [
            ['type' => 'separator', 'label' => 'Overview'],
            ['route' => '/sportsbot/dashboard', 'label' => 'Dashboard', 'icon' => 'ChartBarIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/autopilot', 'label' => 'Autopilot', 'icon' => 'BoltIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/post-timings', 'label' => 'Post Timings', 'icon' => 'ClockIcon', 'plugin' => 'sportsbot'],

            ['type' => 'separator', 'label' => 'Fixture Content'],
            ['route' => '/sportsbot/football-fixtures', 'label' => 'Football Fixtures', 'icon' => 'TvIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/rugby-fixtures', 'label' => 'Rugby Fixtures', 'icon' => 'TvIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/fight-fixtures', 'label' => 'Fight Fixtures', 'icon' => 'TvIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/motorsport-fixtures', 'label' => 'Motorsport Fixtures', 'icon' => 'TvIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/usa-sports-fixtures', 'label' => 'USA Sports Fixtures', 'icon' => 'TvIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/other-sports-fixtures', 'label' => 'Other Sports Fixtures', 'icon' => 'TvIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/highlights', 'label' => 'Highlights', 'icon' => 'PlayIcon', 'plugin' => 'sportsbot'],

            ['type' => 'separator', 'label' => 'Routing'],
            ['route' => '/sportsbot/routing', 'label' => 'Telegram Routes', 'icon' => 'MapIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/discord-routes', 'label' => 'Discord Routes', 'icon' => 'MapIcon', 'plugin' => 'sportsbot'],

            ['type' => 'separator', 'label' => 'Configuration'],
            ['route' => '/sportsbot/coverage', 'label' => 'Coverage Settings', 'icon' => 'Cog6ToothIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/fixture-queue', 'label' => 'Fixture Queue', 'icon' => 'QueueListIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/scraper-settings', 'label' => 'Scraper Settings', 'icon' => 'MagnifyingGlassIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/telegram-settings', 'label' => 'Telegram Settings', 'icon' => 'Cog6ToothIcon', 'plugin' => 'sportsbot'],
            ['route' => '/sportsbot/webhook-diagnostics', 'label' => 'Webhook Diagnostics', 'icon' => 'CommandLineIcon', 'plugin' => 'sportsbot'],

            ['route' => '/sportsbot/update', 'label' => 'Update', 'icon' => 'ArrowPathIcon', 'plugin' => 'sportsbot'],
        ],
    ];

    return $sections;
}, 10);
