<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use Illuminate\Console\Command;

class SportsBotEpgExportCommand extends Command
{
    protected $signature = 'sportsbot:epg-export
        {--hours=72 : Future guide window to export}';

    protected $description = 'Generate cached SportsBot XMLTV and JSON EPG exports';

    public function handle(SportsBotEpgExporter $exporter, SportsBotEpgRuntimeLock $lock): int
    {
        $hours = max(1, min(336, (int) $this->option('hours')));
        $result = $lock->run('epg-export', fn (): array => $exporter->writeCachedExports($hours), 900);

        if (($result['locked'] ?? false) === true) {
            $this->warn('Another EPG job is already running.');
            return Command::SUCCESS;
        }

        $this->info('EPG exports refreshed.');
        $this->line('XMLTV: ' . $result['xml_path']);
        $this->line('JSON: ' . $result['json_path']);

        return Command::SUCCESS;
    }
}
