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
