<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use App\Plugins\SportsBot\Support\SportsBotSports;
use App\Plugins\SportsBot\Support\SportsBotPaths;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class FixtureQueueService
{
    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly FixturesTodayService $fixturesService = new FixturesTodayService(),
        private readonly SportsBotCardRenderer $cards = new SportsBotCardRenderer(),
        private readonly SportsBotNotifier $notifier = new SportsBotNotifier(),
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
        private readonly SportsBotScraperService $scrapers = new SportsBotScraperService(),
        private readonly SportsBotAssetCache $assets = new SportsBotAssetCache(),
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
                    $routeKey = (string) ($config['topic_key'] ?? '');
                    if ($routeKey !== '' && $existing->route_key !== $routeKey) {
                        $existing->route_key = $routeKey;
                        $existing->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $existing->fill([
                    'fixture_data' => $fixture,
                    'route_key' => $config['topic_key'] ?? null,
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
        $cardVersion = $this->desiredCardVersion($sportKey, $config);
        $rendered = 0;
        $skipped = 0;
        $failed = 0;

        $drafts = SportsBotFixtureQueue::query()
            ->bySport($sportKey)
            ->where('publish_date', '<=', $maxDate->toDateString())
            ->whereIn('status', [SportsBotFixtureQueue::STATUS_DRAFT, SportsBotFixtureQueue::STATUS_READY])
            ->get();

        foreach ($drafts as $entry) {
            $fixture = $this->effectiveFixtureData($entry);

            if ($entry->status === SportsBotFixtureQueue::STATUS_READY && $this->hasCurrentCard($entry, $cardVersion)) {
                $this->syncRouteKey($entry, $config);
                $skipped++;
                continue;
            }

            try {
                $this->renderEntryCard($entry, $config, $cardVersion);
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
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $sent = 0;
        $wouldSend = 0;
        $rendered = 0;
        $skipped = 0;
        $failed = 0;

        $items = SportsBotFixtureQueue::query()
            ->bySport($sportKey)
            ->where('publish_date', $today)
            ->whereIn('status', [SportsBotFixtureQueue::STATUS_READY, SportsBotFixtureQueue::STATUS_DRAFT])
            ->get();

        foreach ($items as $entry) {
            if ($entry->sent_at !== null || $entry->telegram_message_id !== null) {
                $skipped++;
                continue;
            }

            if ($entry->status !== SportsBotFixtureQueue::STATUS_READY) {
                if ($dryRun) {
                    $skipped++;
                    continue;
                }

                try {
                    $this->renderEntryCard($entry, $config, $this->desiredCardVersion($sportKey, $config));
                    $entry = $entry->fresh() ?? $entry;
                    $rendered++;
                } catch (Throwable $error) {
                    $entry->status = SportsBotFixtureQueue::STATUS_FAILED;
                    $entry->error = mb_substr($error->getMessage(), 0, 1000);
                    $entry->save();

                    Log::error('sportsbot.fixture_queue.publish_render_failed', [
                        'sport' => $sportKey,
                        'event_id' => $entry->event_id,
                        'error' => $error->getMessage(),
                    ]);
                    $failed++;
                    continue;
                }
            }

            try {
                if ($dryRun) {
                    if ($this->hasCurrentCard($entry, $this->desiredCardVersion($sportKey, $config))) {
                        $wouldSend++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

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
            'would_send' => $wouldSend,
            'rendered' => $rendered,
            'skipped' => $skipped,
            'failed' => $failed,
            'dry_run' => $dryRun,
        ]);

        return [
            'sport' => $sportKey,
            'sent' => $sent,
            'would_send' => $wouldSend,
            'rendered' => $rendered,
            'skipped' => $skipped,
            'failed' => $failed,
            'dry_run' => $dryRun,
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
            $cardVersion = $this->desiredCardVersion((string) $entry->sport_key, $config);

            if ($freshData === null || !is_array($freshData)) {
                Log::warning('sportsbot.fixture_queue.verify_provider_unavailable', [
                    'event_id' => $entry->event_id,
                    'sport' => $entry->sport_key,
                ]);

                return $this->hasCurrentCard($entry, $cardVersion);
            }

            $providerFixture = (array) ($entry->fixture_data ?? []);
            $currentHash = $this->computeHash($providerFixture);

            foreach ($freshData as $key => $value) {
                $providerFixture[$key] = $value;
            }

            $newHash = $this->computeHash($providerFixture);

            if ($currentHash === $newHash) {
                if ($this->hasCurrentCard($entry, $cardVersion)) {
                    return true;
                }

                $this->renderEntryCard($entry, $config, $cardVersion);

                return true;
            }

            Log::info('sportsbot.fixture_queue.event_changed', [
                'event_id' => $entry->event_id,
                'sport' => $entry->sport_key,
            ]);

            $entry->fixture_data = $providerFixture;
            $entry->payload_hash = $newHash;
            $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
            $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
            $entry->card_path = null;
            $entry->last_refreshed_at = now();
            $entry->save();

            $this->renderEntryCard($entry, $config, $cardVersion);

            return true;
        } catch (Throwable $error) {
            Log::warning('sportsbot.fixture_queue.verify_failed', [
                'event_id' => $entry->event_id,
                'error' => $error->getMessage(),
            ]);

            return $this->hasCurrentCard($entry, $this->desiredCardVersion((string) $entry->sport_key, $config));
        }
    }

    private function desiredCardVersion(string $sportKey, array $config): string
    {
        $version = strtolower(trim((string) $this->settings->get(
            $sportKey . '_fixture_card_version',
            $config['default_card_version'] ?? 'v3'
        )));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }

    private function hasCurrentCard(SportsBotFixtureQueue $entry, string $cardVersion): bool
    {
        $cardPath = SportsBotPaths::cardPath((string) ($entry->card_path ?? ''));
        if ($cardPath === '' || !@is_file($cardPath)) {
            return false;
        }

        $this->syncCardPath($entry, $cardPath);

        $file = basename($cardPath);
        if ($cardVersion === 'v3') {
            return str_starts_with($file, 'fixture-v3-') || str_starts_with($file, 'fixture-v3-browser-');
        }

        return str_starts_with($file, 'fixture-' . $cardVersion . '-');
    }

    private function renderEntryCard(SportsBotFixtureQueue $entry, array $config, string $cardVersion): void
    {
        $fixture = $this->effectiveFixtureData($entry);
        $cardVersion = (string) ($this->renderOptions($entry)['card_version'] ?? $cardVersion);

        $assetResult = $this->cacheAssets($entry, $fixture);
        $fixture = (array) ($assetResult['fixture'] ?? $fixture);

        $card = $this->cards->fixtureCard($fixture, $cardVersion, [
            'route_key' => $this->routeKeyForEntry($entry, $config),
            'target' => 'telegram',
            ...$this->renderOptions($entry),
        ]);
        $cardPath = (string) ($card['path'] ?? '');

        if ($cardPath === '' || !@is_file($cardPath)) {
            throw new \RuntimeException('Card render returned no valid file path');
        }

        $entry->card_path = $cardPath;
        $this->applyRenderMetadata($entry, $card, $assetResult);
        $entry->caption = $this->buildCaption($fixture, $config);
        $entry->route_key = $config['topic_key'] ?? $entry->route_key;
        $entry->status = SportsBotFixtureQueue::STATUS_READY;
        $entry->error = null;
        $entry->save();
    }

    private function syncRouteKey(SportsBotFixtureQueue $entry, array $config): void
    {
        $routeKey = (string) ($config['topic_key'] ?? '');
        if ($routeKey === '' || $entry->route_key === $routeKey) {
            return;
        }

        $entry->route_key = $routeKey;
        $entry->save();
    }

    private function sendToTelegram(SportsBotFixtureQueue $entry, array $config, array $options): array
    {
        $routeKey = $this->routeKeyForEntry($entry, $config);
        $fixture = $this->effectiveFixtureData($entry);
        $caption = (string) ($entry->caption ?? '');
        $cardPath = SportsBotPaths::cardPath((string) ($entry->card_path ?? ''));

        $notifyOptions = [
            'route_key' => $routeKey,
            'type' => strtoupper($entry->sport_key) . '_FIXTURES',
            'payload' => [
                'source' => 'fixture_queue',
                'content_key' => strtoupper($entry->sport_key) . '_FIXTURES',
                'event_id' => $entry->event_id,
                'fixture_queue_id' => $entry->id,
                'fixture' => [
                    'time' => (string) ($fixture['time'] ?? ''),
                    'league' => (string) ($fixture['league'] ?? ''),
                    'home_team' => (string) ($fixture['home_team'] ?? ''),
                    'away_team' => (string) ($fixture['away_team'] ?? ''),
                    'tv_channel' => (string) ($fixture['tv_channel'] ?? ''),
                ],
            ],
        ];

        if ($cardPath !== '' && @is_file($cardPath)) {
            $this->syncCardPath($entry, $cardPath);
            $sendResults = $this->notifier->sendPhoto($cardPath, $caption, $notifyOptions);

            $telegramResult = $this->firstDeliveryResult($sendResults, 'telegram');

            return [
                'message_id' => $telegramResult['message_id'] ?? null,
                'topic_id' => $telegramResult['message_thread_id'] ?? null,
                'chat_id' => $telegramResult['chat_id'] ?? null,
                'results' => $sendResults,
            ];
        }

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

        $sendResults = $this->notifier->send($text, $notifyOptions);
        $telegramResult = $this->firstDeliveryResult($sendResults, 'telegram');

        return [
            'message_id' => $telegramResult['message_id'] ?? null,
            'topic_id' => $telegramResult['message_thread_id'] ?? null,
            'chat_id' => $telegramResult['chat_id'] ?? null,
            'results' => $sendResults,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function firstDeliveryResult(array $results, string $platform): array
    {
        foreach ($results as $result) {
            if (($result['platform'] ?? null) === $platform) {
                return $result;
            }
        }

        return [];
    }

    private function routeKeyForEntry(SportsBotFixtureQueue $entry, array $config): string
    {
        $configuredRoute = (string) ($config['topic_key'] ?? '');
        $storedRoute = (string) ($entry->route_key ?? '');

        if ($configuredRoute === TelegramRouteKeys::USA_SPORTS && in_array($storedRoute, [
            TelegramRouteKeys::BASKETBALL,
            TelegramRouteKeys::BASEBALL,
            TelegramRouteKeys::AMERICAN_FOOTBALL,
            TelegramRouteKeys::ICE_HOCKEY,
        ], true)) {
            return $configuredRoute;
        }

        if ($configuredRoute === TelegramRouteKeys::OTHER_SPORTS && in_array($storedRoute, [
            TelegramRouteKeys::TENNIS,
            TelegramRouteKeys::CRICKET,
            TelegramRouteKeys::GOLF,
        ], true)) {
            return $configuredRoute;
        }

        return $storedRoute !== '' ? $storedRoute : ($configuredRoute !== '' ? $configuredRoute : TelegramRouteKeys::DEFAULT);
    }

    public function find(int $id): ?SportsBotFixtureQueue
    {
        return SportsBotFixtureQueue::query()->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function itemData(SportsBotFixtureQueue $entry): array
    {
        $data = $entry->toArray();
        $data['raw_fixture_data'] = $data['fixture_data'] ?? [];
        $data['fixture_data'] = $this->effectiveFixtureData($entry);
        $data['card_path'] = SportsBotPaths::cardPath((string) ($entry->card_path ?? ''));

        return $data;
    }

    public function reRenderItem(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $sportKey = $entry->sport_key;
        $config = SportsFixtureConfig::for($sportKey);
        if (!$config) {
            return ['error' => "Unknown sport: {$sportKey}"];
        }

        $fixture = $this->effectiveFixtureData($entry);
        $cardVersion = $this->desiredCardVersion($sportKey, $config);
        $cardVersion = (string) ($this->renderOptions($entry)['card_version'] ?? $cardVersion);
        $preserveSent = $entry->status === SportsBotFixtureQueue::STATUS_SENT
            || $entry->sent_at !== null
            || $entry->telegram_message_id !== null;

        try {
        $assetResult = $this->cacheAssets($entry, $fixture);
        $fixture = (array) ($assetResult['fixture'] ?? $fixture);

            $card = $this->cards->fixtureCard($fixture, $cardVersion, [
                'route_key' => $this->routeKeyForEntry($entry, $config),
                'target' => 'telegram',
                ...$this->renderOptions($entry),
            ]);
            $cardPath = (string) ($card['path'] ?? '');

            if ($cardPath === '' || !@is_file($cardPath)) {
                throw new \RuntimeException('Card render returned no valid file');
            }

            $entry->card_path = $cardPath;
            $this->applyRenderMetadata($entry, $card, $assetResult);
            $entry->caption = $this->buildCaption($fixture, $config);
            $entry->route_key = $config['topic_key'] ?? $entry->route_key;
            $entry->status = $preserveSent ? SportsBotFixtureQueue::STATUS_SENT : SportsBotFixtureQueue::STATUS_READY;
            $entry->error = null;
            $entry->save();

            return ['re_rendered' => true, 'id' => $id, 'card_path' => $cardPath];
        } catch (Throwable $error) {
            $entry->status = SportsBotFixtureQueue::STATUS_FAILED;
            $entry->error = mb_substr($error->getMessage(), 0, 1000);
            $entry->save();

            return ['re_rendered' => false, 'id' => $id, 'error' => $error->getMessage()];
        }
    }

    public function publishNow(int $id, array $options = []): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $force = (bool) ($options['force'] ?? false);
        $alreadySent = $entry->status === SportsBotFixtureQueue::STATUS_SENT
            || $entry->sent_at !== null
            || $entry->telegram_message_id !== null;

        if ($alreadySent && !$force) {
            return ['published' => false, 'id' => $id, 'already_sent' => true, 'message_id' => $entry->telegram_message_id];
        }

        $sportKey = $entry->sport_key;
        $config = SportsFixtureConfig::for($sportKey);
        if (!$config) {
            return ['error' => "Unknown sport: {$sportKey}"];
        }

        if ($entry->status !== SportsBotFixtureQueue::STATUS_READY && !$alreadySent) {
            $result = $this->reRenderItem($id);
            if (!($result['re_rendered'] ?? false)) {
                return ['published' => false, 'error' => $result['error'] ?? 'Cannot publish item'];
            }

            $entry = $this->find($id);
        }

        if ($alreadySent && !$this->hasCurrentCard($entry, $this->desiredCardVersion($sportKey, $config))) {
            $result = $this->reRenderItem($id);
            if (!($result['re_rendered'] ?? false)) {
                return ['published' => false, 'error' => $result['error'] ?? 'Cannot re-render sent item for resend'];
            }

            $entry = $this->find($id);
        }

        try {
            $verified = $this->verifyBeforePublish($entry, $config);
            if (!$verified) {
                return ['published' => false, 'error' => 'Event verification failed'];
            }

            $results = $this->sendToTelegram($entry, $config, []);

            $entry->status = SportsBotFixtureQueue::STATUS_SENT;
            $entry->sent_at = now();
            $entry->telegram_message_id = $results['message_id'] ?? null;
            $entry->topic_id = $results['topic_id'] ?? null;
            $payloadKey = $alreadySent && $force ? 'republish_results' : 'publish_results';
            $entry->payload = array_merge((array) $entry->payload, [$payloadKey => $results]);
            $entry->save();

            return ['published' => true, 'resent' => $alreadySent && $force, 'id' => $id, 'results' => $results];
        } catch (Throwable $error) {
            $entry->status = SportsBotFixtureQueue::STATUS_FAILED;
            $entry->error = mb_substr($error->getMessage(), 0, 1000);
            $entry->save();

            return ['published' => false, 'id' => $id, 'error' => $error->getMessage()];
        }
    }

    public function updateRenderOptions(int $id, array $options): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['updated' => false, 'error' => "Queue item {$id} not found"];
        }

        $allowed = array_filter([
            'template' => isset($options['template']) ? trim((string) $options['template']) : null,
            'theme' => isset($options['theme']) ? trim((string) $options['theme']) : null,
            'card_version' => isset($options['card_version']) ? trim((string) $options['card_version']) : null,
            'manual_text' => isset($options['manual_text']) ? trim((string) $options['manual_text']) : null,
            'custom_poster_url' => isset($options['custom_poster_url']) ? trim((string) $options['custom_poster_url']) : null,
            'custom_background_url' => isset($options['custom_background_url']) ? trim((string) $options['custom_background_url']) : null,
        ], fn ($value) => $value !== null && $value !== '');

        $payload = (array) $entry->payload;
        $payload['render_options'] = array_merge((array) ($payload['render_options'] ?? []), $allowed);

        $fixture = (array) ($entry->fixture_data ?? []);
        if (isset($allowed['manual_text'])) {
            $fixture['manual_text_override'] = $allowed['manual_text'];
        }
        if (isset($allowed['custom_poster_url'])) {
            $fixture['event_poster'] = $allowed['custom_poster_url'];
        }
        if (isset($allowed['custom_background_url'])) {
            $fixture['background_image'] = $allowed['custom_background_url'];
        }

        $entry->payload = $payload;
        $entry->fixture_data = $fixture;
        $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
        $entry->status = $entry->status === SportsBotFixtureQueue::STATUS_SENT ? $entry->status : SportsBotFixtureQueue::STATUS_DRAFT;
        $entry->save();

        return ['updated' => true, 'id' => $id, 'render_options' => $payload['render_options']];
    }

    public function skipItem(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $entry->status = SportsBotFixtureQueue::STATUS_SKIPPED;
        $entry->save();

        return ['skipped' => true, 'id' => $id];
    }

    public function deleteItem(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $entry->delete();

        return ['deleted' => true, 'id' => $id];
    }

    public function findPoster(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $result = $this->scrapers->findPoster($entry);
        $fresh = $entry->fresh();

        return array_merge($result, ['item' => $fresh ? $this->itemData($fresh) : null]);
    }

    public function findTvInfo(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $wasSent = $entry->status === SportsBotFixtureQueue::STATUS_SENT
            || $entry->sent_at !== null
            || $entry->telegram_message_id !== null;

        $result = $this->scrapers->findTvInfo($entry);
        if ($this->scrapeResultShouldAutoRender($result, ['tv_channel', 'tv_channels'])) {
            $result['render'] = $this->reRenderItem($id);
            if ($wasSent) {
                $this->restoreSentStatus($id);
            }
        }

        $fresh = $entry->fresh();

        return array_merge($result, ['item' => $fresh ? $this->itemData($fresh) : null]);
    }

    public function refreshScrapedData(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $result = $this->scrapers->refresh($entry);
        $fresh = $entry->fresh();

        return array_merge($result, ['item' => $fresh ? $this->itemData($fresh) : null]);
    }

    public function acceptScrapedData(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $wasSent = $entry->status === SportsBotFixtureQueue::STATUS_SENT
            || $entry->sent_at !== null
            || $entry->telegram_message_id !== null;

        $result = $this->scrapers->accept($entry);
        if (($result['accepted'] ?? false) === true) {
            $result['render'] = $this->reRenderItem($id);
            if ($wasSent) {
                $this->restoreSentStatus($id);
            }
        }
        $fresh = $entry->fresh();

        return array_merge($result, ['item' => $fresh ? $this->itemData($fresh) : null]);
    }

    public function rejectScrapedData(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        $result = $this->scrapers->reject($entry);
        $fresh = $entry->fresh();

        return array_merge($result, ['item' => $fresh ? $this->itemData($fresh) : null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheAssets(SportsBotFixtureQueue $entry, array $fixture): array
    {
        $result = $this->assets->cacheFixtureAssets($fixture);
        $failures = (array) ($result['failures'] ?? []);
        $entry->asset_status = $failures === [] ? SportsBotFixtureQueue::ASSET_CACHED : SportsBotFixtureQueue::ASSET_FAILED;
        $entry->asset_failures = $failures;
        $entry->payload = array_merge((array) $entry->payload, [
            'asset_cache' => [
                'summary' => $result['summary'] ?? [],
                'assets' => $result['assets'] ?? [],
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        $entry->save();

        if ($failures !== []) {
            Log::warning('sportsbot.fixture_queue.asset_cache_failed', [
                'event_id' => $entry->event_id,
                'sport' => $entry->sport_key,
                'failures' => $failures,
            ]);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderOptions(SportsBotFixtureQueue $entry): array
    {
        $payload = (array) $entry->payload;

        return (array) ($payload['render_options'] ?? []);
    }

    /**
     * @param array<string, mixed> $card
     * @param array<string, mixed> $assetResult
     */
    private function applyRenderMetadata(SportsBotFixtureQueue $entry, array $card, array $assetResult): void
    {
        $entry->renderer_used = (string) ($card['renderer_used'] ?? $card['type'] ?? '');
        $entry->render_duration_ms = isset($card['render_duration_ms']) ? (int) $card['render_duration_ms'] : null;
        $entry->template_used = (string) ($card['template_used'] ?? '');
        $entry->theme_used = (string) ($card['theme_used'] ?? '');
        $entry->fallback_reason = $card['fallback_reason'] ?? null;
        $entry->browser_failure_reason = $card['browser_failure_reason'] ?? null;
        $entry->asset_failures = (array) ($assetResult['failures'] ?? []);
        $entry->render_diagnostics = array_merge((array) ($card['render_diagnostics'] ?? []), [
            'output' => $card['output'] ?? [],
            'video_ready' => $card['video_ready'] ?? [],
            'asset_summary' => $assetResult['summary'] ?? [],
            'asset_sources' => array_map(
                static fn (array $asset): array => [
                    'field' => (string) ($asset['field'] ?? ''),
                    'type' => (string) ($asset['type'] ?? ''),
                    'render_source' => (string) ($asset['render_source'] ?? 'missing'),
                    'mime_type' => $asset['mime_type'] ?? null,
                    'local_path' => $asset['local_path'] ?? null,
                    'source_url' => $asset['source_url'] ?? null,
                ],
                (array) ($assetResult['assets'] ?? [])
            ),
        ]);
        $payload = (array) $entry->payload;
        $history = (array) ($payload['render_history'] ?? []);
        $entry->payload = array_merge($payload, [
            'render_history' => array_values(array_slice(array_merge(
                $history,
                [[
                    'renderer' => $entry->renderer_used,
                    'duration_ms' => $entry->render_duration_ms,
                    'template' => $entry->template_used,
                    'theme' => $entry->theme_used,
                    'fallback_reason' => $entry->fallback_reason,
                    'browser_failure_reason' => $entry->browser_failure_reason,
                    'rendered_at' => now()->toIso8601String(),
                ]]
            ), -20)),
        ]);
    }

    private function syncCardPath(SportsBotFixtureQueue $entry, string $cardPath): void
    {
        if ($cardPath === '' || $cardPath === (string) ($entry->card_path ?? '')) {
            return;
        }

        $entry->card_path = $cardPath;
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

    /**
     * Manual override > API provider > accepted scraped data > high-confidence scraped data > generated fallback.
     *
     * @return array<string, mixed>
     */
    private function effectiveFixtureData(SportsBotFixtureQueue $entry): array
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $payload = (array) ($entry->payload ?? []);
        $sources = [];

        foreach (array_keys($fixture) as $field) {
            if (!$this->emptyFixtureFieldValue((string) $field, $fixture[$field] ?? null)) {
                $sources[$field] = 'api_provider';
            }
        }

        if ($this->hasTrustedProviderData($fixture)) {
            $accepted = (array) ($payload['accepted_scraped_data']['fields'] ?? []);
            $this->fillMissingFixtureFields($fixture, $accepted, $sources, 'accepted_scraped_data');

            $normalized = (array) ($payload['scraper']['normalized'] ?? []);
            $confidence = (float) ($normalized['confidence'] ?? 0.0);
            $rejected = isset($payload['rejected_scraped_data']);
            if (!$rejected && $confidence >= $this->scrapers->autoUseConfidenceThreshold()) {
                $this->fillMissingFixtureFields($fixture, (array) ($normalized['fields'] ?? []), $sources, 'high_confidence_scraped_data');
                if (($normalized['fields'] ?? []) !== []) {
                    $fixture['_scraper_auto_used'] = true;
                }
            }
        }

        $manual = (array) ($payload['manual_override']['fields'] ?? []);
        foreach ($manual as $field => $value) {
            if (!$this->scrapedFieldAllowed((string) $field) || $this->emptyFixtureFieldValue((string) $field, $value)) {
                continue;
            }
            $fixture[$field] = $value;
            $sources[$field] = 'manual_override';
        }

        if ($sources !== []) {
            $fixture['_field_sources'] = $sources;
        }

        return $fixture;
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $fields
     * @param array<string, string> $sources
     */
    private function fillMissingFixtureFields(array &$fixture, array $fields, array &$sources, string $source): void
    {
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if (!$this->scrapedFieldAllowed($field) || $this->emptyFixtureFieldValue($field, $value)) {
                continue;
            }

            if (!$this->emptyFixtureFieldValue($field, $fixture[$field] ?? null)) {
                continue;
            }

            $fixture[$field] = $value;
            $sources[$field] = $source;
        }
    }

    private function scrapedFieldAllowed(string $field): bool
    {
        return in_array($field, [
            'event_poster',
            'event_name',
            'home_team',
            'away_team',
            'date_label',
            'kickoff_label',
            'time',
            'venue',
            'tv_channel',
            'tv_channels',
            'f1_sessions',
        ], true);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function hasTrustedProviderData(array $fixture): bool
    {
        return trim((string) ($fixture['event_id'] ?? '')) !== ''
            || trim((string) ($fixture['idEvent'] ?? '')) !== ''
            || trim((string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? '')) !== '';
    }

    private function emptyFixtureValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }

    private function restoreSentStatus(int $id): void
    {
        $entry = $this->find($id);
        if (!$entry || ($entry->sent_at === null && $entry->telegram_message_id === null)) {
            return;
        }

        $entry->status = SportsBotFixtureQueue::STATUS_SENT;
        $entry->save();
    }

    private function emptyFixtureFieldValue(string $field, mixed $value): bool
    {
        if ($this->emptyFixtureValue($value)) {
            return true;
        }

        if ($field === 'tv_channel') {
            return $this->placeholderTvChannel((string) $value);
        }

        if ($field === 'tv_channels' && is_array($value)) {
            foreach ($value as $channel) {
                if (!$this->placeholderTvChannel((string) $channel)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function placeholderTvChannel(string $channel): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $channel) ?? $channel));
        $normalized = trim($normalized, " \t\n\r\0\x0B.:-");

        return in_array($normalized, [
            '',
            'not listed',
            'not shown',
            'not available',
            'no tv',
            'none',
            'unknown',
            'tbc',
            'tv tbc',
            'channel tbc',
            'channels tbc',
            'n/a',
            'na',
            '-',
        ], true);
    }

    /**
     * @param array<int, string> $fields
     */
    private function scrapeResultShouldAutoRender(array $result, array $fields): bool
    {
        $normalized = (array) ($result['normalized'] ?? []);
        $confidence = (float) ($normalized['confidence'] ?? 0.0);
        if ($confidence < $this->scrapers->autoUseConfidenceThreshold()) {
            return false;
        }

        $scrapedFields = (array) ($normalized['fields'] ?? []);
        foreach ($fields as $field) {
            if (!$this->emptyFixtureFieldValue($field, $scrapedFields[$field] ?? null)) {
                return true;
            }
        }

        return false;
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
