<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule energy refill every minute
Schedule::command('energy:refill')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Schedule property income collection every hour
Schedule::command('property:collect-income')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Auto-resolve old errors daily at 3 AM
Schedule::command('errors:auto-resolve --days=7')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

if ((bool) config('plugins.SportsBot.enabled') && (bool) config('plugins.SportsBot.schedule.enabled')) {
    $sportsBot = Schedule::command('sportsbot:run-native')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/sportsbot-scheduler.log'));

    match ((string) config('plugins.SportsBot.schedule.frequency')) {
        'everyMinute' => $sportsBot->everyMinute(),
        'everyFiveMinutes' => $sportsBot->everyFiveMinutes(),
        default => $sportsBot->everyTwoMinutes(),
    };
}

if ((bool) config('plugins.SportsBot.enabled')) {
    if ((bool) config('plugins.SportsBot.publishing.fixtures_today.enabled')) {
        Schedule::command('sportsbot:fixtures-today --send')
            ->dailyAt((string) config('plugins.SportsBot.publishing.fixtures_today.time', '08:00'))
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/sportsbot-fixtures-today.log'));
    }

    if ((bool) config('plugins.SportsBot.publishing.tv_guide.enabled')) {
        Schedule::command('sportsbot:tv-guide --send')
            ->dailyAt((string) config('plugins.SportsBot.publishing.tv_guide.time', '08:00'))
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/sportsbot-tv-guide.log'));
    }

    if ((bool) config('plugins.SportsBot.publishing.live_now.enabled')) {
        $liveNow = Schedule::command('sportsbot:live-now --send')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/sportsbot-live-now.log'));

        match ((string) config('plugins.SportsBot.publishing.live_now.frequency')) {
            'everyMinute' => $liveNow->everyMinute(),
            'everyTwoMinutes' => $liveNow->everyTwoMinutes(),
            'everyTenMinutes' => $liveNow->everyTenMinutes(),
            default => $liveNow->everyFiveMinutes(),
        };
    }
}
