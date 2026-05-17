<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotRunner;
use Illuminate\Console\Command;

class SportsBotHealthCommand extends Command
{
    protected $signature = 'sportsbot:health';

    protected $description = 'Check the Laravel-native Sports Bot plugin configuration';

    public function handle(SportsBotRunner $runner): int
    {
        $health = $runner->health();

        $this->line('Plugin enabled: ' . ($health['plugin_enabled'] ? 'yes' : 'no'));
        $this->line('Schedule enabled: ' . ($health['schedule_enabled'] ? 'yes' : 'no'));
        $this->line('Native sending enabled: ' . ($health['send_messages'] ? 'yes' : 'no'));
        $this->line('Provider: ' . $health['provider']);
        $this->line('Provider key configured: ' . ($health['provider_key_configured'] ? 'yes' : 'no'));
        $this->line('Telegram configured: ' . ($health['telegram_configured'] ? 'yes' : 'no'));
        $this->line('Enabled sports: ' . implode(', ', $health['enabled_sports']));
        $this->line('Allowed leagues: ' . (int) $health['allowed_league_count']);

        if (!$health['ok']) {
            $this->error('Sports Bot native health check failed.');

            return Command::FAILURE;
        }

        $this->info('Sports Bot native health check passed.');

        return Command::SUCCESS;
    }
}
