<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Models\SportsBotDelivery;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SportsBotNotifier implements NotifierInterface
{
    public function __construct(
        private readonly TelegramNotifier $telegram = new TelegramNotifier(),
        private readonly DiscordNotifier $discord = new DiscordNotifier(),
    ) {
    }

    public function send(string $message, array $options = []): array
    {
        return $this->fanout(
            fn (TelegramNotifier|DiscordNotifier $notifier): array => $notifier->send($message, $options),
            (string) ($options['route_key'] ?? 'default'),
            (string) ($options['type'] ?? 'MESSAGE'),
            'message',
            $options
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sendPhoto(string $photoPath, string $caption, array $options = []): array
    {
        return $this->fanout(
            fn (TelegramNotifier|DiscordNotifier $notifier): array => $notifier->sendPhoto($photoPath, $caption, $options),
            (string) ($options['route_key'] ?? 'default'),
            (string) ($options['type'] ?? 'PHOTO'),
            'photo',
            $options
        );
    }

    /**
     * @param array<int, array{chat_id:string,message_thread_id:int|null}> $telegramTargets
     * @return array<int, array<string, mixed>>
     */
    public function sendPhotoToTargets(string $photoPath, string $caption, array $options, array $telegramTargets): array
    {
        $routeKey = (string) ($options['route_key'] ?? 'default');
        $type = (string) ($options['type'] ?? 'PHOTO');
        $results = [];
        $telegramAttempted = false;
        $discordAttempted = false;

        if ($this->telegram->configured()) {
            $telegramAttempted = true;
            try {
                foreach ($this->telegram->sendPhotoToTargets($photoPath, $caption, $options, $telegramTargets) as $result) {
                    $row = array_merge(['platform' => 'telegram'], $result);
                    $results[] = $row;
                    $this->logDelivery('telegram', !empty($row['skipped']) ? 'skipped' : 'sent', $routeKey, $type, 'photo', $row, null, $options);
                }
            } catch (Throwable $error) {
                $this->logDelivery('telegram', 'failed', $routeKey, $type, 'photo', [], $error->getMessage(), $options);
                throw $error;
            }
        }

        if ($this->discord->configured($routeKey)) {
            $discordAttempted = true;
            try {
                foreach ($this->discord->sendPhoto($photoPath, $caption, $options) as $result) {
                    $results[] = $result;
                    $this->logDelivery('discord', !empty($result['skipped']) ? 'skipped' : 'sent', $routeKey, $type, 'photo', $result, null, $options);
                }
            } catch (Throwable $error) {
                $this->logDelivery('discord', 'failed', $routeKey, $type, 'photo', [], $error->getMessage(), $options);
            }
        }

        if (!$telegramAttempted && !$discordAttempted) {
            $this->logDelivery('none', 'failed', $routeKey, $type, 'photo', [], 'No SportsBot delivery channels are configured.', $options);
            throw new RuntimeException('No SportsBot delivery channels are configured.');
        }

        return $results;
    }

    public function editMessageMedia(string $chatId, mixed $messageId, string $photoPath, string $caption, array $replyMarkup = []): bool
    {
        return $this->telegram->editMessageMedia($chatId, $messageId, $photoPath, $caption, $replyMarkup);
    }

    public function editMessageText(string $chatId, mixed $messageId, string $text, array $replyMarkup = []): bool
    {
        return $this->telegram->editMessageText($chatId, $messageId, $text, $replyMarkup);
    }

    public function configured(?string $routeKey = null): bool
    {
        return $this->telegram->configured($routeKey) || $this->discord->configured($routeKey);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fanout(callable $send, string $routeKey, string $type, string $media, array $options): array
    {
        $results = [];
        $failures = [];
        $attempted = false;

        if ($this->telegram->configured()) {
            $attempted = true;
            try {
                foreach ($send($this->telegram) as $result) {
                    $row = array_merge(['platform' => 'telegram'], $result);
                    $results[] = $row;
                    $this->logDelivery('telegram', !empty($row['skipped']) ? 'skipped' : 'sent', $routeKey, $type, $media, $row, null, $options);
                }
            } catch (Throwable $error) {
                $failures[] = $error->getMessage();
                $this->logDelivery('telegram', 'failed', $routeKey, $type, $media, [], $error->getMessage(), $options);
            }
        }

        if ($this->discord->configured($routeKey)) {
            $attempted = true;
            try {
                foreach ($send($this->discord) as $result) {
                    $results[] = $result;
                    $this->logDelivery('discord', !empty($result['skipped']) ? 'skipped' : 'sent', $routeKey, $type, $media, $result, null, $options);
                }
            } catch (Throwable $error) {
                $failures[] = $error->getMessage();
                $this->logDelivery('discord', 'failed', $routeKey, $type, $media, [], $error->getMessage(), $options);
            }
        }

        if (!$attempted) {
            $this->logDelivery('none', 'failed', $routeKey, $type, $media, [], 'No SportsBot delivery channels are configured.', $options);
            throw new RuntimeException('No SportsBot delivery channels are configured.');
        }

        if ($results === [] && $failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }

        return $results;
    }

    private function logDelivery(
        string $platform,
        string $status,
        string $routeKey,
        string $type,
        string $media,
        array $result,
        ?string $error,
        array $options
    ): void {
        try {
            if (!Schema::hasTable('sportsbot_deliveries')) {
                return;
            }

            $attributes = [
                'platform' => $platform,
                'route_key' => $result['route_key'] ?? $routeKey,
                'type' => $type,
                'status' => $status,
                'target' => $this->deliveryTarget($platform, $result),
                'message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
                'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                'payload' => [
                    'media' => $media,
                    'result' => $result,
                    'content_key' => $options['payload']['content_key'] ?? null,
                    'source' => $options['payload']['source'] ?? null,
                    'fixture_queue_id' => $options['payload']['fixture_queue_id'] ?? null,
                    'event_id' => $options['payload']['event_id'] ?? null,
                ],
                'sent_at' => $status === 'sent' ? now() : null,
            ];

            $idempotencyKey = trim((string) ($result['idempotency_key'] ?? $options['idempotency_key'] ?? $options['payload']['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '' && Schema::hasColumn('sportsbot_deliveries', 'idempotency_key')) {
                $attributes['idempotency_key'] = mb_substr($idempotencyKey, 0, 160);
            }

            SportsBotDelivery::create($attributes);
        } catch (Throwable) {
            // Delivery logging must never block a real Telegram/Discord send.
        }
    }

    private function deliveryTarget(string $platform, array $result): ?string
    {
        if ($platform === 'telegram') {
            $chatId = (string) ($result['chat_id'] ?? '');
            if ($chatId === '') {
                return null;
            }

            $thread = $result['message_thread_id'] ?? null;

            return $chatId . ':' . ($thread !== null ? (string) $thread : '-');
        }

        if ($platform === 'discord') {
            return (string) ($result['webhook'] ?? '');
        }

        return null;
    }
}
