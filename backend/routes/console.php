<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

function sportsbotSetting(string $key, mixed $default = null): mixed
{
    try {
        return app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->get($key, $default);
    } catch (\Throwable) {
        return $default;
    }
}

function sportsbotScheduleFrequency($event, string $frequency): void
{
    match ($frequency) {
        'everyMinute' => $event->everyMinute(),
        'everyTwoMinutes' => $event->everyTwoMinutes(),
        'everyFiveMinutes' => $event->everyFiveMinutes(),
        'everyTenMinutes' => $event->everyTenMinutes(),
        'everyFifteenMinutes' => $event->everyFifteenMinutes(),
        'everyThirtyMinutes' => $event->everyThirtyMinutes(),
        'hourly' => $event->hourly(),
        default => $event->everyFiveMinutes(),
    };
}

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

if ((bool) config('plugins.SportsBot.enabled') && (bool) sportsbotSetting('schedule_enabled', config('plugins.SportsBot.schedule.enabled'))) {
    $sportsBot = Schedule::command('sportsbot:run-native')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/sportsbot-scheduler.log'));

    sportsbotScheduleFrequency($sportsBot, (string) sportsbotSetting('schedule_frequency', config('plugins.SportsBot.schedule.frequency', 'everyTwoMinutes')));
}

if ((bool) config('plugins.SportsBot.enabled')) {
    if ((bool) sportsbotSetting('fixtures_today_schedule_enabled', config('plugins.SportsBot.publishing.fixtures_today.enabled'))) {
        Schedule::command('sportsbot:fixtures-today --send')
            ->dailyAt((string) sportsbotSetting('fixtures_today_schedule_time', config('plugins.SportsBot.publishing.fixtures_today.time', '08:00')))
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/sportsbot-fixtures-today.log'));
    }

    if ((bool) sportsbotSetting('tv_guide_schedule_enabled', config('plugins.SportsBot.publishing.tv_guide.enabled'))) {
        Schedule::command('sportsbot:tv-guide --send')
            ->dailyAt((string) sportsbotSetting('tv_guide_schedule_time', config('plugins.SportsBot.publishing.tv_guide.time', '08:00')))
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/sportsbot-tv-guide.log'));
    }

    if ((bool) sportsbotSetting('live_now_schedule_enabled', config('plugins.SportsBot.publishing.live_now.enabled'))) {
        $liveNow = Schedule::command('sportsbot:live-now --send')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/sportsbot-live-now.log'));

        sportsbotScheduleFrequency($liveNow, (string) sportsbotSetting('live_now_schedule_frequency', config('plugins.SportsBot.publishing.live_now.frequency', 'everyFiveMinutes')));
    }

    if ((bool) sportsbotSetting('fixture_queue_schedule_enabled', config('plugins.SportsBot.publishing.fixture_queue.enabled'))) {
        if ((bool) sportsbotSetting('fixture_queue_prefetch_enabled', config('plugins.SportsBot.publishing.fixture_queue.prefetch_enabled', true))) {
            Schedule::command('sportsbot:fixtures-prefetch')
                ->dailyAt((string) sportsbotSetting('fixture_queue_prefetch_time', config('plugins.SportsBot.publishing.fixture_queue.prefetch_time', '05:00')))
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/sportsbot-fixture-queue-prefetch.log'));
        }

        if ((bool) sportsbotSetting('fixture_queue_enrich_enabled', config('plugins.SportsBot.publishing.fixture_queue.enrich_enabled', true))) {
            $enrichDays = max(0, (int) sportsbotSetting('fixture_queue_enrich_days', config('plugins.SportsBot.publishing.fixture_queue.enrich_days', 2)));
            $enrichLimit = max(1, (int) sportsbotSetting('fixture_queue_enrich_limit', config('plugins.SportsBot.publishing.fixture_queue.enrich_limit', 30)));
            $enrich = Schedule::command("sportsbot:fixtures-enrich --days={$enrichDays} --limit={$enrichLimit}")
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/sportsbot-fixture-queue-enrich.log'));

            sportsbotScheduleFrequency($enrich, (string) sportsbotSetting('fixture_queue_enrich_frequency', config('plugins.SportsBot.publishing.fixture_queue.enrich_frequency', 'everyThirtyMinutes')));
        }

        if ((bool) sportsbotSetting('fixture_queue_render_enabled', config('plugins.SportsBot.publishing.fixture_queue.render_enabled', true))) {
            $render = Schedule::command('sportsbot:fixtures-render')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/sportsbot-fixture-queue-render.log'));

            sportsbotScheduleFrequency($render, (string) sportsbotSetting('fixture_queue_render_frequency', config('plugins.SportsBot.publishing.fixture_queue.render_frequency', 'everyTenMinutes')));
        }

        if ((bool) sportsbotSetting('fixture_queue_publish_enabled', config('plugins.SportsBot.publishing.fixture_queue.publish_enabled', true))) {
            $publish = Schedule::command('sportsbot:fixtures-publish')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/sportsbot-fixture-queue-publish.log'));

            sportsbotScheduleFrequency($publish, (string) sportsbotSetting('fixture_queue_publish_frequency', config('plugins.SportsBot.publishing.fixture_queue.publish_frequency', 'everyFiveMinutes')));
        }
    }
}
