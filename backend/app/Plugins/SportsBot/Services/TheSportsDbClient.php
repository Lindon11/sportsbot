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
    public function allSports(): array
    {
        return $this->fetch('/all/sports', (int) config('plugins.SportsBot.cache.metadata', 86400), ['sports']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allLeagues(): array
    {
        return $this->fetch('/all/leagues', (int) config('plugins.SportsBot.cache.metadata', 86400), ['all', 'leagues']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allCountries(): array
    {
        return $this->fetch('/all/countries', (int) config('plugins.SportsBot.cache.metadata', 86400), ['countries']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchLeague(string $query): array
    {
        return $this->fetch('/search/league/' . rawurlencode($this->slug($query)), (int) config('plugins.SportsBot.cache.metadata', 86400), ['leagues', 'league']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTeams(string $leagueId): array
    {
        return $this->fetch('/list/teams/' . rawurlencode($leagueId), (int) config('plugins.SportsBot.cache.team', 86400), ['teams']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookupTeam(string $teamId): ?array
    {
        return $this->first('/lookup/team/' . rawurlencode($teamId), (int) config('plugins.SportsBot.cache.team', 86400), ['lookup', 'teams', 'team']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchTeam(string $query): array
    {
        return $this->fetch('/search/team/' . rawurlencode($this->slug($query)), (int) config('plugins.SportsBot.cache.team', 86400), ['teams']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupTeamEquipment(string $teamId): array
    {
        return $this->fetch('/lookup/team_equipment/' . rawurlencode($teamId), (int) config('plugins.SportsBot.cache.team', 86400), ['equipment', 'equipments']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlayers(string $teamId): array
    {
        return $this->fetch('/list/players/' . rawurlencode($teamId), (int) config('plugins.SportsBot.cache.player', 86400), ['players', 'player']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookupPlayer(string $playerId): ?array
    {
        return $this->first('/lookup/player/' . rawurlencode($playerId), (int) config('plugins.SportsBot.cache.player', 86400), ['players', 'player']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPlayer(string $query): array
    {
        return $this->fetch('/search/player/' . rawurlencode($this->slug($query)), (int) config('plugins.SportsBot.cache.player', 86400), ['players', 'player']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupPlayerContracts(string $playerId): array
    {
        return $this->fetch('/lookup/player_contracts/' . rawurlencode($playerId), (int) config('plugins.SportsBot.cache.player', 86400), ['contracts', 'players', 'player']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupPlayerResults(string $playerId): array
    {
        return $this->fetch('/lookup/player_results/' . rawurlencode($playerId), (int) config('plugins.SportsBot.cache.player', 86400), ['results', 'players', 'player']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupPlayerHonours(string $playerId): array
    {
        return $this->fetch('/lookup/player_honours/' . rawurlencode($playerId), (int) config('plugins.SportsBot.cache.player', 86400), ['honours', 'players', 'player']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupPlayerMilestones(string $playerId): array
    {
        return $this->fetch('/lookup/player_milestones/' . rawurlencode($playerId), (int) config('plugins.SportsBot.cache.player', 86400), ['milestones', 'players', 'player']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupPlayerTeams(string $playerId): array
    {
        return $this->fetch('/lookup/player_teams/' . rawurlencode($playerId), (int) config('plugins.SportsBot.cache.player', 86400), ['formerteams', 'teams', 'players']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookupLeague(string $leagueId): ?array
    {
        return $this->first('/lookup/league/' . rawurlencode($leagueId), (int) config('plugins.SportsBot.cache.metadata', 86400), ['lookup', 'leagues', 'league']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leagueTable(string $leagueId, ?string $season = null): array
    {
        $path = '/lookup/table/' . rawurlencode($leagueId);
        if ($season !== null && trim($season) !== '') {
            $path .= '/' . rawurlencode(trim($season));
        }

        return $this->fetch($path, (int) config('plugins.SportsBot.cache.league_table', 3600), ['table', 'tables', 'standings']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topScorers(string $leagueId, ?string $season = null): array
    {
        $path = '/lookup/topscorers/' . rawurlencode($leagueId);
        if ($season !== null && trim($season) !== '') {
            $path .= '/' . rawurlencode(trim($season));
        }

        return $this->fetch($path, (int) config('plugins.SportsBot.cache.league_table', 3600), ['topscorers', 'scorers', 'players']);
    }

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
    public function liveScoresAll(): array
    {
        return $this->fetchLiveScores();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function liveScoresBySport(string $sport): array
    {
        return $this->fetch('/livescore/' . rawurlencode($this->slug($sport)), (int) config('plugins.SportsBot.cache.live_scores', 60));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function liveScoresByLeague(string $leagueId): array
    {
        return $this->fetch('/livescore/' . rawurlencode($leagueId), (int) config('plugins.SportsBot.cache.live_scores', 60));
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
     * @return array<int, array<string, mixed>>
     */
    public function nextLeagueEvents(string $leagueId): array
    {
        return $this->fetch('/schedule/next/league/' . rawurlencode($leagueId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events', 'next']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function previousLeagueEvents(string $leagueId): array
    {
        return $this->fetch('/schedule/previous/league/' . rawurlencode($leagueId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events', 'previous']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextTeamEvents(string $teamId): array
    {
        return $this->fetch('/schedule/next/team/' . rawurlencode($teamId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events', 'next']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function previousTeamEvents(string $teamId): array
    {
        return $this->fetch('/schedule/previous/team/' . rawurlencode($teamId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events', 'previous']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fullLeagueSchedule(string $leagueId, ?string $season = null): array
    {
        $path = '/schedule/league/' . rawurlencode($leagueId);
        if ($season !== null && trim($season) !== '') {
            $path .= '/' . rawurlencode(trim($season));
        }

        return $this->fetch($path, (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fullTeamSchedule(string $teamId): array
    {
        return $this->fetch('/schedule/full/team/' . rawurlencode($teamId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextVenueEvents(string $venueId): array
    {
        return $this->fetch('/schedule/next/venue/' . rawurlencode($venueId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events', 'next']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function previousVenueEvents(string $venueId): array
    {
        return $this->fetch('/schedule/previous/venue/' . rawurlencode($venueId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['schedule', 'events', 'previous']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchEvent(string $query): array
    {
        return $this->fetch('/search/event/' . rawurlencode($this->slug($query)), (int) config('plugins.SportsBot.cache.fixtures', 900), ['events', 'event']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookupEvent(string $eventId): ?array
    {
        return $this->first('/lookup/event/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['events', 'event']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupEventStats(string $eventId): array
    {
        return $this->fetch('/lookup/event_stats/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['stats', 'statistics', 'eventstats']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupEventResults(string $eventId): array
    {
        return $this->fetch('/lookup/event_results/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['results', 'events', 'event']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupEventTimeline(string $eventId): array
    {
        return $this->fetch('/lookup/event_timeline/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['timeline', 'timelines', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupEventLineup(string $eventId): array
    {
        return $this->fetch('/lookup/event_lineup/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['lineup', 'lineups', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupEventHighlights(string $eventId): array
    {
        return $this->fetch('/lookup/event_highlights/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.fixtures', 900), ['highlights', 'tv', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookupEventTv(string $eventId): array
    {
        return $this->fetch('/lookup/event_tv/' . rawurlencode($eventId), (int) config('plugins.SportsBot.cache.tv_guide', 1800), ['lookup', 'tv', 'tvevents', 'events']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookupVenue(string $venueId): ?array
    {
        return $this->first('/lookup/venue/' . rawurlencode($venueId), (int) config('plugins.SportsBot.cache.metadata', 86400), ['venues', 'venue']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchVenue(string $query): array
    {
        return $this->fetch('/search/venue/' . rawurlencode($this->slug($query)), (int) config('plugins.SportsBot.cache.metadata', 86400), ['venues', 'venue']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSeasons(string $leagueId): array
    {
        return $this->fetch('/list/seasons/' . rawurlencode($leagueId), (int) config('plugins.SportsBot.cache.metadata', 86400), ['seasons']);
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
    public function tvByDay(string $date): array
    {
        return $this->fetch('/filter/tv/day/' . rawurlencode($date), (int) config('plugins.SportsBot.cache.tv_guide', 1800), ['filter', 'tvevents', 'tv', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tvBySport(string $sport): array
    {
        return $this->fetch('/filter/tv/sport/' . rawurlencode($this->slug($sport)), (int) config('plugins.SportsBot.cache.tv_guide', 1800), ['filter', 'tvevents', 'tv', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tvByCountry(string $country): array
    {
        return $this->fetch('/filter/tv/country/' . rawurlencode($this->slug($country)), (int) config('plugins.SportsBot.cache.tv_guide', 1800), ['filter', 'tvevents', 'tv', 'events']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tvByChannel(string $channel): array
    {
        return $this->fetchTvByChannel($this->slug($channel));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tvByChannelId(string $channelId): array
    {
        return $this->fetch('/filter/tv/channelid/' . rawurlencode($channelId), (int) config('plugins.SportsBot.tv.cache_ttl', 900), ['filter', 'tvevents', 'tv', 'events']);
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
     * @return array<int, array<string, mixed>>
     */
    public function eventHighlights(?string $date = null, ?string $leagueId = null, ?string $sport = null): array
    {
        $query = array_filter([
            'date' => $date,
            'league' => $leagueId,
            'sport' => $sport !== null ? $this->slug($sport) : null,
        ], static fn ($value): bool => $value !== null && trim((string) $value) !== '');

        $path = '/filter/highlights';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $this->fetch($path, (int) config('plugins.SportsBot.cache.fixtures', 900), ['highlights', 'events']);
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
     * @return array<string, mixed>|null
     */
    private function first(string $path, ?int $ttlOverride = null, array $extractKeys = ['events', 'event', 'data']): ?array
    {
        $rows = $this->fetch($path, $ttlOverride, $extractKeys);

        return $rows[0] ?? null;
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

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
