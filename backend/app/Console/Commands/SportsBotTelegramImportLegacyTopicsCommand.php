<?php

namespace App\Console\Commands;

use App\Plugins\SportsBot\Services\TelegramTopicDiscoveryService;
use Illuminate\Console\Command;
use Throwable;

class SportsBotTelegramImportLegacyTopicsCommand extends Command
{
    protected $signature = 'sportsbot:telegram-import-legacy-topics
        {--path= : Optional path to legacy state.sqlite}
        {--no-routes : Import topics only, without legacy route assignments}';

    protected $description = 'Import Telegram forum topics from the legacy SportsBot SQLite state database';

    public function handle(TelegramTopicDiscoveryService $service): int
    {
        try {
            $summary = $service->importLegacyTopics(
                $this->option('path') ? (string) $this->option('path') : null,
                !(bool) $this->option('no-routes')
            );
        } catch (Throwable $error) {
            $this->error('Legacy topic import failed: ' . $error->getMessage());

            return Command::FAILURE;
        }

        $this->line('legacy db: ' . (string) ($summary['path'] ?? ''));
        $this->line('rows seen: ' . (int) ($summary['rows_seen'] ?? 0));
        $this->line('topics imported: ' . (int) ($summary['topics_imported'] ?? 0));
        $this->line('routes imported: ' . (int) ($summary['routes_imported'] ?? 0));

        foreach ((array) ($summary['recent_topics'] ?? []) as $topic) {
            $this->line(sprintf(
                '- %s:%s %s',
                (string) ($topic->chat_id ?? ''),
                $topic->message_thread_id !== null ? (string) $topic->message_thread_id : '-',
                (string) ($topic->title ?? '')
            ));
        }

        return Command::SUCCESS;
    }
}
