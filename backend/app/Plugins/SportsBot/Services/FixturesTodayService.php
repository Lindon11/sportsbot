<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use App\Plugins\SportsBot\Support\SportsBotSports;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

class FixturesTodayService
{
    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $teamCache = [];

    /**
     * @var array<string, string>
     */
    private const SPORT_LABELS = [
        'football' => 'Football',
        'soccer' => 'Football',
        'basketball' => 'Basketball',
        'baseball' => 'Baseball',
        'mma' => 'MMA',
        'mixed martial arts' => 'MMA',
        'ufc' => 'MMA',
        'fights' => 'Fights',
        'fighting' => 'Fights',
        'boxing' => 'Fights',
        'tennis' => 'Tennis',
        'rugby' => 'Rugby',
        'rugby union' => 'Rugby',
        'rugby league' => 'Rugby',
        'motorsport' => 'Motorsport',
        'formula 1' => 'Motorsport',
        'f1' => 'Motorsport',
        'american football' => 'American Football',
        'ice hockey' => 'Ice Hockey',
        'hockey' => 'Ice Hockey',
        'cricket' => 'Cricket',
        'golf' => 'Golf',
    ];

    /**
     * @var array<int, string>
     */
    private const SPORT_ORDER = [
        'Football',
        'Basketball',
        'Baseball',
        'American Football',
        'Ice Hockey',
        'Fights',
        'MMA',
        'Tennis',
        'Rugby',
        'Cricket',
        'Motorsport',
        'Golf',
    ];

    /**
     * @var array<int, string>
     */
    private const PREFERRED_UK_CHANNEL_KEYS = [
        'sky sports',
        'tnt sports',
        'bbc',
        'itv',
        'premier sports',
        'dazn uk',
        'amazon prime',
        'prime video',
    ];

