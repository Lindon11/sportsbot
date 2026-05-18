<?php

namespace App\Plugins\SportsBot\Services\Scrapers;

use App\Plugins\SportsBot\Contracts\ScraperProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\ScraperResultNormalizer;
use App\Plugins\SportsBot\Support\SportsBotSports;

class RugbyLeagueTvScraper extends AbstractPublicPageScraper implements ScraperProviderInterface
{
    public function __construct(
        private readonly ScraperResultNormalizer $normalizer = new ScraperResultNormalizer(),
    ) {
    }

    public function key(): string
    {
        return 'rugby_league_tv';
    }

    public function supports(SportsBotFixtureQueue $entry, string $action): bool
    {
        $sport = SportsBotSports::normalize((string) $entry->sport_key);

        return $sport === 'rugby' && in_array($action, ['find_tv_info', 'refresh'], true);
    }

    public function scrape(SportsBotFixtureQueue $entry, string $action): array
    {
        $urls = $this->fixtureSourceUrls($entry, $this->scraperArray('rugby_league_tv_urls'));
        $discovery = $this->discoverPublicUrls($entry, array_merge([
            '{event_name} rugby league TV channel',
            '{home_team} vs {away_team} rugby league on TV',
            '{league} {event_name} live stream UK',
            '{home_team} {away_team} Rugby League TV schedule',
        ], $this->scraperArray('rugby_league_tv_search_queries')));
        $urls = array_values(array_unique(array_merge($urls, $discovery['urls'])));
        $knownChannels = $this->knownChannels();
        $logs = $discovery['logs'];
        $results = [];

        foreach ($urls as $url) {
            $page = $this->fetchPublicHtml($url);
            if ($page === null) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'failed', 'error' => 'No public HTML response'];
                continue;
            }

            $xpath = $this->dom($page['html']);
            $text = $this->visibleText($xpath);
            $title = $this->title($xpath);

