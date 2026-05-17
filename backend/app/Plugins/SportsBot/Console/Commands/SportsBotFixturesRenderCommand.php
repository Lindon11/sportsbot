<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\FixtureQueueService;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use Illuminate\Console\Command;

class SportsBotFixturesRenderCommand extends Command
{
    protected $signature = 'sportsbot:fixtures-render
        {--sport= : Render cards for a specific sport key}';

    protected $description = 'Render fixture cards for draft entries within the card prepare window';

    public function handle(FixtureQueueService $queue): int
    {
        $sport = $this->option('sport');

        if ($sport !== null) {
            $config = SportsFixtureConfig::for((string) $sport);
            if ($config === null) {
                $this->error("Unknown sport: {$sport}");

                return Command::FAILURE;
            }

            $result = $queue->render((string) $sport);
        } else {
            $result = $queue->renderAll();
        }

        if (isset($result['error'])) {
            $this->error($result['error']);

            return Command::FAILURE;
        }

        if ($sport !== null) {
            $this->line(sprintf(
                'Rendered %s: %d ready, %d failed',
                $sport,
                $result['rendered'] ?? 0,
                $result['failed'] ?? 0
            ));
        } else {
            foreach ($result as $sportKey => $sportResult) {
                $this->line(sprintf(
                    '  %s: %d ready, %d failed',
                    $sportKey,
                    $sportResult['rendered'] ?? 0,
                    $sportResult['failed'] ?? 0
                ));
            }
        }

        return Command::SUCCESS;
    }
}
