<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\ScraperProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\Scrapers\BroadcastScheduleScraper;
use App\Plugins\SportsBot\Services\Scrapers\CombatPosterScraper;
use App\Plugins\SportsBot\Services\Scrapers\F1ScheduleScraper;
use App\Plugins\SportsBot\Services\Scrapers\RugbyLeagueTvScraper;
use Illuminate\Support\Facades\Log;
use Throwable;

class SportsBotScraperService
{
    /**
     * @var array<int, ScraperProviderInterface>
     */
    private array $providers;

    /**
     * @param array<int, ScraperProviderInterface>|null $providers
     */
    public function __construct(
        ?array $providers = null,
        private readonly ScraperResultNormalizer $normalizer = new ScraperResultNormalizer(),
    ) {
        $this->providers = $providers ?? [
            new CombatPosterScraper($this->normalizer),
            new BroadcastScheduleScraper($this->normalizer),
            new F1ScheduleScraper($this->normalizer),
            new RugbyLeagueTvScraper($this->normalizer),
        ];
    }

    public function findPoster(SportsBotFixtureQueue $entry): array
    {
        return $this->scrape($entry, 'find_poster');
    }

    public function findTvInfo(SportsBotFixtureQueue $entry): array
    {
        return $this->scrape($entry, 'find_tv_info');
    }

    public function refresh(SportsBotFixtureQueue $entry): array
    {
        return $this->scrape($entry, 'refresh');
    }

    public function accept(SportsBotFixtureQueue $entry, string $source = 'admin_api'): array
    {
        $payload = (array) ($entry->payload ?? []);
        $normalized = (array) ($payload['scraper']['normalized'] ?? []);
        $fields = $this->normalizer->sanitizeFields((array) ($normalized['fields'] ?? []));

        if ($fields === []) {
            return ['accepted' => false, 'error' => 'No scraped fields available to accept'];
        }

        if (!$this->hasAnyField($fields, ['event_poster', 'tv_channel', 'tv_channels', 'f1_sessions', 'date_label', 'kickoff_label', 'time'])) {
            return ['accepted' => false, 'error' => 'No useful media, TV, or schedule fields were found to accept'];
        }

        $action = (string) ($payload['scraper']['action'] ?? '');
        if ($action === 'find_tv_info' && !$this->hasAnyField($fields, ['tv_channel', 'tv_channels'])) {
            return ['accepted' => false, 'error' => 'No TV channel fields were found to accept'];
        }

        if ($action === 'find_poster' && !$this->hasAnyField($fields, ['event_poster'])) {
            return ['accepted' => false, 'error' => 'No poster image field was found to accept'];
        }

        $payload['accepted_scraped_data'] = [
            'fields' => $fields,
            'confidence' => (float) ($normalized['confidence'] ?? 0.0),
            'source_urls' => (array) ($normalized['source_urls'] ?? []),
            'accepted_at' => now()->toISOString(),
            'accepted_by' => $source,
        ];
        unset($payload['rejected_scraped_data']);

        $entry->payload = $payload;
        $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
        $entry->card_path = null;
        $entry->caption = null;
        $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
        $entry->save();

        Log::info('sportsbot.scraper.accepted', [
            'queue_id' => $entry->id,
            'event_id' => $entry->event_id,
            'confidence' => (float) ($normalized['confidence'] ?? 0.0),
            'fields_found' => array_keys($fields),
            'source_urls' => (array) ($normalized['source_urls'] ?? []),
        ]);

        return ['accepted' => true, 'id' => $entry->id, 'fields' => $fields];
    }

    public function reject(SportsBotFixtureQueue $entry, string $source = 'admin_api'): array
    {
        $payload = (array) ($entry->payload ?? []);
        $payload['rejected_scraped_data'] = [
            'rejected_at' => now()->toISOString(),
            'rejected_by' => $source,
            'normalized' => $payload['scraper']['normalized'] ?? null,
        ];
        unset($payload['accepted_scraped_data']);

        $entry->payload = $payload;
        $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
        $entry->card_path = null;
        $entry->caption = null;
        $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
        $entry->save();

        Log::info('sportsbot.scraper.rejected', [
            'queue_id' => $entry->id,
            'event_id' => $entry->event_id,
        ]);

        return ['rejected' => true, 'id' => $entry->id];
    }

    public function autoUseConfidenceThreshold(): float
    {
        return max(0.0, min(1.0, (float) $this->scraperConfig('auto_use_confidence', 0.9)));
    }

    private function scrape(SportsBotFixtureQueue $entry, string $action): array
    {
        if (!(bool) $this->scraperConfig('enabled', true)) {
            return ['scraped' => false, 'id' => $entry->id, 'action' => $action, 'error' => 'Scraper enrichment is disabled'];
        }

        $providerResults = [];
        $logs = [];
        $errors = [];

        foreach ($this->providers as $provider) {
            if (!$provider->supports($entry, $action)) {
                continue;
            }

            try {
                $result = $provider->scrape($entry, $action);
                foreach ((array) ($result['results'] ?? []) as $row) {
                    if (is_array($row)) {
                        $providerResults[] = $row;
                    }
                }
                foreach ((array) ($result['logs'] ?? []) as $log) {
                    if (is_array($log)) {
                        $logs[] = array_merge(['provider' => $provider->key()], $log);
                    }
                }
            } catch (Throwable $error) {
                $errors[] = [
                    'provider' => $provider->key(),
                    'error' => $error->getMessage(),
                    'checked_at' => now()->toISOString(),
                ];
            }
        }

        $normalized = $this->normalizer->normalize($providerResults);
        $payload = (array) ($entry->payload ?? []);
        $payload['scraper'] = [
            'action' => $action,
            'status' => ($normalized['fields'] ?? []) !== [] ? 'found' : ($errors !== [] ? 'error' : 'none'),
            'last_checked_at' => now()->toISOString(),
            'normalized' => $normalized,
            'results' => $providerResults,
            'logs' => array_slice(array_merge((array) ($payload['scraper']['logs'] ?? []), $logs, $errors), -50),
            'errors' => $errors,
            'auto_use_confidence' => $this->autoUseConfidenceThreshold(),
        ];

        $entry->payload = $payload;

        $confidence = (float) ($normalized['confidence'] ?? 0.0);
        $fields = (array) ($normalized['fields'] ?? []);
        if ($fields !== [] && $confidence >= $this->autoUseConfidenceThreshold()) {
            $entry->status = SportsBotFixtureQueue::STATUS_DRAFT;
            $entry->card_path = null;
            $entry->caption = null;
            $entry->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
        }

        $entry->save();

        Log::info('sportsbot.scraper.checked', [
            'queue_id' => $entry->id,
            'event_id' => $entry->event_id,
            'action' => $action,
            'source_urls' => (array) ($normalized['source_urls'] ?? []),
            'confidence' => (float) ($normalized['confidence'] ?? 0.0),
            'fields_found' => (array) ($normalized['fields_found'] ?? []),
            'errors' => $errors,
            'last_checked_at' => $payload['scraper']['last_checked_at'],
        ]);

        return [
            'scraped' => true,
            'id' => $entry->id,
            'action' => $action,
            'normalized' => $normalized,
            'logs' => $payload['scraper']['logs'],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, string> $keys
     */
    private function hasAnyField(array $fields, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $fields[$key] ?? null;
            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function scraperConfig(string $key, mixed $default = null): mixed
    {
        return (new SportsBotSettingsService())->get(
            'scraper_' . $key,
            config('plugins.SportsBot.scrapers.' . $key, $default)
        );
    }
}
