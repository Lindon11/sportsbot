<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\SportsDataProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TheSportsDbClient implements SportsDataProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchLiveScores(): array
    {
        try {
            return $this->fetch('/livescore/all');
        } catch (\Throwable) {
            $rows = $this->fetch('/livescore/soccer');

            return array_map(static function (array $row): array {
                if (empty($row['strSport'])) {
                    $row['strSport'] = 'Soccer';
                }

                return $row;
            }, $rows);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchNextLeagueSchedule(string $leagueId): array
    {
        $leagueId = trim($leagueId);

        if ($leagueId === '') {
            return [];
        }

        return $this->fetch('/schedule/next/league/' . rawurlencode($leagueId), 180, ['schedule', 'events', 'next']);
    }

    /**
     * @return array<int, string>
     */
    public function fetchEventTvChannels(string $eventId): array
    {
        $eventId = trim($eventId);

        if ($eventId === '') {
            return [];
        }

        $rows = $this->fetch('/lookup/event_tv/' . rawurlencode($eventId), 300, ['lookup', 'tv', 'tvevents', 'events']);
        $channels = [];

        foreach ($rows as $row) {
            $channel = trim((string) ($row['strChannel'] ?? $row['strChannelName'] ?? ''));

            if ($channel !== '') {
                $channels[$channel] = true;
            }
        }

        return array_keys($channels);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchTvByChannel(string $channelSlug): array
    {
        $channelSlug = trim($channelSlug);

        if ($channelSlug === '') {
            return [];
        }

        return $this->fetch(
            '/filter/tv/channel/' . rawurlencode($channelSlug),
            (int) config('plugins.SportsBot.tv.cache_ttl', 900),
            ['filter', 'tvevents', 'tv', 'events']
        );
    }

    /**
     * @param array<int, string> $extractKeys
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $path, ?int $ttlOverride = null, array $extractKeys = ['livescore', 'livescores', 'events', 'event', 'matches', 'results', 'data']): array
    {
        $ttl = $ttlOverride ?? max(0, (int) config('plugins.SportsBot.provider.live_score_cache_ttl', 75));
        $ttl = max(0, $ttl);
        $cacheKey = 'sportsbot:provider:thesportsdb:' . sha1($path . '|' . implode(',', $extractKeys));

        $callback = fn (): array => $this->fetchFresh($path, $extractKeys);

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * @param array<int, string> $extractKeys
     * @return array<int, array<string, mixed>>
     */
    private function fetchFresh(string $path, array $extractKeys): array
    {
        $apiKey = trim((string) config('plugins.SportsBot.provider.api_key', ''));
        $baseUrl = rtrim((string) config('plugins.SportsBot.provider.base_url'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('TheSportsDB API key is not configured.');
        }

        $response = Http::acceptJson()
            ->withHeaders(['X-API-KEY' => $apiKey])
            ->connectTimeout((int) config('plugins.SportsBot.provider.connect_timeout', 10))
            ->timeout((int) config('plugins.SportsBot.provider.timeout', 20))
            ->get($baseUrl . '/' . ltrim($path, '/'));

        if (!$response->successful()) {
            throw new RuntimeException('TheSportsDB returned HTTP ' . $response->status() . '.');
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new RuntimeException('TheSportsDB returned invalid JSON.');
        }

        return $this->extractList($payload, $extractKeys);
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, array<string, mixed>>
     */
    private function extractList(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }

            return array_values(array_filter($payload[$key], 'is_array'));
        }

        return [];
    }
}
