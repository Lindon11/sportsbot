<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramTopic;
use App\Plugins\SportsBot\Models\SportsBotTelegramRoute;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SQLite3;
use Throwable;

class TelegramTopicDiscoveryService
{
    private const OFFSET_KEY = 'getUpdatesOffset';

    /**
     * @return array<string, mixed>
     */
    public function sync(int $limit = 100, int $timeout = 0, bool $resetOffset = false): array
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $limit = max(1, min(100, $limit));
        $timeout = max(0, min(50, $timeout));
        $offset = $resetOffset ? 0 : $this->currentOffset();

        $payload = [
            'limit' => $limit,
            'timeout' => $timeout,
            'allowed_updates' => json_encode([
                'message',
                'edited_message',
                'channel_post',
                'edited_channel_post',
                'callback_query',
            ]),
        ];

        if ($offset > 0) {
            $payload['offset'] = $offset;
        }

        $response = Http::asForm()
            ->timeout($timeout + 15)
            ->post("https://api.telegram.org/bot{$token}/getUpdates", $payload);

        if (!$response->successful() || !((bool) $response->json('ok'))) {
            throw new RuntimeException('Telegram getUpdates failed: HTTP ' . $response->status());
        }

        $updates = array_values(array_filter((array) $response->json('result', []), 'is_array'));
        $maxUpdateId = null;
        $topicsSaved = 0;
        $labelsSaved = 0;
        $messagesSeen = 0;

        foreach ($updates as $update) {
            $updateId = $update['update_id'] ?? null;

            if (is_numeric((string) $updateId)) {
                $maxUpdateId = max((int) $updateId, (int) ($maxUpdateId ?? 0));
            }

            foreach ($this->messagesFromUpdate($update) as $message) {
                $messagesSeen++;
                $result = $this->saveTopicFromMessage($message);

                if ($result['saved']) {
                    $topicsSaved++;
                }

                if ($result['label_saved']) {
                    $labelsSaved++;
                }
            }
        }

        $nextOffset = $maxUpdateId !== null ? $maxUpdateId + 1 : $offset;

        if ($nextOffset > $offset) {
            $this->saveOffset($nextOffset);
        }

        $summary = [
            'updates_seen' => count($updates),
            'messages_seen' => $messagesSeen,
            'topics_saved' => $topicsSaved,
            'labels_saved' => $labelsSaved,
            'reset_offset' => $resetOffset,
            'previous_offset' => $offset,
            'next_offset' => $nextOffset,
            'recent_topics' => SportsBotTelegramTopic::query()
                ->latest('last_seen_at')
                ->limit(10)
                ->get()
                ->all(),
        ];

        Log::info('sportsbot.telegram_topics.synced', $summary);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        $legacyPath = (string) config('plugins.SportsBot.legacy.state_db');
        $legacyTopicCount = null;

        if (is_file($legacyPath) && class_exists(SQLite3::class)) {
            try {
                $legacyDb = new SQLite3($legacyPath, SQLITE3_OPEN_READONLY);
                $legacyTopicCount = (int) $legacyDb->querySingle('SELECT COUNT(*) FROM telegram_topics');
                $legacyDb->close();
            } catch (Throwable) {
                $legacyTopicCount = null;
            }
        }

        $webhook = [
            'configured' => false,
            'url' => '',
            'pending_update_count' => null,
            'last_error_message' => null,
        ];

        if ($token !== '') {
            try {
                $response = Http::acceptJson()
                    ->timeout(10)
                    ->get("https://api.telegram.org/bot{$token}/getWebhookInfo");

                if ($response->successful() && (bool) $response->json('ok')) {
                    $result = (array) $response->json('result', []);
                    $webhook = [
                        'configured' => trim((string) ($result['url'] ?? '')) !== '',
                        'url' => (string) ($result['url'] ?? ''),
                        'pending_update_count' => $result['pending_update_count'] ?? null,
                        'last_error_message' => $result['last_error_message'] ?? null,
                        'allowed_updates' => $result['allowed_updates'] ?? [],
                    ];
                }
            } catch (Throwable $error) {
                $webhook['last_error_message'] = $error->getMessage();
            }
        }

