<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\FixtureQueueService;
use Illuminate\Console\Command;

class SportsBotFixturesRefreshEventCommand extends Command
{
    protected $signature = 'sportsbot:fixtures-refresh-event
        {event_id : The event ID to refresh}
        {--render : Also re-render the card after refresh}';

    protected $description = 'Re-fetch an event from the provider and update the fixture queue';

    public function handle(FixtureQueueService $queue): int
    {
        $eventId = (string) $this->argument('event_id');

        $this->line("Refreshing event: {$eventId}");

        $result = $queue->refreshEvent($eventId);

        if (isset($result['error'])) {
            $this->error($result['error']);

            return Command::FAILURE;
        }

        $this->info("Refreshed {$result['entries']} queue entry/entries.");

        return Command::SUCCESS;
    }
}
