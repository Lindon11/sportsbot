<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\FixtureQueueService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

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

        $query = SportsBotFixtureQueue::query()
            ->whereIn('status', [
                SportsBotFixtureQueue::STATUS_DRAFT,
                SportsBotFixtureQueue::STATUS_READY,
                SportsBotFixtureQueue::STATUS_FAILED,
            ])
            ->whereBetween('publish_date', [
                Carbon::today()->toDateString(),
                Carbon::today()->addDays($days)->toDateString(),
            ])
            ->orderBy('publish_date')
            ->orderBy('id');

        if ($sport !== null) {
            $query->where('sport_key', (string) $sport);
        }

        $items = $query->get();
        $checked = 0;
        $found = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $item) {
            if ($checked >= $limit) {
                break;
            }

            if (!$force && !$this->needsEnrichment($item)) {
                $skipped++;
                continue;
            }

            try {
                $result = $queue->refreshScrapedData((int) $item->id);
                $checked++;

                $fields = (array) ($result['normalized']['fields'] ?? []);
                if ($fields !== []) {
                    $found++;
                }

                $this->line(sprintf(
                    '#%d %s confidence=%s fields=%s',
                    $item->id,
                    $item->sport_key,
                    (string) ($result['normalized']['confidence'] ?? 0),
                    implode(',', array_keys($fields))
                ));
            } catch (Throwable $error) {
                $checked++;
                $failed++;
                $this->warn("#{$item->id} failed: " . $error->getMessage());
            }
        }

        $this->info(sprintf(
            'Enriched queue: %d checked, %d found, %d skipped, %d failed',
            $checked,
            $found,
            $skipped,
            $failed
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function needsEnrichment(SportsBotFixtureQueue $item): bool
    {
        $fixture = (array) ($item->fixture_data ?? []);
        $payload = (array) ($item->payload ?? []);
        $scraper = (array) ($payload['scraper'] ?? []);

        if (($scraper['status'] ?? null) === 'found') {
            return false;
        }

        $hasTv = trim((string) ($fixture['tv_channel'] ?? '')) !== ''
            || !empty($fixture['tv_channels'] ?? []);
        $hasPoster = trim((string) ($fixture['event_poster'] ?? '')) !== ''
            || trim((string) ($fixture['poster'] ?? '')) !== '';

        return !$hasTv || !$hasPoster;
    }
}
