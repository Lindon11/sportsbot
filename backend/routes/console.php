<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

if (! function_exists('sportsbotSetting')) {
    function sportsbotSetting(string $key, mixed $default = null): mixed
    {
        try {
            return app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

if (! function_exists('sportsbotScheduleFrequency')) {
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
    if ((bool) sportsbotSetting('fixture_queue_schedule_enabled', config('plugins.SportsBot.publishing.fixture_queue.enabled'))) {
        if ((bool) sportsbotSetting('fixture_queue_prefetch_enabled', config('plugins.SportsBot.publishing.fixture_queue.prefetch_enabled', true))) {
            Schedule::command('sportsbot:fixtures-prefetch')
                ->dailyAt((string) sportsbotSetting('fixture_queue_prefetch_time', config('plugins.SportsBot.publishing.fixture_queue.prefetch_time', '00:00')))
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
            Schedule::command('sportsbot:fixtures-publish')
                ->dailyAt((string) sportsbotSetting('fixture_queue_publish_time', config('plugins.SportsBot.publishing.fixture_queue.publish_time', '00:00')))
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/sportsbot-fixture-queue-publish.log'));
        }

        if ((bool) sportsbotSetting('highlights_schedule_enabled', config('plugins.SportsBot.publishing.highlights.enabled', true))) {
            $highlights = Schedule::call(function (): void {
                $module = app(\App\Plugins\SportsBot\Services\Content\HighlightsContentModule::class);
                $publisher = app(\App\Plugins\SportsBot\Services\SportsBotPublisher::class);

                try {
                    $summary = $module->buildSummary();
                    $eventIds = array_map(fn (array $h): string => (string) ($h['event_id'] ?? ''), $summary['highlights'] ?? []);

                    $result = $publisher->send($module, 'schedule');

                    // Mark these events as sent so they aren't re-sent next cycle
                    foreach ($eventIds as $eid) {
                        if ($eid !== '') {
                            \App\Plugins\SportsBot\Models\SportsBotHighlightSent::query()->upsert(
                                ['event_id' => $eid, 'sent_at' => now()],
                                'event_id',
                                ['sent_at' => now()]
                            );
                        }
                    }

                    \Illuminate\Support\Facades\Log::info('sportsbot.highlights.scheduled_sent', [
                        'total' => count($result['results'] ?? []),
                        'sent' => (bool) ($result['sent'] ?? false),
                    ]);

                    \App\Plugins\SportsBot\Models\SportsBotHighlightSent::where('sent_at', '<', now()->subDays(3))->delete();
                } catch (\Throwable $error) {
                    \Illuminate\Support\Facades\Log::warning('sportsbot.highlights.scheduled_failed', [
                        'error' => $error->getMessage(),
                    ]);
                }
            })->name('sportsbot-highlights')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/sportsbot-highlights.log'));

            sportsbotScheduleFrequency(
                $highlights,
                (string) sportsbotSetting('highlights_schedule_frequency', config('plugins.SportsBot.publishing.highlights.frequency', 'everyThirtyMinutes'))
            );
        }
    }
}
