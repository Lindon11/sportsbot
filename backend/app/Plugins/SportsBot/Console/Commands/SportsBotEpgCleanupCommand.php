<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgMaintenance;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use Illuminate\Console\Command;

class SportsBotEpgCleanupCommand extends Command
{
    protected $signature = 'sportsbot:epg-cleanup
        {--output-days= : Keep generated grabber outputs for this many days}
        {--history-days= : Keep EPG import/grabber run history for this many days}
        {--programme-days= : Keep past programme rows for this many days}';

    protected $description = 'Prune old SportsBot EPG grabber outputs, history, and past programme rows';

    public function handle(SportsBotEpgMaintenance $maintenance, SportsBotEpgRuntimeLock $lock): int
    {
        $result = $lock->run('epg-cleanup', fn (): array => $maintenance->cleanup(
            $this->nullableDays('output-days'),
            $this->nullableDays('history-days'),
            $this->nullableDays('programme-days'),
        ), 900);

        if (($result['locked'] ?? false) === true) {
            $this->warn('Another EPG job is already running.');
            return Command::SUCCESS;
        }

        $this->info('EPG cleanup complete.');
        $this->line('Output files deleted: ' . (int) $result['grabber_output_files_deleted']);
        $this->line('Past programmes deleted: ' . (int) $result['past_programmes_deleted']);
        $this->line('History rows deleted: ' . ((int) $result['import_runs_deleted'] + (int) $result['grabber_runs_deleted']));

        return Command::SUCCESS;
    }

    private function nullableDays(string $option): ?int
    {
        $value = trim((string) ($this->option($option) ?? ''));

        return $value !== '' ? max(1, (int) $value) : null;
    }
}
