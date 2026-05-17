<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\Content\MotorsportFixturesContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use Illuminate\Console\Command;
use Throwable;

class SportsBotMotorsportFixturesCommand extends Command
{
    protected $signature = 'sportsbot:motorsport-fixtures
        {--send : Send motorsport fixture TV cards to Telegram route MOTORSPORT}';

    protected $description = 'Build and optionally send upcoming motorsport events with UK TV listings';

    public function handle(
        MotorsportFixturesContentModule $module,
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
        $this->info('Sent motorsport fixtures to ' . count($results) . ' Telegram target(s).');

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
