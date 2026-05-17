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
        private readonly SportsBotScraperService $scrapers = new SportsBotScraperService(),
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
            $fixture = $this->effectiveFixtureData($entry);

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

            $providerFixture = (array) ($entry->fixture_data ?? []);
            $currentHash = $this->computeHash($providerFixture);

            foreach ($freshData as $key => $value) {
                $providerFixture[$key] = $value;
            }

            $newHash = $this->computeHash($providerFixture);

            if ($currentHash === $newHash) {
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

            $cardVersion = (string) ($config['default_card_version'] ?? 'v1');
            $fixture = $this->effectiveFixtureData($entry);

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
        $fixture = $this->effectiveFixtureData($entry);
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
                    'time' => (string) ($fixture['time'] ?? ''),
                    'league' => (string) ($fixture['league'] ?? ''),
                    'home_team' => (string) ($fixture['home_team'] ?? ''),
                    'away_team' => (string) ($fixture['away_team'] ?? ''),
                    'tv_channel' => (string) ($fixture['tv_channel'] ?? ''),
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

    public function find(int $id): ?SportsBotFixtureQueue
    {
        return SportsBotFixtureQueue::query()->find($id);
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
        $cardVersion = (string) ($config['default_card_version'] ?? 'v1');

        try {
            $this->cacheAssets($entry, $fixture);

            $card = $this->cards->fixtureCard($fixture, $cardVersion);
            $cardPath = (string) ($card['path'] ?? '');

            if ($cardPath === '' || !is_file($cardPath)) {
                throw new \RuntimeException('Card render returned no valid file');
            }

            $entry->card_path = $cardPath;
            $entry->caption = $this->buildCaption($fixture, $config);
            $entry->status = SportsBotFixtureQueue::STATUS_READY;
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

    public function publishNow(int $id): array
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

        if ($entry->status !== SportsBotFixtureQueue::STATUS_READY) {
            $result = $this->reRenderItem($id);
            if (!($result['re_rendered'] ?? false)) {
                return ['published' => false, 'error' => $result['error'] ?? 'Cannot publish item'];
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
            $entry->payload = array_merge((array) $entry->payload, ['publish_results' => $results]);
            $entry->save();

            return ['published' => true, 'id' => $id, 'results' => $results];
        } catch (Throwable $error) {
            $entry->status = SportsBotFixtureQueue::STATUS_FAILED;
            $entry->error = mb_substr($error->getMessage(), 0, 1000);
            $entry->save();

            return ['published' => false, 'id' => $id, 'error' => $error->getMessage()];
        }
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

        return array_merge($this->scrapers->findPoster($entry), ['item' => $entry->fresh()?->toArray()]);
    }

    public function findTvInfo(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        return array_merge($this->scrapers->findTvInfo($entry), ['item' => $entry->fresh()?->toArray()]);
    }

    public function refreshScrapedData(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        return array_merge($this->scrapers->refresh($entry), ['item' => $entry->fresh()?->toArray()]);
    }

    public function acceptScrapedData(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        return array_merge($this->scrapers->accept($entry), ['item' => $entry->fresh()?->toArray()]);
    }

    public function rejectScrapedData(int $id): array
    {
        $entry = $this->find($id);
        if (!$entry) {
            return ['error' => "Queue item {$id} not found"];
        }

        return array_merge($this->scrapers->reject($entry), ['item' => $entry->fresh()?->toArray()]);
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
            if (!$this->emptyFixtureValue($fixture[$field] ?? null)) {
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
            if (!$this->scrapedFieldAllowed((string) $field) || $this->emptyFixtureValue($value)) {
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
            if (!$this->scrapedFieldAllowed($field) || $this->emptyFixtureValue($value)) {
                continue;
            }

            if (!$this->emptyFixtureValue($fixture[$field] ?? null)) {
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