            if (!$this->titleMatchesFixture($title . ' ' . $text, $entry)) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'skipped', 'error' => 'Schedule page did not mention fixture'];
                continue;
            }

            $channels = $this->findChannels($text, $knownChannels);
            $dateTime = $this->findDateTimeNearFixture($entry, $text);

            $fields = [
                'event_name' => $title,
                'venue' => $this->findVenue($text),
                'tv_channel' => $channels[0] ?? '',
                'tv_channels' => $channels,
            ] + $this->teamsFromTitle($title) + $this->normalizer->dateTimeFields($dateTime);

            $fields = $this->normalizer->sanitizeFields($fields);
            $confidence = $this->confidence($fields, $channels !== [] ? 0.5 : 0.25);

            $results[] = [
                'provider' => $this->key(),
                'source_url' => $url,
                'confidence' => $confidence,
                'fields' => $fields,
                'logs' => [[
                    'source_url' => $url,
                    'checked_at' => now()->toISOString(),
                    'status' => 'found',
                    'confidence' => $confidence,
                    'fields_found' => array_keys(array_filter($fields, fn ($v) => $v !== '' && $v !== [])),
                    'channels_found' => $channels,
                ]],
            ];

            if ($confidence >= 0.5) {
                break;
            }
        }

        if ($results === [] && $action === 'find_tv_info') {
            $fixture = (array) ($entry->fixture_data ?? []);
            $league = (string) ($fixture['league'] ?? '');
            $isRflChampionship = str_contains($league, 'RFL Championship');
            $isSuperLeague = str_contains($league, 'Super League');
            $isPremiership = str_contains($league, 'Premiership') || str_contains($league, 'Prem Rugby');
            $isNrl = str_contains($league, 'National Rugby League');
            $isStateOfOrigin = str_contains($league, 'State of Origin');
            $isWomen = str_contains($league, 'Women');

            if ($isRflChampionship) {
                $results[] = [
                    'provider' => $this->key(),
                    'confidence' => 0.35,
                    'fields' => [
                        'tv_channel' => 'OurLeague',
                        'tv_channels' => ['OurLeague'],
                    ],
                    'source_url' => 'https://www.rugby-league.com/fixtures',
                ];
                $logs[] = ['source_url' => 'https://www.rugby-league.com/fixtures', 'checked_at' => now()->toISOString(), 'status' => 'fallback', 'confidence' => 0.35, 'fields_found' => ['tv_channel'], 'channels_found' => ['OurLeague']];
            } elseif ($isSuperLeague) {
                $results[] = [
                    'provider' => $this->key(),
                    'confidence' => 0.35,
                    'fields' => [
                        'tv_channel' => 'Sky Sports Arena',
                        'tv_channels' => ['Sky Sports Arena', 'SuperLeague+'],
                    ],
                    'source_url' => 'https://www.superleague.co.uk/fixtures',
                ];
                $logs[] = ['source_url' => 'https://www.superleague.co.uk/fixtures', 'checked_at' => now()->toISOString(), 'status' => 'fallback', 'confidence' => 0.35, 'fields_found' => ['tv_channel'], 'channels_found' => ['Sky Sports Arena', 'SuperLeague+']];
            } elseif ($isPremiership) {
                $results[] = [
                    'provider' => $this->key(),
                    'confidence' => 0.35,
                    'fields' => [
                        'tv_channel' => 'TNT Sports',
                        'tv_channels' => ['TNT Sports', 'Premiership Rugby TV'],
                    ],
                    'source_url' => 'https://www.premiershiprugby.com/fixtures',
                ];
                $logs[] = ['source_url' => 'https://www.premiershiprugby.com/fixtures', 'checked_at' => now()->toISOString(), 'status' => 'fallback', 'confidence' => 0.35, 'fields_found' => ['tv_channel'], 'channels_found' => ['TNT Sports', 'Premiership Rugby TV']];
            } elseif ($isNrl) {
                $results[] = [
                    'provider' => $this->key(),
                    'confidence' => 0.35,
                    'fields' => [
                        'tv_channel' => 'Sky Sports Arena',
                        'tv_channels' => ['Sky Sports Arena', 'Fox League', 'Kayo Sports'],
                    ],
                    'source_url' => 'https://www.nrl.com/draw/',
                ];
                $logs[] = ['source_url' => 'https://www.nrl.com/draw/', 'checked_at' => now()->toISOString(), 'status' => 'fallback', 'confidence' => 0.35, 'fields_found' => ['tv_channel'], 'channels_found' => ['Sky Sports Arena', 'Fox League']];
            } elseif ($isStateOfOrigin) {
                $results[] = [
                    'provider' => $this->key(),
                    'confidence' => 0.35,
                    'fields' => [
                        'tv_channel' => 'Sky Sports Arena',
                        'tv_channels' => ['Sky Sports Arena', 'Channel 9', 'Kayo Sports'],
                    ],
                    'source_url' => 'https://www.nrl.com/draw/state-of-origin/',
                ];
                $logs[] = ['source_url' => 'https://www.nrl.com/draw/state-of-origin/', 'checked_at' => now()->toISOString(), 'status' => 'fallback', 'confidence' => 0.35, 'fields_found' => ['tv_channel'], 'channels_found' => ['Sky Sports Arena', 'Channel 9']];
            } elseif ($isWomen) {
                $results[] = [
                    'provider' => $this->key(),
                    'confidence' => 0.35,
                    'fields' => [
                        'tv_channel' => 'BBC iPlayer',
                        'tv_channels' => ['BBC iPlayer', 'BBC Red Button', 'BBC Sport Website'],
                    ],
                    'source_url' => 'https://www.bbc.co.uk/sport/rugby-union/scores-fixtures',
                ];
                $logs[] = ['source_url' => 'https://www.bbc.co.uk/sport/rugby-union/scores-fixtures', 'checked_at' => now()->toISOString(), 'status' => 'fallback', 'confidence' => 0.35, 'fields_found' => ['tv_channel'], 'channels_found' => ['BBC iPlayer', 'BBC Red Button']];
            }
        }

        return [
            'provider' => $this->key(),
            'action' => $action,
            'results' => $results,
            'logs' => $logs,
        ];
    }

    private function knownChannels(): array
    {
        $channels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $channel): string => trim((string) $channel),
            (array) (new \App\Plugins\SportsBot\Services\SportsBotSettingsService())->get('tv_channels', config('plugins.SportsBot.tv.channels', []))
        ))));

        return $channels !== [] ? $channels : (array) config('plugins.SportsBot.tv.channels', []);
    }

    private function findChannels(string $text, array $knownChannels): array
    {
        $matched = [];

        foreach ($knownChannels as $channel) {
            foreach ($this->channelNeedles($channel) as $needle) {
                if (str_contains(strtolower($text), $needle)) {
                    $matched[$channel] = true;
                    break;
                }
            }
        }

        $patternMatches = $this->channelPatternMatches($text);
        foreach ($patternMatches as $label => $pos) {
            $matched[$label] = true;
        }

        $ordered = [];
        foreach ($knownChannels as $channel) {
            if (isset($matched[$channel])) {
                $ordered[] = $channel;
            }
        }
        foreach ($patternMatches as $label => $pos) {
            if (!isset($matched[$label])) {
                $ordered[] = $label;
            }
        }

        return array_values($ordered);
    }

    private function channelNeedles(string $channel): array
    {
        $normalized = $this->normalizeForMatch($channel);
        $label = $this->normalizeForMatch($this->channelLabel($channel));
        $needles = [$normalized, $label];

        $compact = str_replace([' ', '+'], '', $normalized);
        if ($compact !== $normalized) {
            $needles[] = $compact;
        }

        return array_values(array_unique($needles));
    }

    private function channelPatternMatches(string $text): array
    {
        $channels = [];
        $patterns = [
            '/\bSky Sports(?:\s+(?:Arena|Action|Mix|Main Event))?\b/i',
            '/\bPremier Sports(?:\s+(?:1|2|Player))?\b/i',
            '/\b(?:BBC(?:\s+Red\s+Button|iPlayer|Two|One|Three|Sport\s+Website)?)\b/i',
            '/\b(?:Channel\s+4|ITV4|ITVX|Viaplay|dazn)\b/i',
            '/\b(?:SuperLeague\+|OurLeague|RugbyPass|The\s+Sportsman|TNT\s+Sports)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[0] ?? [] as $match) {
                $label = trim((string) ($match[0] ?? ''));
                if ($label !== '') {
                    $channels[$label] = min($channels[$label] ?? PHP_INT_MAX, (int) ($match[1] ?? PHP_INT_MAX));
                }
            }
        }

        return $channels;
    }

    private function channelLabel(string $channel): string
    {
        $normalized = $this->normalizeForMatch($channel);
        $map = [
            'sky sports arena' => 'Sky Sports Arena',
            'sky sports action' => 'Sky Sports Action',
            'sky sports mix' => 'Sky Sports Mix',
            'premier sports 1' => 'Premier Sports 1',
            'premier sports 2' => 'Premier Sports 2',
            'bbc red button' => 'BBC Red Button',
            'bbc iplayer' => 'BBC iPlayer',
            'bbc two' => 'BBC Two',
            'bbc one' => 'BBC One',
            'channel 4' => 'Channel 4',
            'itv4' => 'ITV4',
            'dazn' => 'DAZN',
            'tnt sports 1' => 'TNT Sports 1',
            'tnt sports 2' => 'TNT Sports 2',
            'tnt sports 3' => 'TNT Sports 3',
            'tnt sports 4' => 'TNT Sports 4',
            'superleague plus' => 'SuperLeague+',
            'ourleague' => 'OurLeague',
            'the sportsman' => 'The Sportsman',
            'viaplay' => 'Viaplay',
        ];

        return $map[$normalized] ?? ucwords($normalized);
    }

    private function normalizeForMatch(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower($text);
        $text = str_replace(['_', '-'], ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function findDateTimeNearFixture(SportsBotFixtureQueue $entry, string $text): string
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $home = strtolower((string) ($fixture['home_team'] ?? ''));
        $away = strtolower((string) ($fixture['away_team'] ?? ''));
        $lines = preg_split('/\r\n|\r|\n/', $text);

        $bestLine = '';
        $bestScore = 0;

        foreach ($lines as $i => $line) {
            $lowerLine = strtolower($line);
            $score = 0;
            if ($home !== '' && str_contains($lowerLine, $home)) $score += 3;
            if ($away !== '' && str_contains($lowerLine, $away)) $score += 3;
            if (preg_match('/\b(\d{1,2}:\d{2})\b/', $line)) $score += 2;
            if (preg_match('/\b(\d{1,2}(?:st|nd|rd|th)?\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*)/i', $line)) $score += 2;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLine = $line;
                if ($i > 0) {
                    $candidate = trim($lines[$i - 1] . ' ' . $line);
                    if (preg_match('/\d{1,2}:\d{2}/', $candidate)) {
                        $bestLine = $candidate;
                    }
                }
            }
        }

        return $bestLine;
    }

    private function findVenue(string $text): string
    {
        if (preg_match('/\b(?:at|Venue:)\s+([A-Z][A-Za-z0-9 .\'-]{3,80})(?:\s+on|\s+at|[.,]|$)/', $text, $matches) === 1) {
            return trim($matches[1]);
        }
        return '';
    }

    private function teamsFromTitle(string $title): array
    {
        if (preg_match('/(.+?)\s+(?:vs\.?|v)\s+(.+?)(?:\s+[-|:]\s+|$)/i', $title, $matches) !== 1) {
            return [];
        }
        return [
            'home_team' => trim($matches[1]),
            'away_team' => trim($matches[2]),
        ];
    }

}