        return [
            'bot_token_configured' => $token !== '',
            'laravel_offset' => $this->currentOffset(),
            'webhook' => $webhook,
            'legacy_state_db' => [
                'path' => $legacyPath,
                'exists' => is_file($legacyPath),
                'telegram_topics' => $legacyTopicCount,
            ],
            'note' => 'Telegram Bot API cannot list all forum topics historically. SportsBot can sync pending bot updates, import legacy topics, or learn topics when you post /topic Name in each forum topic.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function importLegacyTopics(?string $path = null, bool $importRoutes = true): array
    {
        if (!class_exists(SQLite3::class)) {
            throw new RuntimeException('PHP SQLite3 extension is not available.');
        }

        $path = $path !== null && trim($path) !== ''
            ? $path
            : (string) config('plugins.SportsBot.legacy.state_db');

        if (!is_file($path)) {
            throw new RuntimeException('Legacy SportsBot state database was not found: ' . $path);
        }

        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $tableExists = (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'telegram_topics'");

        if ($tableExists <= 0) {
            $db->close();
            throw new RuntimeException('Legacy telegram_topics table was not found.');
        }

        $result = $db->query(
            'SELECT chat_id, message_thread_id, name, route, source, first_seen_at, updated_at
             FROM telegram_topics
             ORDER BY updated_at DESC, message_thread_id ASC'
        );

        $seenRows = 0;
        $topicsImported = 0;
        $routesImported = 0;
        $now = now();

        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $seenRows++;
            $chatId = trim((string) ($row['chat_id'] ?? ''));
            $threadId = is_numeric((string) ($row['message_thread_id'] ?? '')) ? (int) $row['message_thread_id'] : 0;

            if ($chatId === '' || $threadId <= 0) {
                continue;
            }

            $title = trim((string) ($row['name'] ?? ''));
            $source = trim((string) ($row['source'] ?? 'legacy_import')) ?: 'legacy_import';
            $firstSeenAt = $this->parseLegacyDate($row['first_seen_at'] ?? null) ?? $now;
            $lastSeenAt = $this->parseLegacyDate($row['updated_at'] ?? null) ?? $firstSeenAt;

            $topic = SportsBotTelegramTopic::query()
                ->where('chat_id', $chatId)
                ->where('message_thread_id', $threadId)
                ->first();

            if (!$topic instanceof SportsBotTelegramTopic) {
                $topic = new SportsBotTelegramTopic([
                    'chat_id' => $chatId,
                    'message_thread_id' => $threadId,
                    'first_seen_at' => $firstSeenAt,
                ]);
            }

            if ($title !== '') {
                $topic->title = mb_substr($title, 0, 255);
            }

            $topic->source = 'legacy:' . $source;
            $topic->last_seen_at = $lastSeenAt;
            $topic->save();
            $topicsImported++;

            $routeKey = TelegramRouteKeys::normalize((string) ($row['route'] ?? ''));
            if ($importRoutes && in_array($routeKey, TelegramRouteKeys::all(), true) && $routeKey !== TelegramRouteKeys::DEFAULT) {
                $route = SportsBotTelegramRoute::query()
                    ->where('route_key', $routeKey)
                    ->where('chat_id', $chatId)
                    ->where('message_thread_id', $threadId)
                    ->first() ?? new SportsBotTelegramRoute([
                        'route_key' => $routeKey,
                        'chat_id' => $chatId,
                        'message_thread_id' => $threadId,
                    ]);

                $route->fill([
                    'label' => $title !== '' ? $title : $routeKey,
                    'enabled' => true,
                    'fallback' => false,
                ]);
                $route->save();
                $routesImported++;
            }
        }

        $db->close();

        $summary = [
            'path' => $path,
            'rows_seen' => $seenRows,
            'topics_imported' => $topicsImported,
            'routes_imported' => $routesImported,
            'recent_topics' => SportsBotTelegramTopic::query()
                ->latest('last_seen_at')
                ->limit(10)
                ->get()
                ->all(),
        ];

        Log::info('sportsbot.telegram_topics.legacy_imported', $summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $update
     * @return array<int, array<string, mixed>>
     */
    private function messagesFromUpdate(array $update): array
    {
        $messages = [];

        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (is_array($update[$key] ?? null)) {
                $messages[] = $update[$key];
            }
        }

        $callbackMessage = $update['callback_query']['message'] ?? null;
        if (is_array($callbackMessage)) {
            $messages[] = $callbackMessage;
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{saved:bool,label_saved:bool}
     */
    private function saveTopicFromMessage(array $message): array
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = trim((string) ($chat['id'] ?? ''));
        $threadId = is_numeric((string) ($message['message_thread_id'] ?? ''))
            ? (int) $message['message_thread_id']
            : 0;

        if ($chatId === '' || $threadId <= 0) {
            return ['saved' => false, 'label_saved' => false];
        }

        $source = 'message';
        $title = null;

        if (is_array($message['forum_topic_created'] ?? null)) {
            $source = 'forum_topic_created';
            $title = trim((string) ($message['forum_topic_created']['name'] ?? '')) ?: null;
        } elseif (is_array($message['forum_topic_edited'] ?? null)) {
            $source = 'forum_topic_edited';
            $title = trim((string) ($message['forum_topic_edited']['name'] ?? '')) ?: null;
        }

        $commandTitle = $this->topicLabelFromCommand($this->messageText($message));
        $labelSaved = false;

        if ($commandTitle !== null) {
            $title = $commandTitle;
            $source = 'topic_command';
            $labelSaved = true;
        }

        $now = now();
        $topic = SportsBotTelegramTopic::query()
            ->where('chat_id', $chatId)
            ->where('message_thread_id', $threadId)
            ->first();

        if (!$topic instanceof SportsBotTelegramTopic) {
            $topic = new SportsBotTelegramTopic([
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
                'first_seen_at' => $now,
            ]);
        }

        if ($title !== null && $title !== '') {
            $topic->title = mb_substr($title, 0, 255);
        }

        $topic->source = $source;
        $topic->last_seen_at = $now;
        $topic->save();

        return ['saved' => true, 'label_saved' => $labelSaved];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function messageText(array $message): string
    {
        return trim((string) ($message['text'] ?? $message['caption'] ?? ''));
    }

    private function topicLabelFromCommand(string $text): ?string
    {
        if (preg_match('/^\/(?:topic|settopic)(?:@\w+)?\s+(.+)$/is', trim($text), $matches) !== 1) {
            return null;
        }

        $label = trim((string) $matches[1]);

        return $label !== '' ? mb_substr($label, 0, 80) : null;
    }

    private function currentOffset(): int
    {
        if (!Schema::hasTable('sportsbot_telegram_update_state')) {
            return 0;
        }

        try {
            $value = DB::table('sportsbot_telegram_update_state')
                ->where('state_key', self::OFFSET_KEY)
                ->value('value');
        } catch (Throwable) {
            return 0;
        }

        return is_numeric((string) $value) ? max(0, (int) $value) : 0;
    }

    private function saveOffset(int $offset): void
    {
        if (!Schema::hasTable('sportsbot_telegram_update_state')) {
            return;
        }

        DB::table('sportsbot_telegram_update_state')->updateOrInsert(
            ['state_key' => self::OFFSET_KEY],
            ['value' => (string) max(0, $offset), 'updated_at' => now()]
        );
    }

    private function parseLegacyDate(mixed $value): mixed
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
