<?php

namespace App\Console\Commands;

use App\Plugins\SportsBot\Services\Content\LiveNowContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use Illuminate\Console\Command;
use Throwable;

class SportsBotLiveNowCommand extends Command
{
    protected $signature = 'sportsbot:live-now
        {--send : Send formatted live-now digest to Telegram route LIVE_NOW}';

    protected $description = 'Build and optionally send the SportsBot Live Now digest';

    public function handle(LiveNowContentModule $module, SportsBotPublisher $publisher): int
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
        $this->info('Sent Live Now digest to ' . count($results) . ' Telegram target(s).');

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
