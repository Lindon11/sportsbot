<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use App\Plugins\SportsBot\Services\SportsBotEpgSourceImporter;
use Illuminate\Console\Command;

class SportsBotEpgSourcesImportCommand extends Command
{
    protected $signature = 'sportsbot:epg-sources-import
        {--url=* : Limit import to one or more configured public XMLTV URLs}
        {--days=3 : Number of future days to keep}
        {--match : Match fixtures after import}
        {--export : Refresh cached XMLTV and JSON exports after import}';

    protected $description = 'Import enabled SportsBot EPG sources with source health tracking';

    public function handle(
        SportsBotEpgSourceImporter $importer,
        SportsBotEpgMatcher $matcher,
        SportsBotEpgExporter $exporter,
    ): int {
        $days = max(1, min(14, (int) $this->option('days')));
        $urls = array_values(array_filter((array) $this->option('url')));

        $result = $importer->importAll($urls, $days);
        $this->info("Imported {$result['programme_count']} programmes from {$result['source_count']} sources.");

        foreach ($result['sources'] as $source) {
            $status = (string) ($source['status'] ?? 'unknown');
            $count = (int) ($source['programme_count'] ?? 0);
            $this->line("[{$status}] {$source['url']} ({$count} programmes)");
        }

        if ($this->option('match')) {
            $match = $matcher->matchFixtures($days, 300, true);
            $this->info("Matched {$match['checked']} fixtures: {$match['auto_applied']} auto, {$match['needs_review']} review.");
        }

        if ($this->option('export')) {
            $export = $exporter->writeCachedExports();
            $this->info("Export refreshed: {$export['xml_path']}");
        }

        return Command::SUCCESS;
    }
}
