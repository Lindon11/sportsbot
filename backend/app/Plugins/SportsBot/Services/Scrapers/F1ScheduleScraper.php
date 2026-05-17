<?php

namespace App\Plugins\SportsBot\Services\Scrapers;

use App\Plugins\SportsBot\Contracts\ScraperProviderInterface;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\ScraperResultNormalizer;
use App\Plugins\SportsBot\Support\SportsBotSports;

class F1ScheduleScraper extends AbstractPublicPageScraper implements ScraperProviderInterface
{
    public function __construct(
        private readonly ScraperResultNormalizer $normalizer = new ScraperResultNormalizer(),
    ) {
    }

    public function key(): string
    {
        return 'f1_schedule';
    }

    public function supports(SportsBotFixtureQueue $entry, string $action): bool
    {
        $sport = SportsBotSports::normalize((string) $entry->sport_key);

        return in_array($sport, ['formula_1', 'motorsport'], true)
            && in_array($action, ['find_tv_info', 'refresh'], true);
    }

    public function scrape(SportsBotFixtureQueue $entry, string $action): array
    {
        $urls = $this->fixtureSourceUrls($entry, $this->scraperArray('f1_schedule_urls'));
        $discovery = $this->discoverPublicUrls($entry, array_merge([
            '{event_name} F1 session schedule UK time',
            '{event_name} Formula 1 practice qualifying race schedule',
            '{league} {event_name} session times',
        ], $this->scraperArray('f1_schedule_search_queries')));
        $urls = array_values(array_unique(array_merge($urls, $discovery['urls'])));
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

            $sessions = $this->sessionsFromText($text);
            if ($sessions === []) {
                $logs[] = ['source_url' => $url, 'checked_at' => now()->toISOString(), 'status' => 'empty', 'error' => 'No F1 session schedule found near fixture'];
                continue;
            }

            $fields = [
                'f1_sessions' => $sessions,
                'venue' => $this->venueFromText($text),
            ];

            $first = $sessions[0];
            $fields += [
                'event_name' => (string) ($first['session'] ?? ''),
                'date_label' => (string) ($first['date'] ?? ''),
                'kickoff_label' => (string) ($first['time'] ?? ''),
                'time' => (string) ($first['time'] ?? ''),
            ];

            $fields = $this->normalizer->sanitizeFields($fields);
            $confidence = $this->confidence($fields, $sessions !== [] ? 0.5 : 0.25);

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
     * @return array<int, array{session:string,date:string,time:string}>
     */
    private function sessionsFromText(string $text): array
    {
        $sessions = [];
        $pattern = '/\b(Practice\s+\d|Sprint(?:\s+Qualifying)?|Qualifying|Race|Grand Prix)\b.{0,80}?(\d{1,2}\s+[A-Z][a-z]+|(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*)?.{0,40}?(\d{1,2}:\d{2})/i';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $sessions[] = [
                    'session' => trim((string) ($match[1] ?? '')),
                    'date' => trim((string) ($match[2] ?? '')),
                    'time' => trim((string) ($match[3] ?? '')),
                ];
            }
        }

        return array_slice($sessions, 0, 10);
    }

    private function venueFromText(string $text): string
    {
        if (preg_match('/\b(?:Circuit|Autodromo|Autodrome|Silverstone|Monza|Spa|Suzuka|Interlagos|Marina Bay|Yas Marina)[^.!?]{0,80}/i', $text, $matches) === 1) {
            return trim($matches[0]);
        }

        return '';
    }
}
