<?php

namespace App\Console\Commands;

use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use Illuminate\Console\Command;
use Throwable;

class SportsBotTvGuideCommand extends Command
{
    protected $signature = 'sportsbot:tv-guide
        {--send : Send formatted TV guide to Telegram route TV_GUIDE}';

    protected $description = 'Build and optionally send the SportsBot TV guide';

    public function handle(TvGuideContentModule $module, SportsBotPublisher $publisher): int
    {
        if (!(bool) $this->option('send')) {
            $preview = $publisher->preview($module);
            $message = (string) ($preview['message'] ?? '');
            $this->line($message);

            return Command::SUCCESS;
        }

        try {
            $sent = $publisher->send($module, 'command');
        } catch (Throwable $error) {
            $this->error('Send failed: ' . $error->getMessage());

            return Command::FAILURE;
        }

        $results = (array) ($sent['results'] ?? []);
        $this->info('Sent TV guide to ' . count($results) . ' Telegram target(s).');

        foreach ($results as $result) {
            $this->line(sprintf(
                '- %s:%s (message_id=%s, fallback=%s)',
                (string) ($result['chat_id'] ?? ''),
                ($result['message_thread_id'] ?? null) !== null ? (string) $result['message_thread_id'] : '-',
                (string) ($result['message_id'] ?? ''),
                !empty($result['fallback']) ? 'true' : 'false'
            ));
        }

        return Command::SUCCESS;
    }
}
