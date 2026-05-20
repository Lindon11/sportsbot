<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\TheSportsDbClient;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use App\Plugins\SportsBot\Support\SportsBotSports;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HighlightsContentModule implements SportsBotContentModuleInterface
{
    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
        private readonly SportsBotCardRenderer $cards = new SportsBotCardRenderer(),
    ) {
    }

    public function key(): string
    {
        return 'HIGHLIGHTS';
    }

    public function label(): string
    {
        return 'Match Highlights';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::HIGHLIGHTS;
    }

    public function buildSummary(): array
    {
        $cacheKey = 'sportsbot:highlights_summary';
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $highlights = [];
        $sportKeys = array_keys(SportsBotSports::all());
        $daysBack = 7;

        foreach ($sportKeys as $sportKey) {
            try {
                $leagueIds = $this->leagueIdsForSport($sportKey);
                $alreadySeen = [];
                foreach ($leagueIds as $leagueId) {
                    try {
                        $events = $this->provider->previousLeagueEvents((string) $leagueId);
                        foreach ($events as $event) {
                            $video = trim((string) ($event['strVideo'] ?? ''));

                            $eventId = trim((string) ($event['idEvent'] ?? ''));
                            if ($eventId === '' || isset($alreadySeen[$eventId])) {
                                continue;
                            }
                            $alreadySeen[$eventId] = true;

                            $eventDate = trim((string) ($event['dateEvent'] ?? ''));
                            if ($eventDate < Carbon::today()->subDays($daysBack)->toDateString()) {
                                continue;
                            }

                            $thumb = trim((string) ($event['strThumb'] ?? ''));
                            $home = trim((string) ($event['strHomeTeam'] ?? ''));
                            $away = trim((string) ($event['strAwayTeam'] ?? ''));
                            $homeScore = $event['intHomeScore'] ?? null;
                            $awayScore = $event['intAwayScore'] ?? null;
                            $score = $homeScore !== null && $awayScore !== null ? "{$homeScore}-{$awayScore}" : '';

                            $highlights[] = [
                                'event_id' => $eventId,
                                'sport' => SportsBotSports::label($sportKey),
                                'sport_key' => $sportKey,
                                'league' => trim((string) ($event['strLeague'] ?? '')),
                                'league_id' => trim((string) ($event['idLeague'] ?? '')),
                                'event_name' => trim((string) ($event['strEvent'] ?? '')),
                                'home_team' => $home,
                                'away_team' => $away,
                                'home_score' => $homeScore,
                                'away_score' => $awayScore,
                                'score' => $score,
                                'video_url' => $video,
                                'thumb' => $thumb,
                                'date' => $eventDate,
                                'league_badge' => trim((string) ($event['strLeagueBadge'] ?? '')),
                                'home_badge' => trim((string) ($event['strHomeTeamBadge'] ?? '')),
                                'away_badge' => trim((string) ($event['strAwayTeamBadge'] ?? '')),
                            ];
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        usort($highlights, fn (array $a, array $b): int => ($b['date'] ?? '') <=> ($a['date'] ?? ''));

        $summary = [
            'route_key' => TelegramRouteKeys::HIGHLIGHTS,
            'title' => 'Match Highlights',
            'highlights' => $highlights,
            'total' => count($highlights),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];

        \Illuminate\Support\Facades\Cache::put($cacheKey, $summary, now()->addMinutes(30));

        return $summary;
    }

    public function format(array $summary): string
    {
        $total = (int) ($summary['total'] ?? 0);
        if ($total === 0) {
            return 'No match highlights available.';
        }
        $lines = ["<b>Match Highlights</b>\n"];
        foreach (array_slice($summary['highlights'] ?? [], 0, 20) as $h) {
            $name = $h['event_name'] ?? 'Match';
            $league = $h['league'] ?? '';
            $score = $h['score'] ?? '';
            $date = $h['date'] ?? '';
            $video = $h['video_url'] ?? '';
            $line = "⚽ {$name}";
            if ($score) {
                $line .= " ({$score})";
            }
            $line .= " - {$league} ({$date})";
            if ($video) {
                $url = preg_replace('/^https?:\/\/(www\.)?youtube\.com\/watch\?v=/', '', $video);
                $line .= "\n▶️ <a href=\"{$video}\">Watch highlights</a>";
            }
            $lines[] = $line;
        }
        return implode("\n\n", $lines);
    }

    public function telegramOptions(array $summary): array
    {
        return [
            'parse_mode' => 'HTML',
            'payload' => [
                'total' => (int) ($summary['total'] ?? 0),
                'generated_at' => (string) ($summary['generated_at'] ?? ''),
                'card_version' => $this->cardVersion(),
            ],
        ];
    }

    public function renderCards(array $summary, string $cardVersion = 'v3'): array
    {
        $cards = [];
        foreach (array_slice($summary['highlights'] ?? [], 0, 10) as $h) {
            try {
                $eventId = $h['event_id'] ?? '';
                $stats = [];
                if ($eventId !== '') {
                    try {
                        $rows = $this->provider->lookupEventStats($eventId);
                        $hasValues = false;
                        foreach ($rows as $s) {
                            $home = trim((string) ($s['strHome'] ?? ''));
                            if ($home !== '' && $home !== '?') {
                                $hasValues = true;
                                break;
                            }
                        }
                        if ($hasValues) {
                            foreach ($rows as $s) {
                                $name = trim((string) ($s['strStat'] ?? ''));
                                $home = trim((string) ($s['strHome'] ?? ''));
                                $away = trim((string) ($s['strAway'] ?? ''));
                                if ($name !== '') {
                                    $key = strtolower(str_replace([' ', '%', '.', '-'], '_', $name));
                                    $stats[$key] = ['home' => $home, 'away' => $away];
                                }
                            }
                        } else {
                            $stats = $this->scrapeEventStats($eventId, $h['event_name'] ?? '');
                        }
                    } catch (Throwable) {
                        $stats = $this->scrapeEventStats($eventId, $h['event_name'] ?? '');
                    }
                }

                $fixture = [
                    'event_name' => $h['event_name'],
                    'home_team' => $h['home_team'],
                    'away_team' => $h['away_team'],
                    'home_score' => $h['home_score'],
                    'away_score' => $h['away_score'],
                    'score' => $h['score'],
                    'league' => $h['league'],
                    'sport' => SportsBotSports::providerSport($h['sport_key']),
                    'event_thumb' => $h['thumb'],
                    'home_badge' => $h['home_badge'],
                    'away_badge' => $h['away_badge'],
                    'league_badge' => $h['league_badge'],
                    'sport_key' => $h['sport_key'],
                    'dateEvent' => $h['date'],
                    'date_label' => $h['date'],
                    'result_status' => 'Full Time',
                    'video_url' => $h['video_url'],
                    'background_image' => $h['thumb'],
                    'event_stats' => $stats,
                    'home_stats' => $stats,
                    'away_stats' => $stats,
                ];
                $card = $this->cards->fixtureCard($fixture, $cardVersion, [
                    'route_key' => TelegramRouteKeys::HIGHLIGHTS,
                    'kind' => 'result',
                ]);
                $path = (string) ($card['path'] ?? '');
                if ($path !== '' && is_file($path)) {
                    $cards[] = [
                        'path' => $path,
                        'event_name' => $h['event_name'],
                        'video_url' => $h['video_url'],
                        'data_url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)),
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }
        return $cards;
    }

    private function leagueIdsForSport(string $sportKey): array
    {
        $configKey = match ($sportKey) {
            'football' => 'default_league_ids',
            'rugby' => 'rugby_league_ids',
            'fights', 'mma', 'boxing' => 'fight_league_ids',
            'formula_1', 'motorsport' => 'formula_1_league_ids',
            'american_football' => 'american_football_league_ids',
            'ice_hockey' => 'ice_hockey_league_ids',
            'cricket' => 'cricket_league_ids',
            'basketball' => 'basketball_league_ids',
            'baseball' => 'baseball_league_ids',
            'tennis' => 'tennis_league_ids',
            default => null,
        };

        if ($configKey === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            'strval',
            (array) config('plugins.SportsBot.fixtures_today.' . $configKey, [])
        ), fn (string $id): bool => trim($id) !== '')));
    }

    private function scrapeEventStats(string $eventId, string $eventName): array
    {
        $cacheKey = 'sportsbot:scraped_stats:' . $eventId;
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $eventName), '-'));
            $url = "https://www.thesportsdb.com/event/{$eventId}-{$slug}";
            $html = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'])
                ->get($url)
                ->body();

            preg_match_all('/<h5>([^<]+)<\/h5><div[^>]+container2[^>]*><div[^>]+score-left[^>]+>([^<]+)<\/div>.*?<div[^>]+score-right[^>]+>([^<]+)<\/div>/s', $html, $matches);
            $stats = [];
            for ($i = 0; $i < count($matches[1]); $i++) {
                $name = trim($matches[1][$i]);
                $home = trim($matches[2][$i]);
                $away = trim($matches[3][$i]);
                if ($name !== '') {
                    $key = strtolower(str_replace([' ', '%', '.', '-'], '_', $name));
                    $stats[$key] = ['home' => $home, 'away' => $away];
                }
            }

            \Illuminate\Support\Facades\Cache::put($cacheKey, $stats, now()->addDays(7));
            return $stats;
        } catch (Throwable) {
            return [];
        }
    }

    private function cardVersion(): string
    {
        $version = strtolower(trim((string) $this->settings->get('highlights_card_version', 'v3')));
        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }
}
