<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\FixtureQueueService;
use Illuminate\Console\Command;

class SportsBotFixturesEnrichCommand extends Command
{
    protected $signature = 'sportsbot:fixtures-enrich
        {--sport= : Enrich a specific sport key}
        {--days=2 : Number of days ahead to scan}
        {--limit=30 : Maximum queue rows to scrape per run}
        {--force : Re-check rows even if scraper data already exists}';

    protected $description = 'Scrape public pages to enrich fixture queue rows missing TV/poster metadata';

    public function handle(FixtureQueueService $queue): int
    {
        $sport = $this->option('sport');
        $days = max(0, (int) $this->option('days'));
        $limit = max(1, min(200, (int) $this->option('limit')));
        $force = (bool) $this->option('force');

        $result = $queue->enrichQueuedFixtures($sport !== null ? (string) $sport : null, $days, $limit, $force);

        foreach ((array) ($result['rows'] ?? []) as $row) {
            if (isset($row['error'])) {
                $this->warn("#{$row['id']} failed: {$row['error']}");
                continue;
            }

            $this->line(sprintf(
                '#%d %s confidence=%s fields=%s',
                $row['id'] ?? 0,
                $row['sport'] ?? '-',
                (string) ($row['confidence'] ?? 0),
                implode(',', (array) ($row['fields'] ?? []))
            ));
        }

        $this->info(sprintf(
            'Enriched queue: %d checked, %d found, %d skipped, %d failed',
            $result['checked'] ?? 0,
            $result['found'] ?? 0,
            $result['skipped'] ?? 0,
            $result['failed'] ?? 0
        ));

        return ($result['failed'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
