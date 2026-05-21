<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use Illuminate\Console\Command;

class SportsBotEpgMatchFixturesCommand extends Command
{
    protected $signature = 'sportsbot:epg-match-fixtures
        {--days=3 : Fixture publish-date window in days}
        {--limit=200 : Maximum fixtures to check}
        {--force : Re-score fixtures even when they already have TV/accepted EPG}
        {--dry-run : Score fixtures without applying high-confidence matches}';

    protected $description = 'Match imported EPG programmes to fixture queue rows';

    public function handle(SportsBotEpgMatcher $matcher, SportsBotEpgRuntimeLock $lock): int
    {
        $days = max(1, min(14, (int) $this->option('days')));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $apply = ! $this->option('dry-run');

        $result = $lock->run('epg-match-fixtures', fn (): array => $matcher->matchFixtures($days, $limit, $apply, [
            'force' => (bool) $this->option('force'),
        ]), 1800);

        if (($result['locked'] ?? false) === true) {
            $this->warn('Another EPG job is already running.');
            return Command::SUCCESS;
        }

        $this->info("Checked {$result['checked']} fixtures.");
        $this->line("Auto applied: {$result['auto_applied']}");
        $this->line("Needs review: {$result['needs_review']}");
        $this->line("Ignored: {$result['ignored']}");
        $this->line("No candidate: {$result['no_candidate']}");

        return Command::SUCCESS;
    }
}
