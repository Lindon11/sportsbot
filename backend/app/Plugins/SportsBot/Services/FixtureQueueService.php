<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use App\Plugins\SportsBot\Support\SportsBotSports;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class FixtureQueueService
{
    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly FixturesTodayService $fixturesService = new FixturesTodayService(),
        private readonly SportsBotCardRenderer $cards = new SportsBotCardRenderer(),
        private readonly TelegramNotifier $notifier = new TelegramNotifier(),
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    public function prefetchAll(): array
    {
        $results = [];

        foreach (SportsFixtureConfig::enabledSportKeys() as $sportKey) {
            $results[$sportKey] = $this->prefetch($sportKey);
        }

        return $results;
    }

    public function prefetch(string $sportKey): array
    {
        $config = SportsFixtureConfig::for($sportKey);
        if ($config === null) {
            return ['sport' => $sportKey, 'error' => "Unknown sport: {$sportKey}", 'prefetched' => 0];
        }

        $today = Carbon::today();
        $endDate = Carbon::today()->addDays((int) ($config['data_fetch_window'] ?? 7));
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $summary = $this->fixturesService->buildSummary(
            $sportKey,
            (int) ($config['data_fetch_window'] ?? 7)
        );

        $fixtures = $this->flattenFixtures($summary);

        foreach ($fixtures as $fixture) {
            $eventId = (string) ($fixture['event_id'] ?? '');
            if ($eventId === '') {
                $skipped++;
                continue;
            }

            $publishDate = $this->resolvePublishDate($fixture, $config);
            $hash = $this->computeHash($fixture);

            $existing = SportsBotFixtureQueue::query()
                ->where('event_id', $eventId)
                ->where('sport_key', $sportKey)
                ->where('publish_date', $publishDate)
                ->first();

            if ($existing) {
                if ($existing->payload_hash === $hash) {
                    $skipped++;
                    continue;
                }

                $existing->fill([
                    'fixture_data' => $fixture,
                    'payload_hash' => $hash,
                    'status' => SportsBotFixtureQueue::STATUS_DRAFT,
                    'asset_status' => SportsBotFixtureQueue::ASSET_PENDING,
                    'last_refreshed_at' => now(),
                ])->save();
                $updated++;
            } else {
                SportsBotFixtureQueue::query()->create([
                    'event_id' => $eventId,
                    'sport_key' => $sportKey,
                    'publish_date' => $publishDate,
                    'status' => SportsBotFixtureQueue::STATUS_DRAFT,
                    'asset_status' => SportsBotFixtureQueue::ASSET_PENDING,
                    'payload_hash' => $hash,
                    'fixture_data' => $fixture,
                    'route_key' => $config['topic_key'] ?? null,
                    'last_refreshed_at' => now(),
                ]);
                $created++;
            }
        }

        Log::info('sportsbot.fixture_queue.prefetched', [
            'sport' => $sportKey,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($fixtures),
        ]);

        return [
            'sport' => $sportKey,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($fixtures),
        ];
    }

    public function renderAll(): array
    {
        $results = [];

        foreach (SportsFixtureConfig::enabledSportKeys() as $sportKey) {
            $results[$sportKey] = $this->render($sportKey);
        }

        return $results;
    }

    public function render(string $sportKey): array
    {
        $config = SportsFixtureConfig::for($sportKey);
        if ($config === null) {
            return ['sport' => $sportKey, 'error' => "Unknown sport: {$sportKey}", 'rendered' => 0];
        }

        $maxDate = Carbon::today()->addDays((int) ($config['card_prepare_window'] ?? 2));
        $cardVersion = (string) ($config['default_card_version'] ?? 'v1');
        $rendered = 0;
        $skipped = 0;
        $failed = 0;

        $drafts = SportsBotFixtureQueue::query()
            ->draft()
            ->bySport($sportKey)
            ->where('publish_date', '<=', $maxDate->toDateString())
            ->get();

        foreach ($drafts as $entry) {
            $fixture = (array) ($entry->fixture_data ?? []);

            try {
                $this->cacheAssets($entry, $fixture);

                $card = $this->cards->fixtureCard($fixture, $cardVersion);
                $cardPath = (string) ($card['path'] ?? '');

                if ($cardPath === '' || !is_file($cardPath)) {
                    throw new \RuntimeException('Card render returned no valid file path');
                }

                $entry->card_path = $cardPath;
                $entry->caption = $this->buildCaption($fixture, $config);
                $entry->status = SportsBotFixtureQueue::STATUS_READY;
                $entry->save();

                $rendered++;
            } catch (Throwable $error) {
                $entry->status = SportsBotFixtureQueue::STATUS_FAILED;
                $entry->error = mb_substr($error->getMessage(), 0, 1000);
                $entry->save();

                Log::warning('sportsbot.fixture_queue.render_failed', [
                    'sport' => $sportKey,
                    'event_id' => $entry->event_id,
                    'error' => $error->getMessage(),
                ]);
                $failed++;
            }
        }

        Log::info('sportsbot.fixture_queue.rendered', [
            'sport' => $sportKey,
            'rendered' => $rendered,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return [
            'sport' => $sportKey,
            'rendered' => $rendered,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    public function publishAll(array $options = []): array
    {
        $results = [];

        foreach (SportsFixtureConfig::enabledSportKeys() as $sportKey) {
            $results[$sportKey] = $this->publish($sportKey, $options);
        }

        return $results;
    }

    public function publish(string $sportKey, array $options = []): array
    {
        $config = SportsFixtureConfig::for($sportKey);
        if ($config === null) {
            return ['sport' => $sportKey, 'error' => "Unknown sport: {$sportKey}", 'sent' => 0];
        }

        $today = Carbon::today()->toDateString();
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        $items = SportsBotFixtureQueue::query()
            ->bySport($sportKey)
            ->where('publish_date', $today)
            ->whereIn('status', [SportsBotFixtureQueue::STATUS_READY, SportsBotFixtureQueue::STATUS_DRAFT])
            ->get();

        foreach ($items as $entry) {
            if ($entry->status !== SportsBotFixtureQueue::STATUS_READY) {
                $skipped++;
                continue;
            }

            try {
                $verified = $this->verifyBeforePublish($entry, $config);

                if (!$verified) {
                    $skipped++;
                    continue;
                }

                $results = $this->sendToTelegram($entry, $config, $options);

                $entry->status = SportsBotFixtureQueue::STATUS_SENT;
                $entry->sent_at = now();
                $entry->telegram_message_id = $results['message_id'] ?? null;
                $entry->topic_id = $results['topic_id'] ?? null;
                $entry->payload = array_merge((array) $entry->payload, ['publish_results' => $results]);
                $entry->save();

                $sent++;
            } catch (Throwable $error) {
                $entry->status = SportsBotFixtureQueue::STATUS_FAILED;
                $entry->error = mb_substr($error->getMessage(), 0, 1000);
                $entry->save();

                Log::error('sportsbot.fixture_queue.publish_failed', [
                    'sport' => $sportKey,
                    'event_id' => $entry->event_id,
                    'error' => $error->getMessage(),
                ]);
                $failed++;
            }
        }

        Log::info('sportsbot.fixture_queue.published', [
            'sport' => $sportKey,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return [
            'sport' => $sportKey,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    public function refreshEvent(string $eventId): array
    {
        $entries = SportsBotFixtureQueue::query()
            ->where('event_id', $eventId)
            ->whereIn('status', [SportsBotFixtureQueue::STATUS_DRAFT, SportsBotFixtureQueue::STATUS_READY, SportsBotFixtureQueue::STATUS_FAILED])
            ->get();

        if ($entries->isEmpty()) {
            return ['refreshed' => false, 'error' => "No queue entries found for event: {$eventId}"];
        }

        $count = 0;

        foreach ($entries as $entry) {
            try {
                $fresh = $this->provider->lookupEvent($entry->event_id);
                if ($fresh === null || !is_array($fresh)) {
                    $entry->error = 'Event no longer available from provider';
                    $entry->status = SportsBotFixtureQueue::STATUS_SKIPPED;
                    $entry->save();
                    continue;
                }

                $fixture = (array) ($entry->fixture_data ?? []);
                foreach ($fresh as $key => $value) {
                    $arrayKey = lcfirst(str_replace('str', '', $key));
                    if ($arrayKey === '') {
                        $arrayKey = $key;
                    }

                    if (!isset($fixture[$arrayKey]) && !isset($fixture[$key])) {
                        $fixture[$key] = $value;
                    }
                }

                $newHash = $this->computeHash($fixture);

                if ($newHash !== $entry->payload_hash) {
                    $entry->fixture_data = $fixture;
                    $entry->payload_hash = $newHash;
                    $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
                    $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
                    $entry->card_path = null;
                    $entry->caption = null;
                    $entry->error = null;
                } else {
                    $entry->status = SportsBotFixtureQueue::STATUS_READY;
                }

                $entry->last_refreshed_at = now();
                $entry->save();
                $count++;
            } catch (Throwable $error) {
                Log::error('sportsbot.fixture_queue.refresh_failed', [
                    'event_id' => $eventId,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        Log::info('sportsbot.fixture_queue.refreshed', [
            'event_id' => $eventId,
            'entries' => $count,
        ]);

        return ['refreshed' => true, 'entries' => $count];
    }

    private function verifyBeforePublish(SportsBotFixtureQueue $entry, array $config): bool
    {
        try {
            $freshData = $this->provider->lookupEvent($entry->event_id);
            if ($freshData === null || !is_array($freshData)) {
                return false;
            }

            $fixture = (array) ($entry->fixture_data ?? []);
            $currentHash = $this->computeHash($fixture);

            foreach ($freshData as $key => $value) {
                $fixture[$key] = $value;
            }

            $newHash = $this->computeHash($fixture);

            if ($currentHash === $newHash) {
                return true;
            }

            Log::info('sportsbot.fixture_queue.event_changed', [
                'event_id' => $entry->event_id,
                'sport' => $entry->sport_key,
            ]);

            $entry->fixture_data = $fixture;
            $entry->payload_hash = $newHash;
            $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
            $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
            $entry->card_path = null;
            $entry->last_refreshed_at = now();
            $entry->save();

            $cardVersion = (string) ($config['default_card_version'] ?? 'v1');

            $this->cacheAssets($entry, $fixture);

            $card = $this->cards->fixtureCard($fixture, $cardVersion);
            $cardPath = (string) ($card['path'] ?? '');

            if ($cardPath !== '' && is_file($cardPath)) {
                $entry->card_path = $cardPath;
                $entry->caption = $this->buildCaption($fixture, $config);
                $entry->status = SportsBotFixtureQueue::STATUS_READY;
                $entry->save();

                return true;
            }

            return false;
        } catch (Throwable $error) {
            Log::warning('sportsbot.fixture_queue.verify_failed', [
                'event_id' => $entry->event_id,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    private function sendToTelegram(SportsBotFixtureQueue $entry, array $config, array $options): array
    {
        $routeKey = $entry->route_key ?? $config['topic_key'] ?? 'default';
        $caption = (string) ($entry->caption ?? '');
        $cardPath = (string) ($entry->card_path ?? '');

        $notifyOptions = [
            'route_key' => $routeKey,
            'type' => strtoupper($entry->sport_key) . '_FIXTURES',
            'payload' => [
                'source' => 'fixture_queue',
                'content_key' => strtoupper($entry->sport_key) . '_FIXTURES',
                'event_id' => $entry->event_id,
                'fixture_queue_id' => $entry->id,
                'fixture' => [
                    'time' => (string) ($entry->fixture_data['time'] ?? ''),
                    'league' => (string) ($entry->fixture_data['league'] ?? ''),
                    'home_team' => (string) ($entry->fixture_data['home_team'] ?? ''),
                    'away_team' => (string) ($entry->fixture_data['away_team'] ?? ''),
                    'tv_channel' => (string) ($entry->fixture_data['tv_channel'] ?? ''),
                ],
            ],
        ];

        if ($cardPath !== '' && is_file($cardPath)) {
            $targets = $this->routingService->resolveTargets($routeKey);
            $tgResults = $this->notifier->sendPhoto($cardPath, $caption, $notifyOptions);

            $first = $tgResults[0] ?? [];

            return [
                'message_id' => $first['message_id'] ?? null,
                'topic_id' => $first['message_thread_id'] ?? null,
                'chat_id' => $first['chat_id'] ?? null,
                'results' => $tgResults,
            ];
        }

        $fixture = (array) ($entry->fixture_data ?? []);
        $home = (string) ($fixture['home_team'] ?? '');
        $away = (string) ($fixture['away_team'] ?? '');
        $time = (string) ($fixture['time'] ?? 'TBC');
        $league = (string) ($fixture['league'] ?? 'Competition TBC');
        $tvChannel = (string) ($fixture['tv_channel'] ?? 'Not listed');
        $eventName = (string) ($fixture['event_name'] ?? '');

        $title = $home !== '' && $away !== ''
            ? "{$home} vs {$away}"
            : ($eventName !== '' ? $eventName : "Fixture {$entry->event_id}");

        $text = implode("\n", [
            '🕐 ' . $time . ' — ' . $title,
            '🏆 ' . $league,
            '📺 UK TV: ' . $tvChannel,
        ]);

        $tgResults = $this->notifier->send($text, $notifyOptions);
        $first = $tgResults[0] ?? [];

        return [
            'message_id' => $first['message_id'] ?? null,
            'topic_id' => $first['message_thread_id'] ?? null,
            'chat_id' => $first['chat_id'] ?? null,
            'results' => $tgResults,
        ];
    }

    private function cacheAssets(SportsBotFixtureQueue $entry, array $fixture): void
    {
        if ($entry->asset_status === SportsBotFixtureQueue::ASSET_CACHED) {
            return;
        }

        $entry->asset_status = SportsBotFixtureQueue::ASSET_CACHED;
        $entry->save();
    }

    private function resolvePublishDate(array $fixture, array $config): string
    {
        $fixtureDate = $fixture['publish_date']
            ?? $fixture['date']
            ?? $fixture['date_label']
            ?? $fixture['dateEvent']
            ?? null;

        if ($fixtureDate instanceof \Carbon\CarbonImmutable) {
            return $fixtureDate->toDateString();
        }

        if (is_string($fixtureDate) && $fixtureDate !== '') {
            try {
                return Carbon::parse($fixtureDate)->toDateString();
            } catch (Throwable) {
            }
        }

        if (isset($fixture['kickoff_at']) && $fixture['kickoff_at'] instanceof \Carbon\CarbonImmutable) {
            return $fixture['kickoff_at']->toDateString();
        }

        return Carbon::today()->toDateString();
    }

    private function computeHash(array $fixture): string
    {
        return sha1(json_encode([
            'sport' => $fixture['sport'] ?? '',
            'league' => $fixture['league'] ?? '',
            'home_team' => $fixture['home_team'] ?? '',
            'away_team' => $fixture['away_team'] ?? '',
            'event_name' => $fixture['event_name'] ?? '',
            'time' => $fixture['time'] ?? '',
            'date_label' => $fixture['date_label'] ?? '',
            'kickoff_label' => $fixture['kickoff_label'] ?? '',
            'tv_channel' => $fixture['tv_channel'] ?? '',
            'tv_channels' => $fixture['tv_channels'] ?? [],
            'venue' => $fixture['venue'] ?? '',
            'home_badge' => $fixture['home_badge'] ?? '',
            'away_badge' => $fixture['away_badge'] ?? '',
            'event_thumb' => $fixture['event_thumb'] ?? '',
            'event_poster' => $fixture['event_poster'] ?? '',
            'league_badge' => $fixture['league_badge'] ?? '',
            'league_logo' => $fixture['league_logo'] ?? '',
            'league_id' => $fixture['league_id'] ?? '',
            'home_team_id' => $fixture['home_team_id'] ?? '',
            'away_team_id' => $fixture['away_team_id'] ?? '',
        ]));
    }

    private function buildCaption(array $fixture, array $config): string
    {
        $formatter = (string) ($config['caption_formatter'] ?? 'generic');

        return match ($formatter) {
            'combat' => $this->combatCaption($fixture),
            default => $this->otherChannelsCaption($fixture),
        };
    }

    private function combatCaption(array $fixture): string
    {
        $title = trim((string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? 'Fight event'));
        if ($title === '') {
            $home = trim((string) ($fixture['home_team'] ?? $fixture['strHomeTeam'] ?? ''));
            $away = trim((string) ($fixture['away_team'] ?? $fixture['strAwayTeam'] ?? ''));
            $title = trim($home . ($home !== '' && $away !== '' ? ' vs ' : '') . $away);
        }

        $date = trim((string) ($fixture['date_label'] ?? $fixture['dateEvent'] ?? 'Date TBC'));
        $time = trim((string) ($fixture['kickoff_label'] ?? $fixture['time'] ?? 'Time TBC'));

        return mb_substr(implode("\n", [
            $title !== '' ? $title : 'Fight event',
            trim($date . ' ' . $time),
            '',
            'PPV: Check the PPV folders for this event.',
        ]), 0, 1000);
    }

    private function otherChannelsCaption(array $fixture): string
    {
        $primary = $this->normalizeChannel((string) ($fixture['tv_channel'] ?? ''));
        $channels = [];

        foreach ((array) ($fixture['tv_channels'] ?? []) as $channel) {
            $label = $this->normalizeChannel((string) $channel);
            if ($label === '' || strcasecmp($label, $primary) === 0) {
                continue;
            }

            $channels[strtolower($label)] = $label;
        }

        if ($channels === []) {
            return '';
        }

        return mb_substr('Other UK channels: ' . implode(', ', array_values($channels)), 0, 1000);
    }

    private function normalizeChannel(string $channel): string
    {
        return trim(preg_replace('/\s+/', ' ', $channel) ?? $channel);
    }

    private function flattenFixtures(array $summary): array
    {
        $fixtures = [];

        foreach ((array) ($summary['grouped'] ?? []) as $rows) {
            foreach ((array) $rows as $fixture) {
                if (is_array($fixture)) {
                    $fixtures[] = $fixture;
                }
            }
        }

        return $fixtures;
    }
}
