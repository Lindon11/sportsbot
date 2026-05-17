<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\TelegramTopicDiscoveryService;
use Illuminate\Console\Command;
use Throwable;

class SportsBotTelegramSyncTopicsCommand extends Command
{
    protected $signature = 'sportsbot:telegram-sync-topics
        {--limit=100 : Maximum Telegram updates to fetch}
        {--timeout=0 : Long-poll timeout in seconds}
        {--reset-offset : Ignore Laravel stored getUpdates offset for this sync}';

    protected $description = 'Discover Telegram forum topics from bot updates';

    public function handle(TelegramTopicDiscoveryService $service): int
    {
        try {
            $summary = $service->sync(
                (int) $this->option('limit'),
                (int) $this->option('timeout'),
                (bool) $this->option('reset-offset')
            );
        } catch (Throwable $error) {
            $this->error('Topic sync failed: ' . $error->getMessage());

            return Command::FAILURE;
        }

        $this->line('updates seen: ' . (int) ($summary['updates_seen'] ?? 0));
        $this->line('messages seen: ' . (int) ($summary['messages_seen'] ?? 0));
        $this->line('topics saved: ' . (int) ($summary['topics_saved'] ?? 0));
        $this->line('labels saved: ' . (int) ($summary['labels_saved'] ?? 0));
        $this->line('offset: ' . (int) ($summary['previous_offset'] ?? 0) . ' -> ' . (int) ($summary['next_offset'] ?? 0));

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
