<?php

namespace App\Plugins\SportsBot\Services\Scrapers;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractPublicPageScraper
{
    /**
     * @return array<int, string>
     */
    protected function fixtureSourceUrls(SportsBotFixtureQueue $entry, array $configUrls = []): array
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $payload = (array) ($entry->payload ?? []);
        $sources = [];

        foreach ([
            $fixture['source_url'] ?? null,
            $fixture['official_url'] ?? null,
            $fixture['event_url'] ?? null,
            $fixture['strWebsite'] ?? null,
            $payload['source_url'] ?? null,
        ] as $candidate) {
            $url = trim((string) $candidate);
            if ($url !== '') {
                $sources[] = $url;
            }
        }

        foreach ($configUrls as $template) {
            $url = $this->expandTemplate((string) $template, $entry);
            if ($url !== '') {
                $sources[] = $url;
            }
        }

        return array_values(array_unique(array_filter($sources, fn (string $url): bool => $this->isAllowedPublicUrl($url))));
    }

    /**
     * @param array<int, string> $queryTemplates
     * @return array{urls:array<int,string>,logs:array<int,array<string,mixed>>}
     */
    protected function discoverPublicUrls(SportsBotFixtureQueue $entry, array $queryTemplates): array
    {
        if (!(bool) $this->scraperConfig('search_enabled', true)) {
            return ['urls' => [], 'logs' => []];
        }

        $urls = [];
        $logs = [];
        $maxResults = max(1, (int) $this->scraperConfig('search_max_results', 5));
        $searchUrlTemplates = $this->scraperArray('search_urls');
        $legacySearchUrl = trim((string) $this->scraperConfig('search_url', ''));
        if ($searchUrlTemplates === [] && $legacySearchUrl !== '') {
            $searchUrlTemplates = [$legacySearchUrl];
        }
        if ($searchUrlTemplates === []) {
            return ['urls' => [], 'logs' => []];
        }

        foreach ($queryTemplates as $queryTemplate) {
            $query = $this->expandSearchTemplate((string) $queryTemplate, $entry);
            if (!$this->queryHasFixtureSignal($query, $entry)) {
                continue;
            }

            foreach ($searchUrlTemplates as $searchUrlTemplate) {
                $searchUrl = str_replace('{query}', rawurlencode($query), (string) $searchUrlTemplate);
                $page = $this->fetchSearchPage($searchUrl);
                if ($page === null) {
                    $logs[] = [
                        'source_url' => $searchUrl,
                        'query' => $query,
                        'checked_at' => now()->toISOString(),
                        'status' => 'search_failed',
                        'error' => 'Search page unavailable or protected',
                    ];
                    continue;
                }

                $found = $this->extractSearchResultUrls($page['body']);
                $accepted = 0;
                foreach ($found as $url) {
                    if (!$this->isAllowedPublicUrl($url)) {
                        continue;
                    }

                    $urls[$url] = $url;
                    $accepted++;
                    if (count($urls) >= $maxResults) {
                        break 3;
                    }
                }

                $logs[] = [
                    'source_url' => $searchUrl,
                    'query' => $query,
                    'checked_at' => now()->toISOString(),
                    'status' => 'searched',
                    'fields_found' => ['candidate_urls'],
                    'result_count' => $accepted,
                ];
            }
        }

        return ['urls' => array_values($urls), 'logs' => $logs];
    }

    /**
     * @return array{body:string,url:string,content_type:string}|null
     */
    protected function fetchSearchPage(string $url): ?array
    {
        if (!$this->isAllowedPublicUrl($url)) {
            return null;
        }

        try {
            $response = Http::timeout((int) $this->scraperConfig('timeout', 8))
                ->withHeaders([
                    'User-Agent' => (string) $this->scraperConfig('user_agent', 'SportsBot/1.0 public-page-enrichment'),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml,text/xml,application/rss+xml,application/json',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();
            if (trim($body) === '' || $this->looksRestricted($body)) {
                return null;
            }

            return [
                'body' => $body,
                'url' => $url,
                'content_type' => strtolower((string) $response->header('content-type', '')),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{html:string,url:string,content_type:string}|null
     */
    protected function fetchPublicHtml(string $url): ?array
    {
        if (!$this->isAllowedPublicUrl($url)) {
            return null;
        }

        try {
            $response = Http::timeout((int) $this->scraperConfig('timeout', 8))
                ->withHeaders([
                    'User-Agent' => (string) $this->scraperConfig('user_agent', 'SportsBot/1.0 public-page-enrichment'),
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $contentType = strtolower((string) $response->header('content-type', ''));
            if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml')) {
                return null;
            }

            $html = $response->body();
            if (trim($html) === '' || $this->looksRestricted($html)) {
                return null;
            }

            return ['html' => $html, 'url' => $url, 'content_type' => $contentType];
        } catch (Throwable) {
            return null;
        }
    }

    protected function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    protected function meta(DOMXPath $xpath, string $name): string
    {
        $name = strtolower($name);
        $queries = [
            "//meta[translate(@property, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='{$name}']/@content",
            "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='{$name}']/@content",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                return trim((string) $nodes->item(0)?->nodeValue);
            }
        }

        return '';
    }

    protected function title(DOMXPath $xpath): string
    {
        foreach (['og:title', 'twitter:title'] as $metaName) {
            $value = $this->meta($xpath, $metaName);
            if ($value !== '') {
                return $value;
            }
        }

        $nodes = $xpath->query('//title');

        return $nodes && $nodes->length > 0 ? trim((string) $nodes->item(0)?->textContent) : '';
    }

    protected function visibleText(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//body');
        $text = $nodes && $nodes->length > 0 ? (string) $nodes->item(0)?->textContent : '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    protected function pageSearchText(DOMXPath $xpath): string
    {
        $parts = [
            $this->title($xpath),
            $this->meta($xpath, 'description'),
            $this->meta($xpath, 'og:description'),
            $this->meta($xpath, 'twitter:description'),
            $this->visibleText($xpath),
        ];

        $scriptNodes = $xpath->query("//script[@type='application/ld+json' or @id='__NEXT_DATA__']");
        if ($scriptNodes) {
            foreach ($scriptNodes as $node) {
                $parts[] = $this->jsonText((string) $node->textContent);
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts))) ?? '');
    }

    protected function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if ($scheme === '' || $host === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme . '://' . $host . ($dir !== '' ? $dir : '') . '/' . $url;
    }

    protected function confidence(array $fields, float $base = 0.35): float
    {
        $score = $base + (count(array_filter($fields, static fn (mixed $value): bool => is_array($value) ? $value !== [] : trim((string) $value) !== '')) * 0.12);

        return max(0.0, min(0.95, $score));
    }

    protected function titleMatchesFixture(string $text, SportsBotFixtureQueue $entry): bool
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $needles = array_filter([
            $fixture['event_name'] ?? null,
            $fixture['home_team'] ?? null,
            $fixture['away_team'] ?? null,
            $fixture['league'] ?? null,
        ], static fn (mixed $value): bool => trim((string) $value) !== '');

        if ($needles === []) {
            return true;
        }

        $haystack = strtolower($text);
        foreach ($needles as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizedText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower($text);
        $text = str_replace(['_', '-', '–', '—', '+', '&'], [' ', ' ', ' ', ' ', ' plus ', ' and '], $text);
        $text = preg_replace('/[^\p{L}\p{N}\s+.]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    protected function expandTemplate(string $template, SportsBotFixtureQueue $entry): string
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $replacements = [
            '{event_id}' => (string) $entry->event_id,
            '{sport_key}' => (string) $entry->sport_key,
            '{league}' => rawurlencode((string) ($fixture['league'] ?? '')),
            '{event_name}' => rawurlencode((string) ($fixture['event_name'] ?? '')),
            '{home_team}' => rawurlencode((string) ($fixture['home_team'] ?? '')),
            '{away_team}' => rawurlencode((string) ($fixture['away_team'] ?? '')),
        ];

        return trim(strtr($template, $replacements));
    }

    protected function scraperConfig(string $key, mixed $default = null): mixed
    {
        return (new SportsBotSettingsService())->get(
            'scraper_' . $key,
            config('plugins.SportsBot.scrapers.' . $key, $default)
        );
    }

    /**
     * @return array<int, string>
     */
    protected function scraperArray(string $key): array
    {
        $value = $this->scraperConfig($key, []);
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $value) ?: [])));
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        )));
    }

    protected function expandSearchTemplate(string $template, SportsBotFixtureQueue $entry): string
    {
        $fixture = (array) ($entry->fixture_data ?? []);
        $replacements = [
            '{event_id}' => (string) $entry->event_id,
            '{sport_key}' => (string) $entry->sport_key,
            '{league}' => (string) ($fixture['league'] ?? ''),
            '{event_name}' => (string) ($fixture['event_name'] ?? ''),
            '{home_team}' => (string) ($fixture['home_team'] ?? ''),
            '{away_team}' => (string) ($fixture['away_team'] ?? ''),
        ];

        return trim(preg_replace('/\s+/', ' ', strtr($template, $replacements)) ?? '');
    }

    protected function queryHasFixtureSignal(string $query, SportsBotFixtureQueue $entry): bool
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 8) {
            return false;
        }

        $fixture = (array) ($entry->fixture_data ?? []);
        foreach (['event_name', 'home_team', 'away_team', 'league'] as $key) {
            $value = trim((string) ($fixture[$key] ?? ''));
            if ($value !== '' && str_contains(strtolower($query), strtolower($value))) {
                return true;
            }
        }

        return preg_match('/\b[A-Za-z0-9]{4,}\b/', $query) === 1
            && !preg_match('/^(vs|v)\s+/i', $query);
    }

    protected function isAllowedPublicUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $lower = strtolower($url);
        foreach (['/login', '/signin', '/sign-in', '/account', '/auth', 'paywall', '.m3u8', 'stream', 'livestream', 'free-stream'] as $blocked) {
            if (str_contains($lower, $blocked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function extractSearchResultUrls(string $html): array
    {
        if (preg_match_all('/<link>(https?:\/\/[^<]+)<\/link>/i', $html, $rssMatches) !== false && $rssMatches[1] !== []) {
            return array_values(array_unique(array_map(
                static fn (string $url): string => html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $rssMatches[1]
            )));
        }

        if (str_starts_with(ltrim($html), '{')) {
            $json = json_decode($html, true);
            if (is_array($json)) {
                $urls = $this->urlsFromJson($json);
                if ($urls !== []) {
                    return $urls;
                }
            }
        }

        $xpath = $this->dom($html);
        $urls = [];
        $nodes = $xpath->query('//a[@href]/@href');
        if (!$nodes) {
            return [];
        }

        foreach ($nodes as $node) {
            $href = html_entity_decode(trim((string) $node->nodeValue), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '') {
                continue;
            }

            if (str_contains($href, 'uddg=')) {
                $query = (string) parse_url($href, PHP_URL_QUERY);
                parse_str($query, $params);
                $href = isset($params['uddg']) ? (string) $params['uddg'] : $href;
            }

            if (str_starts_with($href, '//')) {
                $href = 'https:' . $href;
            }

            if (filter_var($href, FILTER_VALIDATE_URL)) {
                $host = strtolower((string) parse_url($href, PHP_URL_HOST));
                if (in_array($host, ['duckduckgo.com', 'www.google.com', 'www.bing.com'], true)) {
                    continue;
                }

                $urls[$href] = $href;
            }
        }

        return array_values($urls);
    }

    /**
     * @return array<int, string>
     */
    private function urlsFromJson(array $value): array
    {
        $urls = [];
        foreach ($value as $key => $item) {
            if (in_array($key, ['url', 'link', 'href'], true) && is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) {
                $urls[$item] = $item;
            }

            if (is_array($item)) {
                foreach ($this->urlsFromJson($item) as $url) {
                    $urls[$url] = $url;
                }
            }
        }

        return array_values($urls);
    }

    private function jsonText(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $this->stringsFromJson($decoded))) ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function stringsFromJson(array $value): array
    {
        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && mb_strlen($item) <= 500) {
                $strings[] = $item;
            } elseif (is_array($item)) {
                array_push($strings, ...$this->stringsFromJson($item));
            }
        }

        return $strings;
    }

    private function looksRestricted(string $html): bool
    {
        $text = strtolower(strip_tags($html));
        foreach ([
            'log in to continue',
            'subscribe to continue',
            'paywall',
            'enable cookies to continue',
            'unfortunately, bots use duckduckgo too',
            'complete the following challenge',
            'confirm this search was made by a human',
            'anomaly-modal',
            'challenge-form',
        ] as $blocked) {
            if (str_contains($text, $blocked)) {
                return true;
            }
        }

        return false;
    }
}
