<?php

namespace App\Plugins\SportsBot\Services\Scrapers;

use App\Plugins\SportsBot\Contracts\ScraperProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\ScraperResultNormalizer;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;

class BroadcastScheduleScraper extends AbstractPublicPageScraper implements ScraperProviderInterface
{
    public function __construct(
        private readonly ScraperResultNormalizer $normalizer = new ScraperResultNormalizer(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    public function key(): string
    {
        return 'broadcast_schedule';
    }

    public function supports(SportsBotFixtureQueue $entry, string $action): bool
    {
        return in_array($action, ['find_tv_info', 'refresh'], true);
    }

    public function scrape(SportsBotFixtureQueue $entry, string $action): array
    {
        $urls = $this->fixtureSourceUrls($entry, $this->scraperArray('broadcast_schedule_urls'));
        $discovery = $this->discoverPublicUrls($entry, array_merge([
            '{event_name} UK TV channel',
            '{home_team} vs {away_team} UK TV channel',
            '{event_name} live on TV UK',
            '{league} {event_name} TV schedule UK',
        ], $this->scraperArray('broadcast_schedule_search_queries')));
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
            $window = $this->fixtureWindow($entry, $text);
            if ($window === '' && !$this->titleMatchesFixture($title . ' ' . $text, $entry)) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'skipped', 'error' => 'Schedule page did not mention fixture'];
                continue;
            }

            $searchText = $window !== '' ? $window : $text;
            $channels = $this->findChannels($searchText, $knownChannels);
            $dateTime = $this->findDateTimeNearFixture($entry, $searchText);
            if ($channels === [] && $dateTime === '') {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'empty', 'error' => 'No TV channel or date/time found near fixture'];
                continue;
            }

            $fields = [
                'event_name' => $title,
                'venue' => $this->findVenue($searchText),
                'tv_channel' => $channels[0] ?? '',
                'tv_channels' => $channels,
            ] + $this->teamsFromTitle($title) + $this->normalizer->dateTimeFields($dateTime);

            $fields = $this->normalizer->sanitizeFields($fields);
            $confidence = $this->confidence($fields, $channels !== [] ? 0.45 : 0.2);