    /**
     * @var array<int, string>
     */
    private const UK_COUNTRY_KEYS = [
        'united kingdom',
        'uk',
        'great britain',
        'gb',
        'england',
        'scotland',
        'wales',
        'northern ireland',
    ];

    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(?string $sportKey = null, ?int $lookaheadDays = null): array
    {
        $timezoneName = (string) config('plugins.SportsBot.fixtures_today.timezone', 'Europe/London');
        $tz = new DateTimeZone($timezoneName);
        $today = CarbonImmutable::now($tz)->toDateString();
        $endDate = CarbonImmutable::now($tz)->addDays(max(0, (int) ($lookaheadDays ?? 0)))->toDateString();
        $requestedSport = $sportKey !== null && trim($sportKey) !== ''
            ? $this->normalizeSportLabel(SportsBotSports::providerSport($sportKey))
            : null;

        $leagueIds = $this->leagueIdsForSport($sportKey);

        if ($leagueIds === []) {
            $leagueIds = array_values(array_unique(array_filter(
                array_map('strval', (array) config('plugins.SportsBot.fixtures_today.default_league_ids', [])),
                static fn (string $id): bool => trim($id) !== ''
            )));
        }
        $leagueOrder = array_flip($leagueIds);

        $rawFixturesCount = 0;
        $skippedFixtures = 0;
        $tvChannelsFound = 0;
        $fixtures = [];
        $seen = [];

        foreach ($leagueIds as $leagueId) {
            try {
                $rows = $this->provider->fetchNextLeagueSchedule($leagueId);
            } catch (Throwable $error) {
                Log::warning('sportsbot.fixtures_today.league_fetch_failed', [
                    'league_id' => $leagueId,
                    'error' => $error->getMessage(),
                ]);
                continue;
            }

            $leagueMeta = [];
            try {
                $leagueMeta = $this->provider->lookupLeague($leagueId) ?? [];
            } catch (Throwable $error) {
                Log::debug('sportsbot.fixtures_today.league_lookup_failed', [
                    'league_id' => $leagueId,
                    'error' => $error->getMessage(),
                ]);
            }

            $rawFixturesCount += count($rows);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $skippedFixtures++;
                    continue;
                }

                $kickoffAt = $this->eventKickoffLocal($row, $tz);

                if ($kickoffAt === null || $kickoffAt->toDateString() < $today || $kickoffAt->toDateString() > $endDate) {
                    continue;
                }

                $sport = $this->normalizeSportLabel((string) ($row['strSport'] ?? ''));

                if ($sport === null) {
                    continue;
                }

                if ($requestedSport !== null && $sport !== $requestedSport) {
                    continue;
                }

                $eventId = trim((string) ($row['idEvent'] ?? ''));
                $dedupeKey = $eventId !== ''
                    ? 'event:' . $eventId
                    : 'fallback:' . sha1(json_encode([
                        $row['dateEvent'] ?? null,
                        $row['strTime'] ?? null,
                        $row['strEventTime'] ?? null,
                        $row['strHomeTeam'] ?? null,
                        $row['strAwayTeam'] ?? null,
                        $row['strEvent'] ?? null,
                    ]));

                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $homeTeam = trim((string) ($row['strHomeTeam'] ?? ''));
                $awayTeam = trim((string) ($row['strAwayTeam'] ?? ''));
                $eventName = trim((string) ($row['strEvent'] ?? ''));
                $leagueName = trim((string) ($row['strLeague'] ?? ''));

                if ($requestedSport === 'Football' && !$this->isUkFootballFixture($row, $leagueMeta)) {
                    $skippedFixtures++;
                    continue;
                }

                if ($eventName === '' && $homeTeam === '' && $awayTeam === '') {
                    $skippedFixtures++;
                    continue;
                }

                $tvChannel = '';
                $tvChannels = [];
                if ($eventId !== '') {
                    try {
                        $tvRows = $this->provider->lookupEventTv($eventId);
                        $tvChannelsFound += count($tvRows);
                        $tvChannels = $this->selectUkTvChannels($tvRows);
                        $tvChannel = $this->selectPreferredChannel($tvChannels);
                    } catch (Throwable $error) {
                        Log::warning('sportsbot.fixtures_today.tv_lookup_failed', [
                            'event_id' => $eventId,
                            'error' => $error->getMessage(),
                        ]);
                    }
                }

                $homeTeamMeta = $this->lookupTeamMeta(trim((string) ($row['idHomeTeam'] ?? '')));

                $fixtures[] = [
                    'event_id' => $eventId,
                    'sport' => $sport,
                    'league' => $leagueName !== '' ? $leagueName : 'Competition TBC',
                    'home_team' => $homeTeam,
                    'away_team' => $awayTeam,
                    'event_name' => $eventName,
                    'time' => $kickoffAt->format('H:i'),
                    'date_label' => $kickoffAt->format('l j F Y'),
                    'kickoff_label' => $kickoffAt->format('H:i T'),
                    'kickoff_at' => $kickoffAt,
                    'tv_channel' => $tvChannel,
                    'tv_channels' => $tvChannels,
                    'event_thumb' => trim((string) ($row['strThumb'] ?? '')),
                    'event_poster' => trim((string) ($row['strPoster'] ?? '')),
                    'home_badge' => trim((string) ($row['strHomeTeamBadge'] ?? $row['strHomeBadge'] ?? '')),
                    'away_badge' => trim((string) ($row['strAwayTeamBadge'] ?? $row['strAwayBadge'] ?? '')),
                    'league_badge' => $this->leagueArtworkUrl($row, $leagueMeta, ['strLeagueBadge', 'strBadge']),
                    'league_logo' => $this->leagueArtworkUrl($row, $leagueMeta, ['strLeagueLogo', 'strLogo']),
                    'venue' => $this->fixtureVenue($row, $homeTeamMeta),
                    'venue_source' => trim((string) ($row['strVenue'] ?? '')) !== '' ? 'event' : 'home_team',
                    'league_id' => trim((string) ($row['idLeague'] ?? '')),
                    'home_team_id' => trim((string) ($row['idHomeTeam'] ?? '')),
                    'away_team_id' => trim((string) ($row['idAwayTeam'] ?? '')),
                ];
            }
        }

        $grouped = [];
        foreach (self::SPORT_ORDER as $sport) {
            $grouped[$sport] = [];
        }

        foreach ($fixtures as $fixture) {
            $grouped[$fixture['sport']][] = $fixture;
        }

        foreach ($grouped as $sport => $sportFixtures) {
            usort($sportFixtures, fn (array $a, array $b): int => $this->compareFixtures($a, $b, (string) $sport, $leagueOrder));
            $grouped[$sport] = $sportFixtures;
        }

        $sportsGrouped = array_values(array_keys(array_filter(
            $grouped,
            static fn (array $sportFixtures): bool => $sportFixtures !== []
        )));

        $summary = [
            'route_key' => $requestedSport !== null && $sportKey !== null ? SportsBotSports::routeKey($sportKey) : TelegramRouteKeys::FIXTURES_TODAY,
            'title' => $requestedSport !== null ? $requestedSport . ' Fixtures TV' : "Today's Fixtures",
            'sport_filter' => $sportKey !== null ? SportsBotSports::normalize($sportKey) : null,
            'date' => $today,
            'date_to' => $endDate,
            'lookahead_days' => max(0, (int) ($lookaheadDays ?? 0)),
            'timezone' => $timezoneName,
            'fixtures_total' => count($fixtures),
            'fixtures_raw' => $rawFixturesCount,
            'fixtures_skipped' => $skippedFixtures,
            'tv_channels_found' => $tvChannelsFound,
            'max_per_sport' => $requestedSport !== null ? max(1, count($fixtures)) : max(1, (int) config('plugins.SportsBot.fixtures_today.max_per_sport', 5)),
            'grouped' => $grouped,
            'sports_grouped' => $sportsGrouped,
            'sport_order' => self::SPORT_ORDER,
        ];

        Log::info('sportsbot.fixtures_today.summary', [
            'route_key' => $summary['route_key'],
            'fixtures_total' => $summary['fixtures_total'],
            'fixtures_raw' => $rawFixturesCount,
            'fixtures_skipped' => $skippedFixtures,
            'tv_channels_found' => $tvChannelsFound,
            'sports_grouped' => $sportsGrouped,
            'group_counts' => array_map(static fn (array $sportFixtures): int => count($sportFixtures), $grouped),
        ]);

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    private function leagueIdsForSport(?string $sportKey): array
    {
        $normalizedSport = $sportKey !== null ? SportsBotSports::normalize($sportKey) : null;

        $sportConfigIds = config('plugins.SportsBot.fixtures_today.' . $normalizedSport . '_league_ids', []);

        return match ($normalizedSport) {
            'fights' => $this->resolveLeagueIds('fight_fixture_league_ids', $sportConfigIds ?: config('plugins.SportsBot.fixtures_today.fight_league_ids', [])),
            'rugby' => $this->resolveLeagueIds('rugby_fixture_league_ids', $sportConfigIds ?: config('plugins.SportsBot.fixtures_today.rugby_league_ids', [])),
            'football' => $this->resolveLeagueIds('featured_league_ids', config('plugins.SportsBot.coverage.allowed_league_ids', [])),
            null => $this->resolveLeagueIds('featured_league_ids', config('plugins.SportsBot.fixtures_today.default_league_ids', [])),
            default => $this->resolveLeagueIds($normalizedSport . '_fixture_league_ids', $sportConfigIds),
        };
    }

    /**
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function resolveLeagueIds(string $settingKey, array $default): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', (array) $this->settings->get($settingKey, $default)),
            static fn (string $id): bool => trim($id) !== ''
        )));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param array<string, int> $leagueOrder
     */
    private function compareFixtures(array $a, array $b, string $sport, array $leagueOrder): int
    {
        if (in_array($sport, ['Football', 'Rugby', 'Fights', 'Motorsport'], true)) {
            $aLeague = (string) ($a['league_id'] ?? '');
            $bLeague = (string) ($b['league_id'] ?? '');
            $aLeagueOrder = $leagueOrder[$aLeague] ?? PHP_INT_MAX;
            $bLeagueOrder = $leagueOrder[$bLeague] ?? PHP_INT_MAX;

            if ($aLeagueOrder !== $bLeagueOrder) {
                return $aLeagueOrder <=> $bLeagueOrder;
            }

            $leagueCompare = strcmp((string) ($a['league'] ?? ''), (string) ($b['league'] ?? ''));
            if ($leagueCompare !== 0) {
                return $leagueCompare;
            }
        }

        $timeCompare = ($a['kickoff_at']->timestamp ?? 0) <=> ($b['kickoff_at']->timestamp ?? 0);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }

        return strcmp((string) ($a['event_name'] ?? ''), (string) ($b['event_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $league
     * @param array<int, string> $keys
     */
    private function leagueArtworkUrl(array $event, array $league, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($event[$key] ?? $league[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $homeTeam
     */
    private function fixtureVenue(array $event, ?array $homeTeam): string
    {
        $eventVenue = trim((string) ($event['strVenue'] ?? ''));
        if ($eventVenue !== '') {
            return $eventVenue;
        }

        $homeVenue = trim((string) ($homeTeam['strStadium'] ?? ''));
        if ($homeVenue !== '') {
            return $homeVenue;
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupTeamMeta(string $teamId): ?array
    {
        if ($teamId === '') {
            return null;
        }

        if (array_key_exists($teamId, $this->teamCache)) {
            return $this->teamCache[$teamId];
        }

        try {
            return $this->teamCache[$teamId] = $this->provider->lookupTeam($teamId);
        } catch (Throwable $error) {
            Log::debug('sportsbot.fixtures_today.team_lookup_failed', [
                'team_id' => $teamId,
                'error' => $error->getMessage(),
            ]);
        }

        return $this->teamCache[$teamId] = null;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $league
     */
    private function isUkFootballFixture(array $event, array $league): bool
    {
        $leagueId = trim((string) ($event['idLeague'] ?? $league['idLeague'] ?? ''));
        if ($leagueId !== '' && in_array($leagueId, array_map('strval', (array) config('plugins.SportsBot.fixtures_today.international_league_ids', [])), true)) {
            return true;
        }

        $eventCountry = trim((string) ($event['strCountry'] ?? $event['strCountryCode'] ?? ''));
        if ($eventCountry !== '') {
            return $this->isUkCountry($eventCountry);
        }

        $leagueCountry = trim((string) ($league['strCountry'] ?? ''));
        $teamCountries = [];

        foreach (['idHomeTeam', 'idAwayTeam'] as $teamIdKey) {
            $teamId = trim((string) ($event[$teamIdKey] ?? ''));
            if ($teamId === '') {
                continue;
            }

            $team = $this->lookupTeamMeta($teamId);

            $country = trim((string) ($team['strCountry'] ?? ''));
            if ($country !== '') {
                $teamCountries[] = $country;
            }
        }

        if ($teamCountries !== []) {
            foreach ($teamCountries as $country) {
                if ($this->isUkCountry($country)) {
                    return true;
                }
            }

            return false;
        }

        return $leagueCountry === '' || $this->isUkCountry($leagueCountry);
    }

    private function normalizeSportLabel(string $sport): ?string
    {
        $key = $this->normalizeKey($sport);

        return self::SPORT_LABELS[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventKickoffLocal(array $event, DateTimeZone $tz): ?CarbonImmutable
    {
        $timestamp = trim((string) ($event['strTimestamp'] ?? ''));
        if ($timestamp !== '') {
            try {
                return CarbonImmutable::parse($timestamp)->setTimezone($tz);
            } catch (Throwable) {
            }
        }

        $date = trim((string) ($event['dateEventLocal'] ?? $event['dateEvent'] ?? ''));
        $time = trim((string) ($event['strTimeLocal'] ?? $event['strTime'] ?? $event['strEventTime'] ?? '00:00:00'));

        if ($date === '') {
            return null;
        }

        $candidate = trim($date . ' ' . $time);

        try {
            return CarbonImmutable::parse($candidate, $tz)->setTimezone($tz);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $channels
     */
    private function selectPreferredChannel(array $channels): string
    {
        $labels = array_values(array_filter(array_map(
            static fn (string $channel): string => trim($channel),
            $channels
        ), static fn (string $channel): bool => $channel !== ''));

        if ($labels === []) {
            return '';
        }

        $normalized = array_map(static fn (string $label): string => strtolower($label), $labels);

        foreach (self::PREFERRED_UK_CHANNEL_KEYS as $preferredKey) {
            foreach ($normalized as $index => $candidate) {
                if (str_contains($candidate, $preferredKey)) {
                    return $labels[$index];
                }
            }
        }

        return $labels[0];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function selectUkTvChannels(array $rows): array
    {
        $channels = [];

        foreach ($rows as $row) {
            $channel = trim((string) ($row['strChannel'] ?? $row['strChannelName'] ?? ''));
            if ($channel === '') {
                continue;
            }

            $country = trim((string) ($row['strCountry'] ?? $row['strCountryCode'] ?? ''));
            if ($this->isUkCountry($country) || ($country === '' && $this->isConfiguredUkChannel($channel))) {
                $channels[$channel] = true;
            }
        }

        return array_keys($channels);
    }

    private function isUkCountry(string $country): bool
    {
        $key = $this->normalizeKey($country);

        return $key !== '' && in_array($key, self::UK_COUNTRY_KEYS, true);
    }

    private function isConfiguredUkChannel(string $channel): bool
    {
        $key = $this->normalizeKey($channel);
        if ($key === '') {
            return false;
        }

        foreach (self::PREFERRED_UK_CHANNEL_KEYS as $preferredKey) {
            if (str_contains($key, $preferredKey)) {
                return true;
            }
        }

        foreach ((array) $this->settings->get('tv_channels', config('plugins.SportsBot.tv.channels', [])) as $configured) {
            $configuredKey = $this->normalizeKey((string) $configured);
            if ($configuredKey !== '' && (str_contains($key, $configuredKey) || str_contains($configuredKey, $key))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }
}
