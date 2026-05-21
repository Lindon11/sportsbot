<?php

namespace App\Plugins\SportsBot\Services\Scrapers;

use App\Plugins\SportsBot\Contracts\ScraperProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\TheSportsDbClient;

class TheSportsDbTvScraper implements ScraperProviderInterface
{
    public function __construct(
        private readonly TheSportsDbClient $client = new TheSportsDbClient(),
    ) {
    }

    public function key(): string
    {
        return 'thesportsdb_tv';
    }

    public function supports(SportsBotFixtureQueue $entry, string $action): bool
    {
        return in_array($action, ['find_tv_info', 'refresh'], true);
    }

    public function scrape(SportsBotFixtureQueue $entry, string $action): array
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $eventId = trim((string) ($fixture['event_id'] ?? $entry->event_id ?? ''));

        if ($eventId === '') {
            return ['provider' => $this->key(), 'action' => $action, 'results' => [], 'logs' => []];
        }

        $channels = $this->client->fetchEventTvChannels($eventId);

        if ($channels === []) {
            return [
                'provider' => $this->key(),
                'action' => $action,
                'results' => [],
                'logs' => [['status' => 'empty', 'event_id' => $eventId]],
            ];
        }

        return [
            'provider' => $this->key(),
            'action' => $action,
            'results' => [[
                'provider' => $this->key(),
                'confidence' => 0.85,
                'fields' => [
                    'tv_channel' => $channels[0],
                    'tv_channels' => $channels,
                ],
            ]],
            'logs' => [[
                'status' => 'found',
                'event_id' => $eventId,
                'channels_found' => $channels,
            ]],
        ];
    }
}
