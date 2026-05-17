<?php

namespace App\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotRunner;
use Illuminate\Console\Command;

class SportsBotRunNativeCommand extends Command
{
    protected $signature = 'sportsbot:run-native
        {--dry-run : Fetch and compare matches without sending Telegram messages}
        {--send : Send Telegram messages for generated alerts regardless of config}';

    protected $description = 'Run the Laravel-native Sports Bot plugin';

    public function handle(SportsBotRunner $runner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sendOverride = $this->option('send') ? true : null;

        $summary = $runner->run($dryRun, $sendOverride);

        $this->info($dryRun ? 'Sports Bot native dry run completed.' : 'Sports Bot native run completed.');
        $this->line('Live scores returned: ' . (int) $summary['total_live_scores']);
        $this->line('Normalized matches: ' . (int) $summary['normalized_matches']);
        $this->line('Allowed matches: ' . (int) $summary['allowed_matches']);
        $this->line('Generated alerts: ' . (int) $summary['generated_alerts']);
        $this->line('Duplicate alerts: ' . (int) $summary['duplicate_alerts']);
        $this->line('Sent alerts: ' . (int) $summary['sent_alerts']);
        $this->line('Dry-run alerts: ' . (int) $summary['dry_run_alerts']);

        foreach (($summary['messages'] ?? []) as $message) {
            $this->line('- ' . $message);
        }

        return Command::SUCCESS;
    }
}
