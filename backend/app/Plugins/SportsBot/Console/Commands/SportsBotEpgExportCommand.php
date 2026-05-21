<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use Illuminate\Console\Command;

class SportsBotEpgExportCommand extends Command
{
    protected $signature = 'sportsbot:epg-export
        {--hours=72 : Future guide window to export}';

    protected $description = 'Generate cached SportsBot XMLTV and JSON EPG exports';

    public function handle(SportsBotEpgExporter $exporter): int
    {
        $hours = max(1, min(336, (int) $this->option('hours')));
        $result = $exporter->writeCachedExports($hours);

        $this->info('EPG exports refreshed.');
        $this->line('XMLTV: ' . $result['xml_path']);
        $this->line('JSON: ' . $result['json_path']);

        return Command::SUCCESS;
    }
}
