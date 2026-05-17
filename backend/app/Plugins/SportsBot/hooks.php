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
            [
                'route' => '/sportsbot/dashboard',
                'label' => 'Dashboard',
                'icon' => 'ChartBarIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/fixtures-today',
                'label' => 'Fixtures Today',
                'icon' => 'CalendarIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/football-fixtures',
                'label' => 'Football Fixtures TV',
                'icon' => 'TvIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/rugby-fixtures',
                'label' => 'Rugby Fixtures TV',
                'icon' => 'TvIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/fight-fixtures',
                'label' => 'Fights TV',
                'icon' => 'TvIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/motorsport-fixtures',
                'label' => 'Motorsport',
                'icon' => 'TvIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/tv-guide',
                'label' => 'TV Guide',
                'icon' => 'TvIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/live-now',
                'label' => 'Live Now',
                'icon' => 'BoltIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/routing',
                'label' => 'Telegram Routes',
                'icon' => 'MapIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/coverage',
                'label' => 'Coverage Settings',
                'icon' => 'Cog6ToothIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/webhook-diagnostics',
                'label' => 'Webhook Diagnostics',
                'icon' => 'CommandLineIcon',
                'plugin' => 'sportsbot',
            ],
            [
                'route' => '/sportsbot/fixture-queue',
                'label' => 'Fixture Queue',
                'icon' => 'QueueListIcon',
                'plugin' => 'sportsbot',
            ],
        ],
    ];

    return $sections;
}, 10);
