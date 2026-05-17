<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

class FixturesTodayService
{
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
        'tennis' => 'Tennis',
    ];

    /**
     * @var array<int, string>
     */
    private const SPORT_ORDER = [
        'Football',
        'Basketball',
        'Baseball',
        'MMA',
        'Tennis',
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
        $tz = new DateTimeZone($timezoneName);
        $today = CarbonImmutable::now($tz)->toDateString();

        $leagueIds = array_values(array_unique(array_filter(
            array_map('strval', (array) $this->settings->get('featured_league_ids', config('plugins.SportsBot.coverage.allowed_league_ids', []))),
            static fn (string $id): bool => trim($id) !== ''
        )));

        if ($leagueIds === []) {
            $leagueIds = array_values(array_unique(array_filter(
                array_map('strval', (array) config('plugins.SportsBot.fixtures_today.default_league_ids', [])),
                static fn (string $id): bool => trim($id) !== ''
            )));
        }

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

            $rawFixturesCount += count($rows);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $skippedFixtures++;
                    continue;
                }

                $kickoffAt = $this->eventKickoffLocal($row, $tz);

                if ($kickoffAt === null || $kickoffAt->toDateString() !== $today) {
                    continue;
                }

                $sport = $this->normalizeSportLabel((string) ($row['strSport'] ?? ''));

                if ($sport === null) {
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

                if ($eventName === '' && $homeTeam === '' && $awayTeam === '') {
                    $skippedFixtures++;
                    continue;
                }

                $tvChannel = '';
                if ($eventId !== '') {
                    try {
                        $tvChannels = $this->provider->fetchEventTvChannels($eventId);
                        $tvChannelsFound += count($tvChannels);
                        $tvChannel = $this->selectPreferredChannel($tvChannels);
                    } catch (Throwable $error) {
                        Log::warning('sportsbot.fixtures_today.tv_lookup_failed', [
                            'event_id' => $eventId,
                            'error' => $error->getMessage(),
                        ]);
                    }
                }

                $fixtures[] = [
                    'event_id' => $eventId,
                    'sport' => $sport,
                    'league' => $leagueName !== '' ? $leagueName : 'Competition TBC',
                    'home_team' => $homeTeam,
                    'away_team' => $awayTeam,
                    'event_name' => $eventName,
                    'time' => $kickoffAt->format('H:i'),
                    'kickoff_at' => $kickoffAt,
                    'tv_channel' => $tvChannel,
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
            usort($sportFixtures, static fn (array $a, array $b): int => $a['kickoff_at']->timestamp <=> $b['kickoff_at']->timestamp);
            $grouped[$sport] = $sportFixtures;
        }

        $sportsGrouped = array_values(array_keys(array_filter(
            $grouped,
            static fn (array $sportFixtures): bool => $sportFixtures !== []
        )));

        $summary = [
            'route_key' => TelegramRouteKeys::FIXTURES_TODAY,
            'date' => $today,
            'timezone' => $timezoneName,
            'fixtures_total' => count($fixtures),
            'fixtures_raw' => $rawFixturesCount,
            'fixtures_skipped' => $skippedFixtures,
            'tv_channels_found' => $tvChannelsFound,
            'grouped' => $grouped,
            'sports_grouped' => $sportsGrouped,
            'sport_order' => self::SPORT_ORDER,
        ];

        Log::info('sportsbot.fixtures_today.summary', [
            'route_key' => TelegramRouteKeys::FIXTURES_TODAY,
            'fixtures_total' => $summary['fixtures_total'],
            'fixtures_raw' => $rawFixturesCount,
            'fixtures_skipped' => $skippedFixtures,
            'tv_channels_found' => $tvChannelsFound,
            'sports_grouped' => $sportsGrouped,
            'group_counts' => array_map(static fn (array $sportFixtures): int => count($sportFixtures), $grouped),
        ]);

        return $summary;
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

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }
}
