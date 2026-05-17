<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\SportsDataProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TheSportsDbClient implements SportsDataProviderInterface
{
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

    private function fetch(string $path): array
    {
        $ttl = max(0, (int) config('plugins.SportsBot.provider.live_score_cache_ttl', 75));
        $cacheKey = 'sportsbot:provider:thesportsdb:' . sha1($path);

        $callback = fn (): array => $this->fetchFresh($path);

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    private function fetchFresh(string $path): array
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

        return $this->extractList($payload, ['livescore', 'livescores', 'events', 'event', 'matches', 'results', 'data']);
    }

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
