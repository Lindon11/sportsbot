<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\FixtureQueueService;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use Illuminate\Console\Command;

class SportsBotFixturesPublishCommand extends Command
{
    protected $signature = 'sportsbot:fixtures-publish
        {--sport= : Publish cards for a specific sport key}
        {--dry-run : Show what would be sent without sending}';

    protected $description = 'Publish today\'s ready fixture cards to Telegram';

    public function handle(FixtureQueueService $queue): int
    {
        $sport = $this->option('sport');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run mode — no messages will be sent');
        }

        if ($sport !== null) {
            $config = SportsFixtureConfig::for((string) $sport);
            if ($config === null) {
                $this->error("Unknown sport: {$sport}");

                return Command::FAILURE;
            }

            $result = $queue->publish((string) $sport, ['dry_run' => $dryRun]);
        } else {
            $result = $queue->publishAll(['dry_run' => $dryRun]);
        }

        if (isset($result['error'])) {
            $this->error($result['error']);

            return Command::FAILURE;
        }

        if ($sport !== null) {
            $this->line(sprintf(
                'Published %s: %d sent, %d would send, %d rendered, %d skipped, %d failed',
                $sport,
                $result['sent'] ?? 0,
                $result['would_send'] ?? 0,
                $result['rendered'] ?? 0,
                $result['skipped'] ?? 0,
                $result['failed'] ?? 0
            ));
        } else {
            foreach ($result as $sportKey => $sportResult) {
                $this->line(sprintf(
                    '  %s: %d sent, %d would send, %d rendered, %d skipped, %d failed',
                    $sportKey,
                    $sportResult['sent'] ?? 0,
                    $sportResult['would_send'] ?? 0,
                    $sportResult['rendered'] ?? 0,
                    $sportResult['skipped'] ?? 0,
                    $sportResult['failed'] ?? 0
                ));
            }
        }

        return Command::SUCCESS;
    }
}
