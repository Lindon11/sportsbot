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
        $urls = $this->fixtureSourceUrls($entry, array_merge(
            $this->scraperArray('broadcast_schedule_urls'),
            (array) config('plugins.SportsBot.scrapers.broadcast_schedule_urls', [])
        ));
        $discovery = $this->discoverPublicUrls($entry, array_merge([
            '{event_name} UK TV channel',
            '{home_team} vs {away_team} UK TV channel',
            '{home_team} v {away_team} on TV',
            '{home_team} {away_team} TV channel',
            '{event_name} live on TV UK',
            '{league} TV schedule {home_team} {away_team}',
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
            $text = $this->pageSearchText($xpath);
            $title = $this->title($xpath);
            $matchScore = $this->fixtureMatchScore($entry, $title . ' ' . $text);
            $window = $this->fixtureWindow($entry, $text);
            if ($window === '' && $matchScore < 0.45) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'skipped', 'error' => 'Schedule page did not mention fixture'];
                continue;
            }

            $searchText = $window !== '' ? $window : $this->bestSignalWindow($entry, $text);
            $channels = $this->findChannels($searchText, $knownChannels);
            $dateTime = $this->findDateTimeNearFixture($entry, $searchText);
            if ($channels === []) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'empty', 'error' => 'No TV channel found near fixture'];
                continue;
            }

            $eventTitle = $this->eventTitle($entry, $title);
            $fields = [
                'event_name' => $eventTitle,
                'venue' => $this->findVenue($searchText),
                'tv_channel' => $channels[0] ?? '',
                'tv_channels' => $channels,
            ] + $this->teamsFromTitle($title) + $this->normalizer->dateTimeFields($dateTime);

            $fields = $this->normalizer->sanitizeFields($fields);
            $confidence = $this->confidence($fields, min(0.72, 0.42 + ($matchScore * 0.24) + ($window !== '' ? 0.06 : 0.0) + ($dateTime !== '' ? 0.04 : 0.0)));
            if ($eventTitle === '' && $dateTime === '') {
                $confidence = min($confidence, count($channels) > 4 ? 0.78 : 0.82);
            }

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
                'match_score' => $matchScore,
                'channels_found' => $channels,
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

        if (preg_match('/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*\s+\d{1,2}\s+[A-Z][a-z]+\s+(?:20\d{2}\s+)?\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/i', $window, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b\d{1,2}\s+[A-Z][a-z]+\s+(?:20\d{2}\s+)?\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/i', $window, $matches) === 1) {
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

        $homePosition = $this->firstRawPosition($text, $this->fixtureAliases((string) ($fixture['home_team'] ?? '')));
        $awayPosition = $this->firstRawPosition($text, $this->fixtureAliases((string) ($fixture['away_team'] ?? '')));
        if ($homePosition !== null && $awayPosition !== null && abs($homePosition - $awayPosition) <= 1200) {
            $start = max(0, min($homePosition, $awayPosition) - 420);
            $length = abs($homePosition - $awayPosition) + 1200;

            return substr($text, $start, $length);
        }

        foreach ($needles as $needle) {
            $position = $this->firstRawPosition($text, $this->fixtureAliases((string) $needle));
            if ($position !== null) {
                return substr($text, max(0, $position - 320), 900);
            }
        }

        return '';
    }

    private function bestSignalWindow(SportsBotFixtureQueue $entry, string $text): string
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        foreach (['event_name', 'home_team', 'away_team', 'league'] as $key) {
            $position = $this->firstRawPosition($text, $this->fixtureAliases((string) ($fixture[$key] ?? '')));
            if ($position !== null) {
                return substr($text, max(0, $position - 400), 1200);
            }
        }

        return substr($text, 0, 1800);
    }

    private function eventTitle(SportsBotFixtureQueue $entry, string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (preg_match('/\b(?:tv guide|sports tv schedules?|live sport on tv|watch live|fixtures?|schedule)\b/i', $title) === 1) {
            return '';
        }

        $fixture = (array) ($entry->fixture_data ?? []);
        foreach (['event_name', 'home_team', 'away_team'] as $key) {
            if ($this->hasAnyAlias($title, $this->fixtureAliases((string) ($fixture[$key] ?? '')))) {
                return $title;
            }
        }

        return '';
    }

    private function fixtureMatchScore(SportsBotFixtureQueue $entry, string $text): float
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $event = $this->hasAnyAlias($text, $this->fixtureAliases((string) ($fixture['event_name'] ?? '')));
        $home = $this->hasAnyAlias($text, $this->fixtureAliases((string) ($fixture['home_team'] ?? '')));
        $away = $this->hasAnyAlias($text, $this->fixtureAliases((string) ($fixture['away_team'] ?? '')));
        $league = $this->hasAnyAlias($text, $this->fixtureAliases((string) ($fixture['league'] ?? '')));

        if ($home && $away) {
            return 1.0;
        }

        if ($event) {
            return $league ? 0.9 : 0.78;
        }

        if (($home || $away) && $league) {
            return 0.62;
        }

        return $league ? 0.42 : 0.0;
    }

    /**
     * @param array<int, string> $aliases
     */
    private function hasAnyAlias(string $text, array $aliases): bool
    {
        return $this->firstPosition($text, $aliases) !== null;
    }

    /**
     * @param array<int, string> $aliases
     */
    private function firstPosition(string $text, array $aliases): ?int
    {
        $haystack = $this->normalizedText($text);

        foreach ($aliases as $alias) {
            $needle = $this->normalizedText($alias);
            if ($needle === '' || mb_strlen($needle) < 3) {
                continue;
            }

            $position = strpos($haystack, $needle);
            if ($position !== false) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $aliases
     */
    private function firstRawPosition(string $text, array $aliases): ?int
    {
        $best = null;

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '' || mb_strlen($alias) < 3) {
                continue;
            }

            $position = stripos($text, $alias);
            if ($position !== false) {
                $best = min($best ?? PHP_INT_MAX, $position);
            }
        }

        return $best === null ? null : $best;
    }

    /**
     * @return array<int, string>
     */
    private function fixtureAliases(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $aliases = [$value];
        $withoutBrackets = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $value) ?? $value);
        if ($withoutBrackets !== '') {
            $aliases[] = $withoutBrackets;
        }

        foreach (preg_split('/\s+(?:vs\.?|v)\s+/i', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $aliases[] = $part;
            }
        }

        $aliases[] = preg_replace('/\b(?:fc|afc|cf|club|the)\b/i', '', $withoutBrackets) ?? '';

        return array_values(array_unique(array_filter(array_map(
            static fn (string $alias): string => trim(preg_replace('/\s+/', ' ', $alias) ?? $alias),
            $aliases
        ))));
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
            // UK
            '/\bSky Sports(?:\+| Plus)?(?:\s+(?:Main Event|Premier League|Football|Cricket|Golf|F1|Mix|Arena|Action|Racing|Tennis|News))?\b/i',
            '/\bSky Sports Ultra HDR\b/i',
            '/\bTNT Sports(?:\s+(?:1|2|3|4|5|Ultimate|Box Office))?\b/i',
            '/\b(?:Premier Sports(?:\s+(?:1|2|Player|ROI))?|Racing TV|Eurosport\s+(?:1|2))\b/i',
            '/\b(?:BBC One|BBC Two|BBC Three|BBC Four|BBC Scotland|BBC Wales|BBC iPlayer|BBC Sport Website|BBC Red Button)\b/i',
            '/\b(?:ITV1|ITV4|ITVX|STV|Channel 4|Channel 5|S4C|TG4|RTE(?:\s*2)?)\b/i',
            '/\b(?:Virgin Media (?:One|Two|Three)|UKTV)\b/i',
            '/\b(?:DAZN(?: UK| Canada| Spain| Germany| Japan| Italy| Portugal| Belgium)?)\b/i',
            '/\b(?:Viaplay(?: UK| Sweden| Norway| Denmark| Finland| Netherlands| Poland)?)\b/i',
            '/\bdiscovery\+\b/i',
            '/\bAmazon Prime Video(?: Australia)?\b/i',
            '/\bApple TV\+\b/i',
            '/\bUFC Fight Pass\b/i',
            '/\bYouTube\b/i',
            '/\bNetflix\b/i',
            '/\bDisney\+\b/i',
            '/\bParamount\+\b/i',
            '/\bHBO(?: Max)?\b/i',
            '/\bShowtime\b/i',
            '/\bStarz\b/i',
            // Canada
            '/\bTSN(?:\s*(?:1|2|3|4|5|\+))?\b/i',
            '/\bSportsnet(?:\s+(?:One|360|1))?\b/i',
            '/\b(?:SN(?:\s*360)?|SN1|SN Now)\b/i',
            '/\bCBC\s+(?:Sports|Gem)\b/i',
            '/\b(?:RDS(?:\s*2| Info)?|TVA\s+Sports(?:\s*2)?|ATG TV)\b/i',
            // US
            '/\bESPN(?:\s*\+|2|3| Deportes)?\b/i',
            '/\b(?:Fox\s+Sports\s+(?:1|2)|FS1|FS2|Fox Soccer Plus|Fox Deportes)\b/i',
            '/\b(?:NBC\s+Sports(?:\s+(?:Network|California|Chicago|Boston|Philadelphia|Washington|Bay Area))?|USA\s+Network)\b/i',
            '/\b(?:CBS\s+Sports\s+Network|CBS|ABC)\b/i',
            '/\b(?:NFL\s+(?:Network|Sunday Ticket|RedZone)|NBA\s+TV|NHL\s+Network|MLB\s+Network|MLS\s+Season\s+Pass)\b/i',
            '/\b(?:Golf\s+Channel|Tennis\s+Channel|TNT|TBS|Peacock(?: Premium)?)\b/i',
            '/\b(?:Big\s+Ten\s+Network|BTN|ACC\s+Network|ACCN|SEC\s+Network|SECN|Pac-12\s+Network|Longhorn\s+Network)\b/i',
            '/\b(?:The\s+CW|truTV|MSG\s+Network|NESN|YES\s+Network|SNY|Marquee\s+Sports)\b/i',
            '/\bBally\s+Sports(?:\s+(?:Arizona|Detroit|Florida|Great\s+Lakes|Indiana|Kansas\s+City|Midwest|New\s+Orleans|North|Ohio|Oklahoma|San\s+Diego|SoCal|South|Southeast|Southwest|Sun|West|Wisconsin))?\b/i',
            '/\b(?:Altitude\s+Sports|Root\s+Sports|MASN|SportsTime\s+Ohio|Mid-Atlantic\s+Sports)\b/i',
            // Streaming
            '/\b(?:Fubo\s+TV(?: Sports)?|Sling\s+(?:TV|Orange|Blue)|YouTube\s+TV|Hulu\s*\+\s*Live\s+TV|DirecTV\s+(?:Stream)?)\b/i',
            '/\b(?:Optus\s+Sport|Kayo\s+(?:Sports|Freebies)|Foxtel|Stan\s+Sport|Watch\s+(?:AFL|NRL))\b/i',
            '/\b(?:7plus|9Now|10\s+Play|SBS\s+On\s+Demand|ABC\s+iView)\b/i',
            '/\b(?:SonyLIV|Hotstar|StarHub|Singtel|Astro)\b/i',
            // SuperSport / Africa
            '/\bSuperSport(?:\s+(?:\d{1,2}|Maximo))?\b/i',
            '/\b(?:DSTV|beIN\s+Sports(?:\s+[1-4])?)\b/i',
            // Australia
            '/\bFox\s+Sports\s+Australia\b/i',
            '/\bESPN\s+Australia\b/i',
            // Europe
            '/\bSky\s+Sport(?:\s+(?:Austria|Germany|Italia|Mexico|New\s+Zealand|Australia))?\b/i',
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
            // UK
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
            'sky sports tennis' => 'Sky Sports Tennis',
            'sky sports ultra hdr' => 'Sky Sports Ultra HDR',
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
            'discovery+' => 'discovery+',
            'amazon prime video' => 'Amazon Prime Video',
            'apple tv plus' => 'Apple TV+',
            'apple tv+' => 'Apple TV+',
            'ufc fight pass' => 'UFC Fight Pass',
            'youtube' => 'YouTube',
            // Canada
            'tsn' => 'TSN',
            'tsn1' => 'TSN1',
            'tsn2' => 'TSN2',
            'tsn3' => 'TSN3',
            'tsn4' => 'TSN4',
            'tsn5' => 'TSN5',
            'sportsnet' => 'Sportsnet',
            'sportsnet one' => 'Sportsnet One',
            'sportsnet 360' => 'Sportsnet 360',
            'sn1' => 'SN1',
            'sn360' => 'SN360',
            'cbc sports' => 'CBC Sports',
            'cbc gem' => 'CBC Gem',
            'rds' => 'RDS',
            'rds2' => 'RDS2',
            'tva sports' => 'TVA Sports',
            'tva sports 2' => 'TVA Sports 2',
            // US
            'espn' => 'ESPN',
            'espn2' => 'ESPN2',
            'espn plus' => 'ESPN+',
            'fox sports 1' => 'FS1',
            'fox sports 2' => 'FS2',
            'fs1' => 'FS1',
            'fs2' => 'FS2',
            'nbc sports' => 'NBC Sports',
            'usa network' => 'USA Network',
            'cbs sports network' => 'CBS Sports Network',
            'cbs' => 'CBS',
            'abc' => 'ABC',
            'nfl network' => 'NFL Network',
            'nba tv' => 'NBA TV',
            'nhl network' => 'NHL Network',
            'mlb network' => 'MLB Network',
            'golf channel' => 'Golf Channel',
            'tnt' => 'TNT',
            'tbs' => 'TBS',
            'peacock' => 'Peacock',
            'big ten network' => 'Big Ten Network',
            'btn' => 'BTN',
            'acc network' => 'ACC Network',
            'accn' => 'ACCN',
            'sec network' => 'SEC Network',
            'secn' => 'SECN',
            'the cw' => 'The CW',
            'trutv' => 'truTV',
            // Streaming
            'fubo tv' => 'Fubo TV',
            'fubo sports' => 'Fubo Sports',
            'sling tv' => 'Sling TV',
            'sling orange' => 'Sling Orange',
            'sling blue' => 'Sling Blue',
            'youtube tv' => 'YouTube TV',
            'hulu + live tv' => 'Hulu + Live TV',
            'hulu plus live tv' => 'Hulu + Live TV',
            'direc tv stream' => 'DirecTV Stream',
            'direc tv' => 'DirecTV',
            'netflix' => 'Netflix',
            'disney plus' => 'Disney+',
            'disney+' => 'Disney+',
            'paramount plus' => 'Paramount+',
            'paramount+' => 'Paramount+',
            'hbo max' => 'HBO Max',
            'hbo' => 'HBO',
            'showtime' => 'Showtime',
            'starz' => 'Starz',
            'peacock premium' => 'Peacock Premium',
            'peacock' => 'Peacock',
            'ufc fight pass' => 'UFC Fight Pass',
            'wwe network' => 'WWE Network',
            'triller tv' => 'Triller TV',
            'fite tv' => 'FITE TV',
            // Australia
            'optus sport' => 'Optus Sport',
            'kayo sports' => 'Kayo Sports',
            'foxtel' => 'Foxtel',
            'stan sport' => 'Stan Sport',
            'watch afl' => 'Watch AFL',
            'watch nrl' => 'Watch NRL',
            'kayo freebies' => 'Kayo Freebies',
            '7plus' => '7plus',
            '9now' => '9Now',
            '10 play' => '10 Play',
            'sbs on demand' => 'SBS On Demand',
            'abc iview' => 'ABC iView',
            'fox sports australia' => 'Fox Sports Australia',
            'espn australia' => 'ESPN Australia',
            'beinsports australia' => 'beIN Sports Australia',
            // Sky regionals
            'sky sports australia' => 'Sky Sports Australia',
            'sky sports new zealand' => 'Sky Sports New Zealand',
            'sky sports italy' => 'Sky Sports Italy',
            'sky sports germany' => 'Sky Sports Germany',
            'sky sports mexico' => 'Sky Sports Mexico',
            'sky go' => 'Sky Go',
            // Africa
            'supersport' => 'SuperSport',
            'supersport maximo' => 'SuperSport Maximo',
            'dstv' => 'DSTV',
            'bein sports' => 'beIN Sports',
            // Regional US
            'mlb network' => 'MLB Network',
            'nba tv' => 'NBA TV',
            'nhl network' => 'NHL Network',
            'nfl network' => 'NFL Network',
            'nfl sunday ticket' => 'NFL Sunday Ticket',
            'nfl redzone' => 'NFL RedZone',
            'mls season pass' => 'MLS Season Pass',
            'golf channel' => 'Golf Channel',
            'tennis channel' => 'Tennis Channel',
            'msg network' => 'MSG Network',
            'nesn' => 'NESN',
            'yes network' => 'YES Network',
            'sny' => 'SNY',
            'marquee sports' => 'Marquee Sports',
            'bally sports' => 'Bally Sports',
            'altitude sports' => 'Altitude Sports',
            'root sports' => 'Root Sports',
            'masn' => 'MASN',
            'sportstime ohio' => 'SportsTime Ohio',
            'mid-atlantic sports' => 'Mid-Atlantic Sports',
            // Europe
            'tvspielfilm' => 'TV Spielfilm',
            'fernsehserien' => 'Fernsehserien',
            'programme tv' => 'Programme TV',
            'telerama' => 'Télérama',
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
