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

if ((bool) config('footballbot.enabled') && (bool) config('footballbot.schedule.enabled')) {
    $footballBot = Schedule::command('footballbot:run')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/footballbot-scheduler.log'));

    match ((string) config('footballbot.schedule.frequency')) {
        'everyMinute' => $footballBot->everyMinute(),
        'everyFiveMinutes' => $footballBot->everyFiveMinutes(),
        default => $footballBot->everyTwoMinutes(),
    };
}

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
