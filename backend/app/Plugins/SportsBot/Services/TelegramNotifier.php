<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sendPhoto(string $photoPath, string $caption, array $options = []): array
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        $requestedRouteKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $type = trim((string) ($options['type'] ?? 'PHOTO'));

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        if (!is_file($photoPath)) {
            throw new RuntimeException('SportsBot card image does not exist: ' . $photoPath);
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
                'type' => $type !== '' ? $type : 'PHOTO',
                'status' => 'sending',
                'payload' => [
                    'caption' => $caption,
                    'photo_path' => $photoPath,
                    'options' => $options,
                    'routing' => $resolved,
                ],
            ]);

            $payload = [
                'chat_id' => $chatId,
                'caption' => $caption,
                'disable_notification' => (bool) ($options['disable_notification'] ?? config('plugins.SportsBot.telegram.disable_notification', false)),
            ];

            if (!empty($options['reply_markup'])) {
                $payload['reply_markup'] = json_encode($options['reply_markup']);
            }

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
                $response = Http::asMultipart()
                    ->attach('photo', file_get_contents($photoPath), basename($photoPath))
                    ->timeout(20)
                    ->post("https://api.telegram.org/bot{$token}/sendPhoto", $payload);

                $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
                $responseBody = $response->json();

                if (!$ok) {
                    $error = 'Telegram sendPhoto failed for chat ' . $chatId;
                    $logRow->update([
                        'status' => 'failed',
                        'error' => $error,
                        'payload' => array_merge((array) $logRow->payload, ['telegram_response' => $responseBody]),
                    ]);
                    $failures[] = $error;
                    continue;
                }

                $telegramMessageId = $response->json('result.message_id');
                $logRow->update([
                    'telegram_message_id' => $telegramMessageId,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'payload' => array_merge((array) $logRow->payload, ['telegram_response' => $responseBody]),
                ]);

                $results[] = [
                    'chat_id' => $chatId,
                    'message_thread_id' => $messageThreadId,
                    'message_id' => $telegramMessageId,
                    'route_key' => $resolved['resolved_route_key'] ?? $requestedRouteKey,
                    'fallback' => (bool) ($resolved['fallback'] ?? false),
                    'media' => 'photo',
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
            throw new RuntimeException('Telegram sendPhoto failed: ' . implode(' | ', $failures));
        }

        return $results;
    }

    public function editMessageMedia(string $chatId, mixed $messageId, string $photoPath, string $caption, array $replyMarkup = []): bool
    {
        if (!is_file($photoPath)) {
            return false;
        }

        $media = [
            'type' => 'photo',
            'media' => 'attach://photo',
            'caption' => $caption,
            'parse_mode' => (string) config('plugins.SportsBot.telegram.parse_mode', 'HTML'),
        ];

        $payload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'media' => json_encode($media),
        ];

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->telegramMultipart('editMessageMedia', $payload, 'photo', $photoPath);
    }

    public function editMessageCaption(string $chatId, mixed $messageId, string $caption, array $replyMarkup = []): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'caption' => $caption,
            'parse_mode' => (string) config('plugins.SportsBot.telegram.parse_mode', 'HTML'),
        ];

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->telegramPost('editMessageCaption', $payload);
    }

    public function editMessageReplyMarkup(string $chatId, mixed $messageId, array $replyMarkup): bool
    {
        return $this->telegramPost('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'reply_markup' => json_encode($replyMarkup),
        ]);
    }

    public function editMessageText(string $chatId, mixed $messageId, string $text, array $replyMarkup = []): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'text' => $text,
            'parse_mode' => (string) config('plugins.SportsBot.telegram.parse_mode', 'HTML'),
        ];

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->telegramPost('editMessageText', $payload);
    }

    public function configured(?string $routeKey = null): bool
    {
        if (trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken()) === '') {
            return false;
        }

        try {
            $resolved = $this->routingService->resolveTargets($routeKey ?: TelegramRouteKeys::DEFAULT);
            return !empty($resolved['targets']);
        } catch (Throwable) {
            $primary = trim((string) config('plugins.SportsBot.telegram.chat_id', ''));
            $extra = config('plugins.SportsBot.telegram.extra_chat_ids', []);

            return $primary !== '' || (is_array($extra) && $extra !== []);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function telegramPost(string $method, array $payload): bool
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

            $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
            if (!$ok) {
                Log::warning('sportsbot.telegram.method_failed', [
                    'method' => $method,
                    'response' => $response->json(),
                ]);
            }

            return $ok;
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.method_error', [
                'method' => $method,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function telegramMultipart(string $method, array $payload, string $field, string $path): bool
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        if ($token === '' || !is_file($path)) {
            return false;
        }

        try {
            $response = Http::asMultipart()
                ->attach($field, file_get_contents($path), basename($path))
                ->timeout(20)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

            $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
            if (!$ok) {
                Log::warning('sportsbot.telegram.multipart_failed', [
                    'method' => $method,
                    'response' => $response->json(),
                ]);
            }

            return $ok;
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.multipart_error', [
                'method' => $method,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }
}
