<?php

namespace App\Console\Commands;

use App\Core\Services\FootballBotRuntime;
use Illuminate\Console\Command;

class FootballBotHealthCommand extends Command
{
    protected $signature = 'footballbot:health';

    protected $description = 'Check the Laravel-managed sports alert bot configuration';

    public function handle(FootballBotRuntime $bot): int
    {
        $health = $bot->health();

        $this->line('Runtime: ' . $health['runtime_path']);
        $this->line('State DB: ' . ($health['state_db'] ?? '-'));
        $this->line('Log file: ' . ($health['log_file'] ?? '-'));
        $this->line('Webhook: ' . ($health['webhook_enabled'] ? 'enabled' : 'disabled'));

        if ($health['missing_extensions'] !== []) {
            $this->error('Missing PHP extensions: ' . implode(', ', $health['missing_extensions']));
        }

        if ($health['missing_env'] !== []) {
            $this->error('Missing environment values: ' . implode(', ', $health['missing_env']));
        }

        if (!$health['ok']) {
            return Command::FAILURE;
        }

        $this->info('Football bot health check passed.');

        return Command::SUCCESS;
    }
}
