<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramNotifier implements NotifierInterface
{
    public function __construct(
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
    ) {
    }

    public function send(string $message, array $options = []): array
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        $requestedRouteKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $type = trim((string) ($options['type'] ?? 'MESSAGE'));

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $resolved = $this->routingService->resolveTargets($requestedRouteKey);
        $targets = $resolved['targets'] ?? [];

        if ($targets === []) {
            throw new RuntimeException('No Telegram targets resolved for route: ' . $requestedRouteKey);
        }

        $results = [];
        $failures = [];

        foreach ($targets as $target) {
            $chatId = (string) ($target['chat_id'] ?? '');
            $messageThreadId = $target['message_thread_id'] ?? null;

            $logRow = SportsBotTelegramMessage::create([
                'route_key' => $requestedRouteKey,
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
                'type' => $type !== '' ? $type : 'MESSAGE',
                'status' => 'sending',
                'payload' => [
                    'message' => $message,
                    'options' => $options,
                    'routing' => $resolved,
                ],
            ]);

            $payload = [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_notification' => (bool) ($options['disable_notification'] ?? config('plugins.SportsBot.telegram.disable_notification', false)),
            ];

            $parseMode = array_key_exists('parse_mode', $options)
                ? (string) $options['parse_mode']
                : (string) config('plugins.SportsBot.telegram.parse_mode', 'HTML');

            if (trim($parseMode) !== '') {
                $payload['parse_mode'] = $parseMode;
            }

            if ($messageThreadId !== null) {
                $payload['message_thread_id'] = (string) $messageThreadId;
            }

            try {
                $response = Http::asForm()
                    ->timeout(15)
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

                $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
                $responseBody = $response->json();

                if (!$ok) {
                    $error = 'Telegram sendMessage failed for chat ' . $chatId;

                    $logRow->update([
                        'status' => 'failed',
                        'error' => $error,
                        'payload' => array_merge((array) $logRow->payload, [
                            'telegram_response' => $responseBody,
                        ]),
                    ]);

                    $failures[] = $error;
                    continue;
                }

                $telegramMessageId = $response->json('result.message_id');

                $logRow->update([
                    'telegram_message_id' => $telegramMessageId,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'payload' => array_merge((array) $logRow->payload, [
                        'telegram_response' => $responseBody,
                    ]),
                ]);

                $results[] = [
                    'chat_id' => $chatId,
                    'message_thread_id' => $messageThreadId,
                    'message_id' => $telegramMessageId,
                    'route_key' => $resolved['resolved_route_key'] ?? $requestedRouteKey,
                    'fallback' => (bool) ($resolved['fallback'] ?? false),
                ];
            } catch (Throwable $error) {
                $logRow->update([
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                ]);

                $failures[] = $error->getMessage();
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('Telegram sendMessage failed: ' . implode(' | ', $failures));
        }

        return $results;
    }

    public function configured(): bool
    {
        if (trim((string) config('plugins.SportsBot.telegram.bot_token', '')) === '') {
            return false;
        }

        try {
            $resolved = $this->routingService->resolveTargets(TelegramRouteKeys::DEFAULT);
        } catch (Throwable) {
            $primary = trim((string) config('plugins.SportsBot.telegram.chat_id', ''));
            $extra = config('plugins.SportsBot.telegram.extra_chat_ids', []);

            return $primary !== '' || (is_array($extra) && $extra !== []);
        }

        return !empty($resolved['targets']);
    }
}
