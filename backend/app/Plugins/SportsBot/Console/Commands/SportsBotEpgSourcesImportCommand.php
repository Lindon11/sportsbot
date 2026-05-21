<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use App\Plugins\SportsBot\Services\SportsBotEpgSourceImporter;
use Illuminate\Console\Command;

class SportsBotEpgSourcesImportCommand extends Command
{
    protected $signature = 'sportsbot:epg-sources-import
        {--url=* : Limit import to one or more configured public XMLTV URLs}
        {--days=3 : Number of future days to keep}
        {--region=UK : Limit import to a source region}
        {--source-limit=0 : Maximum number of sources to import}
        {--max-programmes=80000 : Maximum programmes per source}
        {--chunk-size=2000 : Database insert chunk size}
        {--skip-unchanged : Skip sources when their content has not changed}
        {--no-skip-unchanged : Re-import sources even if unchanged}
        {--match : Match fixtures after import}
        {--export : Refresh cached XMLTV and JSON exports after import}';

    protected $description = 'Import enabled SportsBot EPG sources with source health tracking';

    public function handle(
        SportsBotEpgSourceImporter $importer,
        SportsBotEpgMatcher $matcher,
        SportsBotEpgExporter $exporter,
        SportsBotEpgRuntimeLock $lock,
    ): int {
        $days = max(1, min(14, (int) $this->option('days')));
        $urls = array_values(array_filter((array) $this->option('url')));
        $region = strtoupper((string) $this->option('region'));
        $skipUnchanged = (bool) $this->option('no-skip-unchanged')
            ? false
            : ((bool) $this->option('skip-unchanged') || (bool) config('plugins.SportsBot.epg.skip_unchanged', true));
        $options = [
            'region' => $region,
            'source_limit' => (int) $this->option('source-limit'),
            'max_programmes' => (int) $this->option('max-programmes'),
            'chunk_size' => (int) $this->option('chunk-size'),
            'skip_unchanged' => $skipUnchanged,
        ];

        $result = $lock->run('epg-sources-import', function () use ($importer, $matcher, $exporter, $urls, $days, $options): array {
            $result = $importer->importAll($urls, $days, $options);

            if ($this->option('match')) {
                $result['match'] = $matcher->matchFixtures($days, 300, true);
            }

            if ($this->option('export')) {
                $result['export'] = $exporter->writeCachedExports();
            }

            return $result;
        }, 3600);

        if (($result['locked'] ?? false) === true) {
            $this->warn('Another EPG job is already running.');
            return Command::SUCCESS;
        }

        $this->info("Imported {$result['programme_count']} programmes from {$result['source_count']} sources.");

        foreach ($result['sources'] as $source) {
            $status = (string) ($source['status'] ?? 'unknown');
            $count = (int) ($source['programme_count'] ?? 0);
            $this->line("[{$status}] {$source['url']} ({$count} programmes)");
        }

        if (isset($result['match'])) {
            $match = $result['match'];
            $this->info("Matched {$match['checked']} fixtures: {$match['auto_applied']} auto, {$match['needs_review']} review.");
        }

        if (isset($result['export'])) {
            $export = $result['export'];
            $this->info("Export refreshed: {$export['xml_path']}");
        }

        return Command::SUCCESS;
    }
}
