<?php

namespace App\Plugins\SportsBot\Services\Scrapers;

use App\Plugins\SportsBot\Contracts\ScraperProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\ScraperResultNormalizer;
use App\Plugins\SportsBot\Support\SportsBotSports;

class CombatPosterScraper extends AbstractPublicPageScraper implements ScraperProviderInterface
{
    public function __construct(
        private readonly ScraperResultNormalizer $normalizer = new ScraperResultNormalizer(),
    ) {
    }

    public function key(): string
    {
        return 'combat_poster';
    }

    public function supports(SportsBotFixtureQueue $entry, string $action): bool
    {
        $sport = SportsBotSports::normalize((string) $entry->sport_key);

        return in_array($sport, ['fights', 'mma', 'boxing'], true)
            && in_array($action, ['find_poster', 'refresh'], true);
    }

    public function scrape(SportsBotFixtureQueue $entry, string $action): array
    {
        $urls = $this->fixtureSourceUrls($entry, $this->scraperArray('combat_poster_urls'));
        $discovery = $this->discoverPublicUrls($entry, array_merge([
            '{event_name} fight poster official',
            '{home_team} vs {away_team} fight poster',
            '{event_name} UFC boxing poster',
        ], $this->scraperArray('combat_poster_search_queries')));
        $urls = array_values(array_unique(array_merge($urls, $discovery['urls'])));
        $logs = $discovery['logs'];
        $results = [];

        foreach ($urls as $url) {
            $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'checking'];
            $page = $this->fetchPublicHtml($url);
            if ($page === null) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'failed', 'error' => 'No public HTML response'];
                continue;
            }

            $xpath = $this->dom($page['html']);
            $title = $this->title($xpath);
            $image = $this->meta($xpath, 'og:image') ?: $this->meta($xpath, 'twitter:image');
            $image = $this->absoluteUrl($image, $url);

            $fields = [
                'event_poster' => $image,
                'event_name' => $title,
            ] + $this->fightersFromTitle($title);

            if (!$this->titleMatchesFixture($title . ' ' . $this->visibleText($xpath), $entry)) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'skipped', 'error' => 'Page title did not match fixture'];
                continue;
            }

            $fields = $this->normalizer->sanitizeFields($fields);
            $confidence = $this->confidence($fields, isset($fields['event_poster']) ? 0.45 : 0.25);

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
     * @return array<string, string>
     */
    private function fightersFromTitle(string $title): array
    {
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        if (preg_match('/(.+?)\s+(?:vs\.?|v)\s+(.+?)(?:\s+[-|:]\s+|$)/i', $title, $matches) !== 1) {
            return [];
        }

        return [
            'home_team' => trim($matches[1]),
            'away_team' => trim($matches[2]),
        ];
    }
}
