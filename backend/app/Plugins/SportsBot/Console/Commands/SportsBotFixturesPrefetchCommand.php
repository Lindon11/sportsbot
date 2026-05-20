<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\FixtureQueueService;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use Illuminate\Console\Command;

class SportsBotFixturesPrefetchCommand extends Command
{
    protected $signature = 'sportsbot:fixtures-prefetch
        {--sport= : Prefetch a specific sport key (e.g. football, fights)}
        {--days= : Override the fetch window in days}';

    protected $description = 'Fetch fixture data from provider and store in the fixture queue as draft';

    public function handle(FixtureQueueService $queue): int
    {
        $sport = $this->option('sport');
        $days = $this->option('days');
        $days = $days !== null && $days !== '' ? max(0, (int) $days) : null;

        if ($sport !== null) {
            $config = SportsFixtureConfig::for((string) $sport);
            if ($config === null) {
                $this->error("Unknown sport: {$sport}");

                return Command::FAILURE;
            }

            $result = $queue->prefetch((string) $sport, $days);
        } else {
            $result = $queue->prefetchAll($days);
        }

        if (isset($result['error'])) {
            $this->error($result['error']);

            return Command::FAILURE;
        }

        if ($sport !== null) {
            $this->line(sprintf(
                'Prefetched %s: %d created, %d updated, %d skipped',
                $sport,
                $result['created'] ?? 0,
                $result['updated'] ?? 0,
                $result['skipped'] ?? 0
            ));
        } else {
            foreach ($result as $sportKey => $sportResult) {
                $this->line(sprintf(
                    '  %s: %d created, %d updated, %d skipped',
                    $sportKey,
                    $sportResult['created'] ?? 0,
                    $sportResult['updated'] ?? 0,
                    $sportResult['skipped'] ?? 0
                ));
            }
        }

        return Command::SUCCESS;
    }
}
