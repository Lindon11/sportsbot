<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\Content\RugbyFixturesContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use Illuminate\Console\Command;
use Throwable;

class SportsBotRugbyFixturesCommand extends Command
{
    protected $signature = 'sportsbot:rugby-fixtures
        {--send : Send rugby fixture TV cards to Telegram route RUGBY}';

    protected $description = 'Build and optionally send today\'s rugby fixtures with UK TV listings';

    public function handle(
        RugbyFixturesContentModule $module,
        SportsBotPublisher $publisher,
    ): int {
        if (!(bool) $this->option('send')) {
            $preview = $publisher->preview($module);
            $this->line((string) ($preview['message'] ?? ''));

            return Command::SUCCESS;
        }

        try {
            $sent = $publisher->send($module, 'command');
        } catch (Throwable $error) {
            $this->error('Send failed: ' . $error->getMessage());

            return Command::FAILURE;
        }

        $results = (array) ($sent['results'] ?? []);
        $this->info('Sent rugby fixtures to ' . count($results) . ' Telegram target(s).');

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
