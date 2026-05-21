<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use Illuminate\Console\Command;

class SportsBotEpgMatchFixturesCommand extends Command
{
    protected $signature = 'sportsbot:epg-match-fixtures
        {--days=3 : Fixture publish-date window in days}
        {--limit=200 : Maximum fixtures to check}
        {--dry-run : Score fixtures without applying high-confidence matches}';

    protected $description = 'Match imported EPG programmes to fixture queue rows';

    public function handle(SportsBotEpgMatcher $matcher): int
    {
        $days = max(1, min(14, (int) $this->option('days')));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $apply = ! $this->option('dry-run');

        $result = $matcher->matchFixtures($days, $limit, $apply);

        $this->info("Checked {$result['checked']} fixtures.");
        $this->line("Auto applied: {$result['auto_applied']}");
        $this->line("Needs review: {$result['needs_review']}");
        $this->line("Ignored: {$result['ignored']}");
        $this->line("No candidate: {$result['no_candidate']}");

        return Command::SUCCESS;
    }
}