            $results[] = [
                'provider' => $this->key(),
                'source_url' => $url,
                'confidence' => $confidence,
                'fields' => $fields,
            ];
            $logs[] = [
                'source_url' => $url,
                'checked_at' => now()->toISOString(),
                'status' => $fields === [] ? 'empty' : 'found',
                'confidence' => $confidence,
                'fields_found' => array_keys($fields),
            ];
        }

        return [
            'provider' => $this->key(),
            'action' => $action,
            'results' => $results,
            'logs' => $logs,
        ];
    }

    /**
     * @param array<int, string> $knownChannels
     * @return array<int, string>
     */
    private function findChannels(string $text, array $knownChannels): array
    {
        $found = [];
        $positions = [];
        $normalizedText = $this->normalizeForMatch($text);

        foreach ($knownChannels as $channel) {
            $channel = trim((string) $channel);
            if ($channel === '') {
                continue;
            }

            $label = $this->channelLabel($channel);
            foreach ($this->channelNeedles($channel) as $needle) {
                $position = $needle !== '' ? strpos($normalizedText, $needle) : false;
                if ($position !== false) {
                    $found[strtolower($label)] = $label;
                    $positions[strtolower($label)] = min($positions[strtolower($label)] ?? PHP_INT_MAX, $position);
                    break;
                }
            }
        }

        foreach ($this->channelPatternMatches($text) as $channel => $position) {
            if ($channel !== '') {
                $found[strtolower($channel)] = $channel;
                $positions[strtolower($channel)] = min($positions[strtolower($channel)] ?? PHP_INT_MAX, $position);
            }
        }

        uasort($found, static fn (string $left, string $right): int => ($positions[strtolower($left)] ?? PHP_INT_MAX) <=> ($positions[strtolower($right)] ?? PHP_INT_MAX));

        return array_slice(array_values($found), 0, 8);
    }

    private function findDateTimeNearFixture(SportsBotFixtureQueue $entry, string $text): string
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $needles = array_filter([
            $fixture['event_name'] ?? null,
            $fixture['home_team'] ?? null,
            $fixture['away_team'] ?? null,
        ], static fn (mixed $value): bool => trim((string) $value) !== '');

        $window = $text;
        foreach ($needles as $needle) {
            $position = stripos($text, (string) $needle);
            if ($position !== false) {
                $window = substr($text, max(0, $position - 240), 520);
                break;
            }
        }

        if (preg_match('/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\s+\d{1,2}\s+[A-Z][a-z]+\s+(?:20\d{2}\s+)?\d{1,2}:\d{2}\b/', $window, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b\d{1,2}[\/.-]\d{1,2}[\/.-]20\d{2}\s+\d{1,2}:\d{2}\b/', $window, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }

    private function fixtureWindow(SportsBotFixtureQueue $entry, string $text): string
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $needles = array_values(array_filter([
            $fixture['event_name'] ?? null,
            $fixture['home_team'] ?? null,
            $fixture['away_team'] ?? null,
            $fixture['league'] ?? null,
        ], static fn (mixed $value): bool => trim((string) $value) !== ''));

        if ($needles === []) {
            return '';
        }

        foreach ($needles as $needle) {
            $position = stripos($text, (string) $needle);
            if ($position !== false) {
                return substr($text, max(0, $position - 320), 900);
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function knownChannels(): array
    {
        $channels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $channel): string => trim((string) $channel),
            (array) $this->settings->get('tv_channels', config('plugins.SportsBot.tv.channels', []))
        ))));

        return $channels !== [] ? $channels : (array) config('plugins.SportsBot.tv.channels', []);
    }

    /**
     * @return array<int, string>
     */
    private function channelNeedles(string $channel): array
    {
        $normalized = $this->normalizeForMatch($channel);
        $label = $this->normalizeForMatch($this->channelLabel($channel));
        $needles = [$normalized, $label];

        $compact = str_replace([' ', '+'], '', $normalized);
        if ($compact !== $normalized) {
            $needles[] = $compact;
        }

        if (str_contains($normalized, 'tnt sports')) {
            $needles[] = str_replace('tnt sports', 'tntsports', $normalized);
        }

        if (str_contains($normalized, 'sky sports plus')) {
            $needles[] = 'sky sports+';
        }

        if (str_contains($normalized, 'bbc iplayer')) {
            $needles[] = 'iplayer';
        }

        if (str_contains($normalized, 'itvx')) {
            $needles[] = 'itv x';
        }

        return array_values(array_unique($needles));
    }

    /**
     * @return array<string, int>
     */
    private function channelPatternMatches(string $text): array
    {
        $channels = [];
        $patterns = [
            '/\bSky Sports(?:\+| Plus)?(?:\s+(?:Main Event|Premier League|Football|Cricket|Golf|F1|Mix|Arena|Action|Racing|Tennis|News))?\b/i',
            '/\bTNT Sports(?:\s+(?:1|2|3|4|Ultimate|Box Office))?\b/i',
            '/\b(?:Premier Sports(?:\s+(?:1|2|Player))?|Racing TV|Eurosport\s+(?:1|2))\b/i',
            '/\b(?:BBC One|BBC Two|BBC Three|BBC Four|BBC iPlayer|BBC Sport Website)\b/i',
            '/\b(?:ITV1|ITV4|ITVX|Channel 4|Channel 5|DAZN UK|discovery\+|Amazon Prime Video|UFC Fight Pass|YouTube)\b/i',
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

    private function normalizeForMatch(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower($text);
        $text = str_replace(['_', '-'], ' ', $text);
        $text = str_replace(['&', '+'], [' and ', ' plus '], $text);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function channelLabel(string $channel): string
    {
        $normalized = $this->normalizeForMatch($channel);
        $map = [
            'sky sports main event' => 'Sky Sports Main Event',
            'sky sports premier league' => 'Sky Sports Premier League',
            'sky sports football' => 'Sky Sports Football',
            'sky sports cricket' => 'Sky Sports Cricket',
            'sky sports golf' => 'Sky Sports Golf',
            'sky sports f1' => 'Sky Sports F1',
            'sky sports arena' => 'Sky Sports Arena',
            'sky sports action' => 'Sky Sports Action',
            'sky sports mix' => 'Sky Sports Mix',
            'sky sports plus' => 'Sky Sports+',
            'sky sports racing' => 'Sky Sports Racing',
            'tnt sports 1' => 'TNT Sports 1',
            'tnt sports 2' => 'TNT Sports 2',
            'tnt sports 3' => 'TNT Sports 3',
            'tnt sports 4' => 'TNT Sports 4',
            'tnt sports ultimate' => 'TNT Sports Ultimate',
            'eurosport 1' => 'Eurosport 1',
            'eurosport 2' => 'Eurosport 2',
            'bbc one' => 'BBC One',
            'bbc two' => 'BBC Two',
            'bbc three' => 'BBC Three',
            'bbc four' => 'BBC Four',
            'bbc iplayer' => 'BBC iPlayer',
            'itv1' => 'ITV1',
            'itv4' => 'ITV4',
            'itvx' => 'ITVX',
            'channel 4' => 'Channel 4',
            'channel 5' => 'Channel 5',
            'premier sports 1' => 'Premier Sports 1',
            'premier sports 2' => 'Premier Sports 2',
            'dazn' => 'DAZN',
            'dazn uk' => 'DAZN UK',
            'racing tv' => 'Racing TV',
            'discovery plus' => 'discovery+',
            'amazon prime video' => 'Amazon Prime Video',
            'ufc fight pass' => 'UFC Fight Pass',
        ];

        return $map[$normalized] ?? ucwords($normalized);
    }

    /**
     * @return array<string, string>
     */
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

    private function findVenue(string $text): string
    {
        if (preg_match('/\b(?:at|Venue:)\s+([A-Z][A-Za-z0-9 .\'-]{3,80})(?:\s+on|\s+at|[.,]|$)/', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }
}
