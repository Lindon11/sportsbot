<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class LiveNowService
{
    /**
     * @var array<string, string>
     */
    private const SPORT_LABELS = [
        'football' => 'Football',
        'soccer' => 'Football',
        'basketball' => 'Basketball',
        'nba' => 'Basketball',
        'baseball' => 'Baseball',
        'mlb' => 'Baseball',
        'american football' => 'American Football',
        'nfl' => 'American Football',
        'mma' => 'MMA',
        'mixed martial arts' => 'MMA',
        'ufc' => 'MMA',
        'tennis' => 'Tennis',
        'rugby' => 'Rugby',
        'rugby union' => 'Rugby',
        'rugby league' => 'Rugby',
        'cricket' => 'Cricket',
        'formula one' => 'Formula 1',
        'formula 1' => 'Formula 1',
        'f1' => 'Formula 1',
        'motorsport' => 'Formula 1',
        'racing' => 'Formula 1',
        'boxing' => 'Boxing',
        'fights' => 'Boxing',
        'fighting' => 'Boxing',
        'combat' => 'Boxing',
        'ppv' => 'Boxing',
        'ice hockey' => 'Ice Hockey',
        'hockey' => 'Ice Hockey',
        'nhl' => 'Ice Hockey',
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
        'MMA',
        'Boxing',
        'Tennis',
        'Rugby',
        'Cricket',
        'Formula 1',
        'Golf',
    ];

    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(): array
    {
        $timezoneName = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezoneName);
        $rows = $this->provider->fetchLiveScores();
        $allowedSports = $this->allowedSports();
        $configIds = array_map('strval', (array) config('plugins.SportsBot.coverage.allowed_league_ids', []));
        $dbIds = array_filter(array_map('strval', (array) $this->settings->get('featured_league_ids', [])));
        $allowedLeagueIds = $dbIds !== []
            ? array_values(array_unique(array_merge($configIds, $dbIds)))
            : $configIds;

        $matches = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $match = $this->normalizeMatch($row);

            if ($match === null) {
                $skipped++;
                continue;
            }

            $sportKey = $this->normalizeKey((string) ($match['raw_sport'] ?? $match['sport']));
            if ($allowedSports !== [] && !isset($allowedSports[$sportKey])) {
                continue;
            }

            if ($allowedLeagueIds !== []) {
                $leagueId = trim((string) ($match['league_id'] ?? ''));
                if ($leagueId === '' || !in_array($leagueId, $allowedLeagueIds, true)) {
                    continue;
                }
            }

            if (!$this->isLiveStatus((string) ($match['status'] ?? ''))) {
                continue;
            }

            $matches[] = $match;
        }

        $grouped = [];
        foreach (self::SPORT_ORDER as $sport) {
            $grouped[$sport] = [];
        }

        foreach ($matches as $match) {
            $sport = (string) ($match['sport'] ?? 'Sports');
            $grouped[$sport] ??= [];
            $grouped[$sport][] = $match;
        }

        foreach ($grouped as $sport => $sportMatches) {
            usort($sportMatches, static function (array $a, array $b): int {
                $leagueCompare = strcmp((string) ($a['league'] ?? ''), (string) ($b['league'] ?? ''));

                return $leagueCompare !== 0
                    ? $leagueCompare
                    : strcmp((string) ($a['event'] ?? ''), (string) ($b['event'] ?? ''));
            });
            $grouped[$sport] = $sportMatches;
        }

        $sportsGrouped = array_values(array_keys(array_filter(
            $grouped,
            static fn (array $sportMatches): bool => $sportMatches !== []
        )));

        $summary = [
            'route_key' => TelegramRouteKeys::LIVE_NOW,
            'generated_at' => $now->toIso8601String(),
            'timezone' => $timezoneName,
            'live_total' => count($matches),
            'live_raw' => count($rows),
            'live_skipped' => $skipped,
            'grouped' => $grouped,
            'sports_grouped' => $sportsGrouped,
            'sport_order' => self::SPORT_ORDER,
        ];

        Log::info('sportsbot.live_now.summary', [
            'route_key' => TelegramRouteKeys::LIVE_NOW,
            'live_total' => $summary['live_total'],
            'live_raw' => $summary['live_raw'],
            'live_skipped' => $summary['live_skipped'],
            'sports_grouped' => $sportsGrouped,
            'group_counts' => array_map(static fn (array $sportMatches): int => count($sportMatches), $grouped),
        ]);

        return $summary;
    }

    /**
     * @return array<string, true>
     */
    private function allowedSports(): array
    {
        $allowed = [];

        foreach ((array) $this->settings->get('enabled_sports', config('plugins.SportsBot.coverage.enabled_sports', [])) as $sport) {
            $key = $this->normalizeKey((string) $sport);

            if ($key === '') {
                continue;
            }

            $allowed[$key] = true;

            if ($key === 'football') {
                $allowed['soccer'] = true;
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeMatch(array $row): ?array
    {
        $homeTeam = trim((string) ($row['strHomeTeam'] ?? $row['home_team'] ?? ''));
        $awayTeam = trim((string) ($row['strAwayTeam'] ?? $row['away_team'] ?? ''));
        $eventName = trim((string) ($row['strEvent'] ?? $row['event'] ?? ''));

        if ($homeTeam === '' && $awayTeam === '' && $eventName === '') {
            return null;
        }

        $rawSport = (string) ($row['strSport'] ?? $row['sport'] ?? 'Soccer');
        $sport = $this->sportLabel($rawSport);

        if ($sport === null) {
            return null;
        }

        return [
            'event_id' => trim((string) ($row['idEvent'] ?? $row['event_id'] ?? '')),
            'sport' => $sport,
            'raw_sport' => $rawSport,
            'league_id' => trim((string) ($row['idLeague'] ?? $row['league_id'] ?? '')),
            'league' => trim((string) ($row['strLeague'] ?? $row['league'] ?? 'Competition TBC')) ?: 'Competition TBC',
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'event' => $eventName !== '' ? $eventName : trim($homeTeam . ' vs ' . $awayTeam),
            'home_score' => $this->nullableInt($row['intHomeScore'] ?? $row['home_score'] ?? null),
            'away_score' => $this->nullableInt($row['intAwayScore'] ?? $row['away_score'] ?? null),
            'status' => trim((string) ($row['strStatus'] ?? $row['status'] ?? '')),
            'progress' => trim((string) ($row['strProgress'] ?? $row['progress'] ?? '')),
        ];
    }

    private function sportLabel(string $sport): ?string
    {
        $key = $this->normalizeKey($sport);

        return self::SPORT_LABELS[$key] ?? ($key !== '' ? ucwords($key) : null);
    }

    private function isLiveStatus(string $status): bool
    {
        $key = $this->normalizeKey($status);

        return $key !== ''
            && !in_array($key, ['ns', 'not started', 'tbd', 'postponed', 'cancelled', 'canceled'], true)
            && !in_array($key, ['ft', 'full time', 'aet', 'aot', 'ap', 'after penalties', 'final', 'match finished'], true);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric((string) $value) ? (int) $value : null;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }
}
