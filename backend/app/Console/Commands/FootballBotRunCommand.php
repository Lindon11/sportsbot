<?php

namespace App\Console\Commands;

use App\Core\Services\FootballBotRuntime;
use Illuminate\Console\Command;

class FootballBotRunCommand extends Command
{
    protected $signature = 'footballbot:run {--dry-run : Generate/check without sending Telegram messages}';

    protected $description = 'Run the Laravel-managed sports alert bot';

    public function handle(FootballBotRuntime $bot): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $summary = $bot->runLiveCheck($dryRun);

        $this->info($dryRun ? 'Football bot dry run completed.' : 'Football bot run completed.');
        $this->line('Live scores returned: ' . (int) ($summary['total_live_scores'] ?? 0));
        $this->line('Allowed live matches: ' . (int) ($summary['allowed_matches'] ?? 0));
        $this->line('Generated alerts: ' . (int) ($summary['generated_alerts'] ?? 0));
        $this->line('Sent alerts: ' . (int) ($summary['sent_alerts'] ?? 0));

        foreach (($summary['messages'] ?? []) as $message) {
            $this->line('- ' . $message);
        }

        return Command::SUCCESS;
    }
}
