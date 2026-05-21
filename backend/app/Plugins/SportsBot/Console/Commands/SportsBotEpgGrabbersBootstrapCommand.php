<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgGrabberRuntime;
use Illuminate\Console\Command;

class SportsBotEpgGrabbersBootstrapCommand extends Command
{
    protected $signature = 'sportsbot:epg-grabbers-bootstrap
        {--tool=iptv-org : Tool to bootstrap}';

    protected $description = 'Bootstrap managed EPG grabber tools into local storage';

    public function handle(SportsBotEpgGrabberRuntime $runtime): int
    {
        $tool = strtolower((string) $this->option('tool'));
        if (! in_array($tool, ['iptv-org', 'iptv_org_epg'], true)) {
            $this->error("Unsupported grabber tool: {$tool}");
            return Command::FAILURE;
        }

        $result = $runtime->bootstrapIptvOrg();
        if (($result['bootstrapped'] ?? false) === true) {
            $this->info('iptv-org/epg bootstrap complete: ' . $result['path']);
            return Command::SUCCESS;
        }

        $this->error('iptv-org/epg bootstrap failed: ' . ($result['error'] ?? 'unknown error'));
        return Command::FAILURE;
    }
}
