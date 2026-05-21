<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgGrabberRuntime;
use Illuminate\Console\Command;

class SportsBotEpgGrabbersDiscoverCommand extends Command
{
    protected $signature = 'sportsbot:epg-grabbers-discover
        {--region=UK : Region to discover grabbers for}';

    protected $description = 'Discover managed EPG grabbers and public feed grabber entries';

    public function handle(SportsBotEpgGrabberRuntime $runtime): int
    {
        $region = strtoupper((string) $this->option('region'));
        $result = $runtime->discover($region);

        $this->info('EPG grabber discovery complete.');
        foreach ($result as $type => $details) {
            $this->line($type . ': ' . json_encode($details, JSON_UNESCAPED_SLASHES));
        }

        return Command::SUCCESS;
    }
}
