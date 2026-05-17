<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\Content\FixturesTodayContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use Illuminate\Console\Command;
use Throwable;

class SportsBotFixturesTodayCommand extends Command
{
    protected $signature = 'sportsbot:fixtures-today
        {--send : Send formatted fixtures to Telegram route FIXTURES_TODAY}';

    protected $description = 'Build and optionally send today\'s fixtures grouped by sport';

    public function handle(
        FixturesTodayContentModule $module,
        SportsBotPublisher $publisher,
    ): int {
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

        $this->info('Sent fixtures message to ' . count($results) . ' Telegram target(s).');

        foreach ($results as $result) {
            $this->line(sprintf(
                '- %s:%s (message_id=%s, fallback=%s)',
                (string) ($result['chat_id'] ?? ''),
                $result['message_thread_id'] !== null ? (string) $result['message_thread_id'] : '-',
                (string) ($result['message_id'] ?? ''),
                !empty($result['fallback']) ? 'true' : 'false'
            ));
        }

        return Command::SUCCESS;
    }
}
