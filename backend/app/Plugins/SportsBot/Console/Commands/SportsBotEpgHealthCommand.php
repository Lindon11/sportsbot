<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Services\SportsBotEpgHealthService;
use Illuminate\Console\Command;

class SportsBotEpgHealthCommand extends Command
{
    protected $signature = 'sportsbot:epg-health
        {--notify : Send deduplicated health alerts through Monitor Bot Telegram}
        {--json : Output machine-readable JSON}';

    protected $description = 'Check SportsBot EPG exports, sources, grabbers, and UK guide coverage';

    public function handle(SportsBotEpgHealthService $health, MonitorBotTelegramNotifier $notifier): int
    {
        $result = $this->option('notify') ? $health->notify($notifier) : $health->snapshot();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $alerts = (array) ($result['alerts'] ?? []);
        $this->info('EPG health: ' . (string) ($result['status'] ?? 'unknown'));
        foreach ($alerts as $alert) {
            $this->line('[' . strtoupper((string) ($alert['severity'] ?? 'warning')) . '] ' . (string) ($alert['message'] ?? ''));
        }

        if ($this->option('notify')) {
            $this->line('Monitor Bot sent: ' . (int) ($result['sent'] ?? 0) . ', suppressed: ' . (int) ($result['suppressed'] ?? 0) . ', failed: ' . (int) ($result['failed'] ?? 0));
        }

        return Command::SUCCESS;
    }
}
