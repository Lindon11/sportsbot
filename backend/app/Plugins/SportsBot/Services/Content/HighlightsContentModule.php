<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Models\SportsBotDelivery;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotHighlightSent;
use App\Plugins\SportsBot\Services\TheSportsDbClient;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use App\Plugins\SportsBot\Support\SportsBotSports;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        $candidates = [];
        $sentIds = $this->sentHighlightIds();
        $sportKeys = array_keys(SportsBotSports::all());
        $daysBack = 1;
        $providerTotal = 0;
        $emptyEventIds = 0;
        $duplicateEventIds = 0;
        $outsideWindow = 0;

        foreach ($sportKeys as $sportKey) {
            try {
                $leagueIds = $this->leagueIdsForSport($sportKey);
                foreach ($leagueIds as $leagueId) {
                    try {
                        $events = $this->provider->previousLeagueEvents((string) $leagueId);
                        foreach ($events as $event) {
                            $video = trim((string) ($event['strVideo'] ?? ''));

                            $eventId = trim((string) ($event['idEvent'] ?? ''));
                            if ($eventId === '') {
                                $emptyEventIds++;
                                continue;
                            }

                            if (isset($candidates[$eventId])) {
                                $duplicateEventIds++;
                                continue;
                            }

                            $eventDate = trim((string) ($event['dateEvent'] ?? ''));
                            if ($eventDate < Carbon::today()->subDays($daysBack)->toDateString()) {
                                $outsideWindow++;
                                continue;
                            }

                            $thumb = trim((string) ($event['strThumb'] ?? ''));
                            $home = trim((string) ($event['strHomeTeam'] ?? ''));
                            $away = trim((string) ($event['strAwayTeam'] ?? ''));
                            $homeScore = $event['intHomeScore'] ?? null;
                            $awayScore = $event['intAwayScore'] ?? null;
                            $score = $homeScore !== null && $awayScore !== null ? "{$homeScore}-{$awayScore}" : '';

                            $providerTotal++;
                            $candidates[$eventId] = [
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

        $highlights = [];
        $matchedTotal = 0;
        $alreadySentTotal = 0;
        $sentFixtures = $this->sentFixturesByEventId(array_keys($candidates));

        foreach ($candidates as $eventId => $highlight) {
            $fixtureEntry = $sentFixtures[$eventId] ?? null;
            if (!$fixtureEntry instanceof SportsBotFixtureQueue) {
                continue;
            }

            $matchedTotal++;
            if (isset($sentIds[$eventId])) {
                $alreadySentTotal++;
                continue;
            }

            $highlights[] = $this->withPostedFixtureMetadata($highlight, $fixtureEntry);
        }

        usort($highlights, fn (array $a, array $b): int => ($b['date'] ?? '') <=> ($a['date'] ?? ''));

        $summary = [
            'route_key' => TelegramRouteKeys::HIGHLIGHTS,
            'title' => 'Match Highlights',
            'highlights' => $highlights,
            'total' => count($highlights),
            'provider_total' => $providerTotal,
            'matched_total' => $matchedTotal,
            'filtered_unposted_total' => max(0, $providerTotal - $matchedTotal),
            'already_sent_total' => $alreadySentTotal,
            'filtered_out_total' => max(0, $providerTotal - count($highlights)),
            'filter' => [
                'source' => 'sportsbot_fixture_queue',
                'required_status' => SportsBotFixtureQueue::STATUS_SENT,
                'requires_sent_at' => true,
                'requires_delivery_proof' => [
                    'telegram_message_id',
                    'sportsbot_deliveries:discord',
                    'sportsbot_deliveries:discord_bot',
                ],
                'match_key' => 'event_id',
                'empty_event_ids' => $emptyEventIds,
                'duplicate_event_ids' => $duplicateEventIds,
                'outside_window' => $outsideWindow,
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];

        return $summary;
    }

    /**
     * @return array<string, bool>
     */
    private function sentHighlightIds(): array
    {
        $sentIds = [];

        try {
            if (Schema::hasTable('sportsbot_highlights_sent')) {
                foreach (SportsBotHighlightSent::query()
                    ->where('sent_at', '>', now()->subHours(36))
                    ->pluck('event_id')
                    ->all() as $eventId) {
                    $eventId = trim((string) $eventId);
                    if ($eventId !== '') {
                        $sentIds[$eventId] = true;
                    }
                }
            }
        } catch (Throwable $error) {
            Log::warning('sportsbot.highlights.sent_lookup_failed', [
                'error' => $error->getMessage(),
            ]);
        }

        $cached = Cache::get('sportsbot:highlights_sent', []);
        if (is_array($cached)) {
            foreach ($cached as $key => $value) {
                if ($value === false) {
                    continue;
                }

                $eventId = is_string($key) && !is_numeric($key)
                    ? $key
                    : (is_string($value) ? $value : '');
                $eventId = trim($eventId);

                if ($eventId !== '') {
                    $sentIds[$eventId] = true;
                }
            }
        }

        return $sentIds;
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
        $highlights = array_slice($summary['highlights'] ?? [], 0, 10);
        $currentLeague = null;

        foreach ($highlights as $h) {
            $leagueName = trim((string) ($h['league'] ?? ''));
            $leagueChanged = $leagueName !== '' && $leagueName !== $currentLeague;

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
                if ($path === '' || !is_file($path)) {
                    continue;
                }

                // Only add league header after result card succeeds
                if ($leagueChanged) {
                    $currentLeague = $leagueName;
                    try {
                        $leagueCard = $this->cards->leagueCard([
                            'name' => $leagueName,
                            'sport' => SportsBotSports::providerSport($h['sport_key']),
                            'badge' => $h['league_badge'] ?? '',
                            'logo' => $h['league_badge'] ?? '',
                            'date' => $h['date'] ?? '',
                        ], $cardVersion, ['route_key' => TelegramRouteKeys::HIGHLIGHTS]);
                        $leaguePath = (string) ($leagueCard['path'] ?? '');
                        if ($leaguePath !== '' && is_file($leaguePath)) {
                            $cards[] = [
                                'path' => $leaguePath,
                                'type' => 'league_header',
                                'event_id' => '',
                                'event_name' => $leagueName . ' Results',
                                'video_url' => '',
                            ];
                        }
                    } catch (Throwable) {
                    }
                }

                $cards[] = [
                    'path' => $path,
                    'type' => 'result',
                    'event_id' => (string) ($h['event_id'] ?? ''),
                    'event_name' => $h['event_name'],
                    'video_url' => $h['video_url'],
                    'data_url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)),
                ];
            } catch (Throwable) {
                continue;
            }
        }
        return $cards;
    }

    /**
     * @param array<int, string> $eventIds
     * @return array<string, SportsBotFixtureQueue>
     */
    private function sentFixturesByEventId(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $eventId): string => trim((string) $eventId),
            $eventIds
        ), static fn (string $eventId): bool => $eventId !== '')));

        if ($eventIds === []) {
            return [];
        }

        try {
            $rows = SportsBotFixtureQueue::query()
                ->whereIn('event_id', $eventIds)
                ->where('status', SportsBotFixtureQueue::STATUS_SENT)
                ->whereNotNull('sent_at')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->get();
        } catch (Throwable $error) {
            Log::warning('sportsbot.highlights.fixture_queue_lookup_failed', [
                'error' => $error->getMessage(),
            ]);

            return [];
        }

        $discordProofs = $this->discordDeliveryProofsForFixtures($rows->all(), $eventIds);
        $sent = [];
        foreach ($rows as $row) {
            $eventId = (string) $row->event_id;
            if ($eventId === '' || isset($sent[$eventId])) {
                continue;
            }

            $proof = $this->deliveryProofForFixture($row, $discordProofs);
            if ($proof === null) {
                continue;
            }

            $row->setAttribute('delivery_proof', $proof);
            if ($eventId !== '') {
                $sent[$eventId] = $row;
            }
        }

        return $sent;
    }

    /**
     * @param array<int, SportsBotFixtureQueue> $entries
     * @param array<int, string> $eventIds
     * @return array{by_queue_id:array<int,array<string,mixed>>,by_event_id:array<string,array<string,mixed>>}
     */
    private function discordDeliveryProofsForFixtures(array $entries, array $eventIds): array
    {
        $queueIds = [];
        foreach ($entries as $entry) {
            if ($entry instanceof SportsBotFixtureQueue) {
                $queueIds[] = (int) $entry->id;
            }
        }

        $queueLookup = array_fill_keys($queueIds, true);
        $eventLookup = array_fill_keys($eventIds, true);
        $proofs = [
            'by_queue_id' => [],
            'by_event_id' => [],
        ];

        if ($queueLookup === [] && $eventLookup === []) {
            return $proofs;
        }

        try {
            $deliveries = SportsBotDelivery::query()
                ->whereIn('platform', ['discord', 'discord_bot'])
                ->where('status', 'sent')
                ->whereNotNull('message_id')
                ->where(function ($query): void {
                    $query->where('sent_at', '>=', now()->subDays(3))
                        ->orWhere('created_at', '>=', now()->subDays(3));
                })
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(1000)
                ->get();
        } catch (Throwable $error) {
            Log::warning('sportsbot.highlights.discord_delivery_lookup_failed', [
                'error' => $error->getMessage(),
            ]);

            return $proofs;
        }

        foreach ($deliveries as $delivery) {
            $messageId = trim((string) ($delivery->message_id ?? ''));
            if ($messageId === '') {
                continue;
            }

            $payload = (array) ($delivery->payload ?? []);
            $queueId = (int) ($payload['fixture_queue_id'] ?? 0);
            $eventId = trim((string) ($payload['event_id'] ?? ''));

            if ($queueId > 0 && isset($queueLookup[$queueId]) && !isset($proofs['by_queue_id'][$queueId])) {
                $proofs['by_queue_id'][$queueId] = $this->deliveryProofFromDiscordDelivery($delivery);
            }

            if ($eventId !== '' && isset($eventLookup[$eventId]) && !isset($proofs['by_event_id'][$eventId])) {
                $proofs['by_event_id'][$eventId] = $this->deliveryProofFromDiscordDelivery($delivery);
            }
        }

        return $proofs;
    }

    /**
     * @param array{by_queue_id:array<int,array<string,mixed>>,by_event_id:array<string,array<string,mixed>>} $discordProofs
     * @return array<string,mixed>|null
     */
    private function deliveryProofForFixture(SportsBotFixtureQueue $entry, array $discordProofs): ?array
    {
        if ($entry->telegram_message_id !== null) {
            return [
                'source' => 'sportsbot_fixture_queue',
                'platform' => 'telegram',
                'message_id' => (string) $entry->telegram_message_id,
                'sent_at' => $entry->sent_at?->toIso8601String(),
            ];
        }

        $queueId = (int) $entry->id;
        $eventId = (string) $entry->event_id;

        return $discordProofs['by_queue_id'][$queueId]
            ?? $discordProofs['by_event_id'][$eventId]
            ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    private function deliveryProofFromDiscordDelivery(SportsBotDelivery $delivery): array
    {
        return [
            'source' => 'sportsbot_deliveries',
            'platform' => (string) $delivery->platform,
            'message_id' => (string) $delivery->message_id,
            'delivery_id' => (int) $delivery->id,
            'route_key' => (string) ($delivery->route_key ?? ''),
            'target' => (string) ($delivery->target ?? ''),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $highlight
     * @return array<string, mixed>
     */
    private function withPostedFixtureMetadata(array $highlight, SportsBotFixtureQueue $entry): array
    {
        $fixture = (array) ($entry->fixture_data ?? []);

        foreach ([
            'league' => ['league', 'strLeague'],
            'event_name' => ['event_name', 'strEvent'],
            'home_team' => ['home_team', 'strHomeTeam'],
            'away_team' => ['away_team', 'strAwayTeam'],
            'home_badge' => ['home_badge', 'strHomeTeamBadge', 'strHomeBadge'],
            'away_badge' => ['away_badge', 'strAwayTeamBadge', 'strAwayBadge'],
            'league_badge' => ['league_badge', 'strLeagueBadge'],
            'thumb' => ['event_thumb', 'strThumb'],
        ] as $highlightKey => $fixtureKeys) {
            if (trim((string) ($highlight[$highlightKey] ?? '')) !== '') {
                continue;
            }

            foreach ($fixtureKeys as $fixtureKey) {
                $value = trim((string) ($fixture[$fixtureKey] ?? ''));
                if ($value !== '') {
                    $highlight[$highlightKey] = $value;
                    break;
                }
            }
        }

        $highlight['fixture_queue_id'] = (int) $entry->id;
        $highlight['fixture_posted_at'] = $entry->sent_at?->toIso8601String();
        $proof = (array) ($entry->getAttribute('delivery_proof') ?? []);
        $highlight['fixture_message_id'] = $proof['message_id'] ?? $entry->telegram_message_id;
        $highlight['fixture_delivery_platform'] = (string) ($proof['platform'] ?? ($entry->telegram_message_id !== null ? 'telegram' : ''));
        $highlight['fixture_delivery_proof'] = $proof;
        $highlight['fixture_telegram_message_id'] = $entry->telegram_message_id;
        $highlight['fixture_route_key'] = (string) ($entry->route_key ?? '');
        $highlight['fixture_publish_date'] = $entry->publish_date?->toDateString();
        $highlight['posted_fixture_payload'] = $fixture;

        return $highlight;
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
