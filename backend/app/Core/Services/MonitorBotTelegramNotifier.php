<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MonitorBotTelegramNotifier
{
    public function configured(): bool
    {
        return $this->token() !== '' && $this->targets() !== [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sendMessage(string $message, array $options = []): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('Monitor bot Telegram token or target is not configured.');
        }

        $results = [];
        $failures = [];

        foreach ($this->targets() as $target) {
            $payload = [
                'chat_id' => $target['chat_id'],
                'text' => $message,
                'disable_notification' => $this->disableNotification(),
            ];

            if ($target['message_thread_id'] !== null) {
                $payload['message_thread_id'] = (string) $target['message_thread_id'];
                $this->reopenTopic((string) $target['chat_id'], (int) $target['message_thread_id']);
            }

            $parseMode = trim((string) config('services.monitor_bot.telegram_parse_mode', 'HTML'));
            if ($parseMode !== '') {
                $payload['parse_mode'] = $parseMode;
            }

            try {
                $response = Http::asForm()
                    ->timeout(15)
                    ->post($this->apiUrl('sendMessage'), $payload);

                $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
                if (!$ok) {
                    $failures[] = 'Telegram sendMessage failed for monitor target ' . $target['chat_id'];
                    continue;
                }

                $results[] = [
                    'platform' => 'monitor_telegram',
                    'chat_id' => (string) $target['chat_id'],
                    'message_thread_id' => $target['message_thread_id'],
                    'message_id' => $response->json('result.message_id'),
                ];
            } catch (Throwable $error) {
                $failures[] = $error->getMessage();
            }
        }

        if ($results === [] && $failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sendPhoto(string $photoPath, string $caption, array $options = []): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('Monitor bot Telegram token or target is not configured.');
        }

        if (!is_file($photoPath)) {
            throw new RuntimeException('Monitor bot alert card does not exist: ' . $photoPath);
        }

        $results = [];
        $failures = [];

        foreach ($this->targets() as $target) {
            $payload = [
                'chat_id' => $target['chat_id'],
                'caption' => $caption,
                'disable_notification' => $this->disableNotification(),
            ];

            if ($target['message_thread_id'] !== null) {
                $payload['message_thread_id'] = (string) $target['message_thread_id'];
                $this->reopenTopic((string) $target['chat_id'], (int) $target['message_thread_id']);
            }

            $parseMode = trim((string) config('services.monitor_bot.telegram_parse_mode', 'HTML'));
            if ($parseMode !== '') {
                $payload['parse_mode'] = $parseMode;
            }

            try {
                $response = Http::asMultipart()
                    ->attach('photo', file_get_contents($photoPath), basename($photoPath))
                    ->timeout(20)
                    ->post($this->apiUrl('sendPhoto'), $payload);

                $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
                if (!$ok) {
                    $failures[] = 'Telegram sendPhoto failed for monitor target ' . $target['chat_id'];
                    continue;
                }

                $results[] = [
                    'platform' => 'monitor_telegram',
                    'chat_id' => (string) $target['chat_id'],
                    'message_thread_id' => $target['message_thread_id'],
                    'message_id' => $response->json('result.message_id'),
                    'media' => 'photo',
                ];
            } catch (Throwable $error) {
                $failures[] = $error->getMessage();
            }
        }

        if ($results === [] && $failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }

        return $results;
    }

    private function token(): string
    {
        return trim((string) config('services.monitor_bot.telegram_token', ''));
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token() . '/' . $method;
    }

    private function disableNotification(): bool
    {
        return (bool) config('services.monitor_bot.telegram_disable_notification', false);
    }

    /**
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function targets(): array
    {
        $targets = [];

        $primaryChatId = trim((string) config('services.monitor_bot.telegram_chat_id', ''));
        if ($primaryChatId !== '') {
            $targets[] = [
                'chat_id' => $primaryChatId,
                'message_thread_id' => $this->nullableInt(config('services.monitor_bot.telegram_message_thread_id')),
            ];
        }

        foreach ($this->parseExtraTargets(config('services.monitor_bot.telegram_extra_targets', '')) as $target) {
            $targets[] = $target;
        }

        $unique = [];
        foreach ($targets as $target) {
            $chatId = trim((string) $target['chat_id']);
            if ($chatId === '') {
                continue;
            }

            $threadId = $target['message_thread_id'];
            $key = $chatId . ':' . ($threadId !== null ? (string) $threadId : '-');
            $unique[$key] = [
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
            ];
        }

        return array_values($unique);
    }

    /**
     * @return array<int, array{chat_id:string,message_thread_id:int|null}>
     */
    private function parseExtraTargets(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = array_filter(array_map('trim', explode(',', (string) $value)));
        }

        $targets = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $chatId = trim((string) ($item['chat_id'] ?? $item['chat'] ?? $item['id'] ?? ''));
                $threadId = $this->nullableInt($item['message_thread_id'] ?? $item['thread_id'] ?? null);
            } else {
                $parts = explode(':', (string) $item, 2);
                $chatId = trim($parts[0] ?? '');
                $threadId = $this->nullableInt($parts[1] ?? null);
            }

            if ($chatId !== '') {
                $targets[] = [
                    'chat_id' => $chatId,
                    'message_thread_id' => $threadId,
                ];
            }
        }

        return $targets;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' ? (int) $value : null;
    }

    private function reopenTopic(string $chatId, int $messageThreadId): void
    {
        try {
            Http::asForm()
                ->timeout(5)
                ->post($this->apiUrl('reopenForumTopic'), [
                    'chat_id' => $chatId,
                    'message_thread_id' => $messageThreadId,
                ]);
        } catch (Throwable $error) {
            Log::debug('monitor_bot.telegram.reopen_topic_failed', [
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
