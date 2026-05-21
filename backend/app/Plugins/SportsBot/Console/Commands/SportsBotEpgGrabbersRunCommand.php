<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgGrabberRuntime;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use Illuminate\Console\Command;

class SportsBotEpgGrabbersRunCommand extends Command
{
    protected $signature = 'sportsbot:epg-grabbers-run
        {--region=UK : Region to run grabbers for}
        {--only= : Run only a grabber type or exact name}
        {--import : Import generated XMLTV output}
        {--export : Refresh cached guide exports afterwards}
        {--chunk-size=2000 : Import insert chunk size}
        {--max-programmes=80000 : Maximum programmes per source}
        {--skip-unchanged : Skip sources when their content has not changed}
        {--no-skip-unchanged : Re-import sources even if unchanged}';

    protected $description = 'Run managed EPG grabbers and optionally import their XMLTV output';

    public function handle(SportsBotEpgGrabberRuntime $runtime, SportsBotEpgRuntimeLock $lock): int
    {
        $region = strtoupper((string) $this->option('region'));
        $only = $this->option('only') ? (string) $this->option('only') : null;
        $skipUnchanged = (bool) $this->option('no-skip-unchanged')
            ? false
            : ((bool) $this->option('skip-unchanged') || (bool) config('plugins.SportsBot.epg.skip_unchanged', true));

        $result = $lock->run('epg-grabbers-run', fn (): array => $runtime->run(
            $region,
            $only,
            (bool) $this->option('import'),
            (bool) $this->option('export'),
            [
                'region' => $region,
                'chunk_size' => (int) $this->option('chunk-size'),
                'max_programmes' => (int) $this->option('max-programmes'),
                'skip_unchanged' => $skipUnchanged,
            ],
        ), 3600);

        if (($result['locked'] ?? false) === true) {
            $this->warn('Another EPG job is already running.');
            return Command::SUCCESS;
        }

        $this->info("Ran {$result['run_count']} EPG grabbers.");
        foreach ($result['runs'] ?? [] as $run) {
            $this->line("[{$run['status']}] {$run['type']} {$run['name']}");
        }

        return Command::SUCCESS;
    }
}
