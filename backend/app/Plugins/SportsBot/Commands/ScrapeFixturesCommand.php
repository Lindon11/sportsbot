<?php

namespace App\Plugins\SportsBot\Commands;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Support\SportsBotSports;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ScrapeFixturesCommand extends Command
{
    protected $signature = 'sportsbot:scrape-fixtures
        {sport : Sport key (football, rugby, etc.)}
        {date? : Date (Y-m-d) to scrape fixtures for}
        {--home= : Home team name (manual add)}
        {--away= : Away team name (manual add)}
        {--league= : Competition/league name}
        {--venue= : Venue name}
        {--time= : Kick-off time (e.g. 15:00)}
        {--tv= : TV channel}
        {--dry-run : Show what would be added without creating}';

    protected $description = 'Add fixtures to the queue from web search or direct input';

    public function handle(): int
    {
        $sportKey = $this->argument('sport');
        $date = $this->argument('date') ?? Carbon::today()->format('Y-m-d');
        $home = $this->option('home');
        $away = $this->option('away');
        $league = $this->option('league');
        $venue = $this->option('venue');
        $time = $this->option('time');
        $tv = $this->option('tv');
        $dryRun = (bool) $this->option('dry-run');

        $sport = SportsBotSports::all()[$sportKey] ?? null;
        if ($sport === null) {
            $this->error("Unknown sport: {$sportKey}");
            return self::FAILURE;
        }

        // Manual mode: --home and --away provided
        if ($home && $away) {
            $fixture = [
                'home' => $home,
                'away' => $away,
                'competition' => $league ?: 'Competition TBC',
                'venue' => $venue ?: '',
                'tv' => $tv ?: '',
                'time' => $time ?: '',
            ];
            if ($dryRun) {
                $this->line("[DRY-RUN] Would add: {$home} vs {$away} ({$fixture['competition']}) on {$date}");
            } else {
                $this->addToQueue($fixture, $sportKey, $date);
                $this->info("Added: {$home} vs {$away}");
            }
            return self::SUCCESS;
        }

        // Search mode
        $this->info("Searching for {$sport['label']} fixtures on {$date}...");
        $query = $league ? "{$league} football fixtures {$date}" : "{$sport['label']} fixtures {$date}";

        $added = 0;
        $results = $this->searchFixtures($query);
        foreach ($results as $fixture) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Would add: {$fixture['home']} vs {$fixture['away']} ({$fixture['competition']})");
                $added++;
            } else {
                $created = $this->addToQueue($fixture, $sportKey, $date);
                if ($created) {
                    $this->line("  Added: {$fixture['home']} vs {$fixture['away']}");
                    $added++;
                }
            }
        }

        if ($added === 0) {
            $this->warn("No fixtures found. Use --home= --away= to add manually.");
        }

        $this->info("Done. {$added} fixture(s) processed.");
        return self::SUCCESS;
    }

    private function searchFixtures(string $query): array
    {
        $results = [];
        $searchUrl = 'https://www.bing.com/search?q=' . rawurlencode($query);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'])
                ->get($searchUrl);

            if (!$response->successful()) {
                $this->warn("Search failed: HTTP {$response->status()}");
                return [];
            }

            $html = $response->body();

            // Bing result captions
            preg_match_all('/<h2><a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a><\/h2>/s', $html, $linkMatches);
            preg_match_all('/<p[^>]*class="b_caption"[^>]*>(.*?)<\/p>/s', $html, $captionMatches);

            foreach ($linkMatches[1] as $i => $url) {
                $text = strip_tags($linkMatches[2][$i]);
                $caption = strip_tags($captionMatches[1][$i] ?? '');
                $combined = $text . ' ' . $caption;

                $teams = null;
                if (preg_match('/([A-Z][a-z]+(?:\s[A-Z][a-z]+)*)\s+v(?:s|\.|ersus)\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)*)/', $combined, $m)) {
                    $teams = [$m[1], $m[2]];
                } elseif (preg_match('/between\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)*)\s+and\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)*)/', $combined, $m)) {
                    $teams = [$m[1], $m[2]];
                }

                if ($teams !== null && strlen($teams[0]) > 2 && strlen($teams[1]) > 2) {
                    $results[] = [
                        'home' => trim($teams[0]),
                        'away' => trim($teams[1]),
                        'competition' => $this->extractCompetition($combined . ' ' . $url),
                        'url' => $url,
                    ];
                }

                if (count($results) >= 10) break;
            }
        } catch (\Throwable $e) {
            $this->error("Search error: " . $e->getMessage());
        }

        return $results;
    }

    private function extractCompetition(string $text, string $url): string
    {
        if (stripos($text, 'premier') !== false || stripos($url, 'premier') !== false) return 'English Premier League';
        if (stripos($text, 'championship') !== false || stripos($url, 'championship') !== false) return 'EFL Championship';
        if (stripos($text, 'league one') !== false || stripos($url, 'league-one') !== false) return 'League One';
        if (stripos($text, 'league two') !== false || stripos($url, 'league-two') !== false) return 'League Two';
        if (stripos($text, 'la liga') !== false || stripos($url, 'la-liga') !== false) return 'Spanish La Liga';
        if (stripos($text, 'serie a') !== false || stripos($url, 'serie-a') !== false) return 'Italian Serie A';
        if (stripos($text, 'bundesliga') !== false || stripos($url, 'bundesliga') !== false) return 'German Bundesliga';
        if (stripos($text, 'ligue 1') !== false || stripos($url, 'ligue-1') !== false) return 'French Ligue 1';
        if (stripos($text, 'eredivisie') !== false || stripos($url, 'eredivisie') !== false) return 'Dutch Eredivisie';
        if (stripos($text, 'play') !== false || stripos($url, 'play') !== false) return 'EFL Championship';
        return 'Competition TBC';
    }

    private function addToQueue(array $fixture, string $sportKey, string $date): bool
    {
        $eventId = 'scraped-' . Str::slug($fixture['home'] . '-' . $fixture['away'] . '-' . $date);

        $existing = SportsBotFixtureQueue::query()->where('event_id', $eventId)->first();
        if ($existing) {
            $this->line("  Skipped (already in queue): {$fixture['home']} vs {$fixture['away']}");
            return false;
        }

        $time = $fixture['time'] ?? '';
        $venue = $fixture['venue'] ?? '';

        $fixtureData = [
            'event_id' => $eventId,
            'event_name' => $fixture['home'] . ' vs ' . $fixture['away'],
            'home_team' => $fixture['home'],
            'away_team' => $fixture['away'],
            'league' => $fixture['competition'],
            'sport' => SportsBotSports::providerSport($sportKey),
            'sport_key' => $sportKey,
            'time' => $time,
            'venue' => $venue,
            'tv_channel' => $fixture['tv'] ?? '',
            'source' => 'scraper',
        ];

        $publishDate = Carbon::parse($date);
        $routeKey = TelegramRouteKeys::normalize(SportsBotSports::routeKey($sportKey));

        SportsBotFixtureQueue::query()->create([
            'event_id' => $eventId,
            'sport_key' => $sportKey,
            'publish_date' => $publishDate,
            'status' => SportsBotFixtureQueue::STATUS_DRAFT,
            'route_key' => $routeKey,
            'fixture_data' => $fixtureData,
            'asset_status' => SportsBotFixtureQueue::ASSET_PENDING,
        ]);

        return true;
    }
}
