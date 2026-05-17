<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramNotifier implements NotifierInterface
{
    public function send(string $message, array $options = []): array
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        $chatIds = $this->chatIds();

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        if ($chatIds === []) {
            throw new RuntimeException('Telegram chat ID is not configured.');
        }

        $results = [];

        foreach ($chatIds as $chatId) {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => config('plugins.SportsBot.telegram.parse_mode', 'HTML'),
                    'disable_notification' => (bool) config('plugins.SportsBot.telegram.disable_notification', false),
                ]);

            $ok = $response->successful() && (bool) ($response->json('ok') ?? false);

            if (!$ok) {
                throw new RuntimeException('Telegram sendMessage failed for a configured chat.');
            }

            $results[] = [
                'chat_id' => $chatId,
                'message_id' => $response->json('result.message_id'),
            ];
        }

        return $results;
    }

    public function configured(): bool
    {
        return trim((string) config('plugins.SportsBot.telegram.bot_token', '')) !== ''
            && $this->chatIds() !== [];
    }

    private function chatIds(): array
    {
        $primary = trim((string) config('plugins.SportsBot.telegram.chat_id', ''));
        $extra = config('plugins.SportsBot.telegram.extra_chat_ids', []);

        if (!is_array($extra)) {
            $extra = [];
        }

        return array_values(array_unique(array_filter(
            array_merge([$primary], array_map('strval', $extra)),
            static fn (string $chatId): bool => trim($chatId) !== ''
        )));
    }
}
