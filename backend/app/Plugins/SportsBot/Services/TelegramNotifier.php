<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        $idempotencyKey = $this->idempotencyKey($options);

        foreach ($targets as $target) {
            $chatId = (string) ($target['chat_id'] ?? '');
            $messageThreadId = $target['message_thread_id'] ?? null;

            $existing = $this->previousSentResult($idempotencyKey, $chatId, $messageThreadId, $type);
            if ($existing !== null) {
                $results[] = array_merge($existing, [
                    'route_key' => $resolved['resolved_route_key'] ?? $requestedRouteKey,
                    'fallback' => (bool) ($resolved['fallback'] ?? false),
                    'idempotency_key' => $idempotencyKey,
                    'skipped' => true,
                ]);
                continue;
            }

            $logRow = SportsBotTelegramMessage::create($this->messageAttributes([
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
            ], $idempotencyKey));

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
                    'idempotency_key' => $idempotencyKey,
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
        $resolved = $this->routingService->resolveTargets(
            TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT))
        );
        $targets = $resolved['targets'] ?? [];

        if ($targets === []) {
            return $this->sendPhotoToTargets($photoPath, $caption, $options, $targets);
        }

        $results = [];
        $tempFiles = [];

        try {
            foreach ($this->groupTargetsByBranding($targets) as $group) {
                $groupPhotoPath = $this->resolveGroupPhoto(
                    $photoPath,
                    (array) ($group['branding'] ?? []),
                    $tempFiles
                );

                $results = array_merge(
                    $results,
                    $this->sendPhotoToTargets($groupPhotoPath, $caption, $options, $group['targets'], $resolved)
                );
            }
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }

        return $results;
    }

    /**
     * @param array<int, array{chat_id:string,message_thread_id:int|null,branding?:array<string,mixed>}> $targets
     * @return array<int, array{branding:array<string,mixed>,targets:array<int,array{chat_id:string,message_thread_id:int|null}>}>
     */
    private function groupTargetsByBranding(array $targets): array
    {
        $groups = [];

        foreach ($targets as $target) {
            $branding = (array) ($target['branding'] ?? []);
            ksort($branding);
            $hash = md5(json_encode($branding) ?: '');

            if (!isset($groups[$hash])) {
                $groups[$hash] = [
                    'branding' => $branding,
                    'targets' => [],
                ];
            }

            $groups[$hash]['targets'][] = [
                'chat_id' => $target['chat_id'],
                'message_thread_id' => $target['message_thread_id'] ?? null,
            ];
        }

        return array_values($groups);
    }

    /**
     * @param array<string, mixed> $branding
     * @param array<int, string> $tempFiles
     */
    private function resolveGroupPhoto(string $originalPath, array $branding, array &$tempFiles): string
    {
        $watermark = trim((string) ($branding['watermark'] ?? ''));

        if ($watermark === '') {
            return $originalPath;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'sportsbot_wm_');
        if ($tempBase === false) {
            Log::warning('sportsbot.telegram.watermark_temp_failed');

            return $originalPath;
        }

        $tempPath = $tempBase . '.png';
        @unlink($tempBase);

        if (!$this->applyWatermark($originalPath, $tempPath, $watermark)) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            Log::warning('sportsbot.telegram.watermark_apply_failed', [
                'source_path' => $originalPath,
            ]);

            return $originalPath;
        }

        $tempFiles[] = $tempPath;

        return $tempPath;
    }

    private function applyWatermark(string $sourcePath, string $outputPath, string $watermarkText): bool
    {
        if (!function_exists('imagepng') || !function_exists('imagesx') || !function_exists('imagesy')) {
            return false;
        }

        $image = $this->loadImageResource($sourcePath);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if (function_exists('imagealphablending')) {
            imagealphablending($image, true);
        }

        if (function_exists('imagesavealpha')) {
            imagesavealpha($image, true);
        }

        $fontPath = $this->resolveFontPath();

        if ($fontPath !== null && function_exists('imagettfbbox') && function_exists('imagettftext')) {
            $fontSize = 18;
            $maxWidth = max(80, $width - 40);
            $bbox = false;

            while ($fontSize >= 10) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $watermarkText);
                if ($bbox === false || ($bbox[2] - $bbox[0]) <= $maxWidth) {
                    break;
                }

                $fontSize -= 2;
            }

            if ($bbox === false) {
                imagedestroy($image);

                return false;
            }

            $color = imagecolorallocatealpha($image, 192, 192, 192, 40);
            $textWidth = $bbox[2] - $bbox[0];
            $x = max(10, (int) (($width - $textWidth) / 2));
            $y = $height - 30;

            imagettftext($image, $fontSize, 0, $x, $y, $color, $fontPath, $watermarkText);
        } else {
            $fontSize = 5;
            $color = imagecolorallocatealpha($image, 192, 192, 192, 60);
            $textWidth = imagefontwidth($fontSize) * mb_strlen($watermarkText);
            $x = max(10, (int) (($width - $textWidth) / 2));
            $y = $height - 24;

            imagestring($image, $fontSize, $x, $y, $watermarkText, $color);
        }

        $written = imagepng($image, $outputPath);
        imagedestroy($image);

        return $written && is_file($outputPath) && filesize($outputPath) > 0;
    }

    private function loadImageResource(string $sourcePath): mixed
    {
        $imageType = function_exists('exif_imagetype') ? @exif_imagetype($sourcePath) : false;

        if ($imageType === 3 && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($sourcePath);
        }

        if ($imageType === 2 && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($sourcePath);
        }

        if ($imageType === 18 && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($sourcePath);
        }

        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $contents = @file_get_contents($sourcePath);
        if ($contents === false) {
            return false;
        }

        return @imagecreatefromstring($contents);
    }

    private function resolveFontPath(): ?string
    {
        $expectedPath = config('plugins.SportsBot.cards.watermark_font_path');

        if (is_string($expectedPath) && $expectedPath !== '' && is_file($expectedPath)) {
            return $expectedPath;
        }

        $commonPaths = [
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/local/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];

        foreach ($commonPaths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{chat_id:string,message_thread_id:int|null}> $targets
     * @param array<string, mixed>|null $resolved
     * @return array<int, array<string, mixed>>
     */
    public function sendPhotoToTargets(
        string $photoPath,
        string $caption,
        array $options,
        array $targets,
        ?array $resolved = null,
    ): array {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        $requestedRouteKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $type = trim((string) ($options['type'] ?? 'PHOTO'));

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        if (!is_file($photoPath)) {
            throw new RuntimeException('SportsBot card image does not exist: ' . $photoPath);
        }

        if ($targets === []) {
            throw new RuntimeException('No Telegram targets provided for route: ' . $requestedRouteKey);
        }

        $results = [];
        $failures = [];
        $idempotencyKey = $this->idempotencyKey($options);

        foreach ($targets as $target) {
            $chatId = (string) ($target['chat_id'] ?? '');
            $messageThreadId = $target['message_thread_id'] ?? null;

            $existing = $this->previousSentResult($idempotencyKey, $chatId, $messageThreadId, $type);
            if ($existing !== null) {
                $results[] = array_merge($existing, [
                    'route_key' => $resolved['resolved_route_key'] ?? $requestedRouteKey,
                    'fallback' => (bool) ($resolved['fallback'] ?? false),
                    'media' => 'photo',
                    'idempotency_key' => $idempotencyKey,
                    'skipped' => true,
                ]);
                continue;
            }

            $logRow = SportsBotTelegramMessage::create($this->messageAttributes([
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
                ], $idempotencyKey));

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
                    'idempotency_key' => $idempotencyKey,
                ];
            } catch (Throwable $error) {
                $logRow->update([
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                ]);

                $failures[] = $error->getMessage();
            }
        }

        foreach ($failures as $error) {
            $results[] = ['error' => $error];
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

    private function idempotencyKey(array $options): string
    {
        return mb_substr(trim((string) ($options['idempotency_key'] ?? $options['payload']['idempotency_key'] ?? '')), 0, 160);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function messageAttributes(array $attributes, string $idempotencyKey): array
    {
        if ($idempotencyKey !== '' && $this->supportsIdempotencyKey()) {
            $attributes['idempotency_key'] = $idempotencyKey;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previousSentResult(string $idempotencyKey, string $chatId, mixed $messageThreadId, string $type): ?array
    {
        if ($idempotencyKey === '' || !$this->supportsIdempotencyKey()) {
            return null;
        }

        try {
            $query = SportsBotTelegramMessage::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('chat_id', $chatId)
                ->where('type', $type !== '' ? $type : 'MESSAGE')
                ->where('status', 'sent')
                ->whereNotNull('telegram_message_id')
                ->latest('id');

            if ($messageThreadId === null) {
                $query->whereNull('message_thread_id');
            } else {
                $query->where('message_thread_id', (int) $messageThreadId);
            }

            $message = $query->first();
            if (!$message instanceof SportsBotTelegramMessage) {
                return null;
            }

            return [
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
                'message_id' => $message->telegram_message_id,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function supportsIdempotencyKey(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }

        try {
            $supported = Schema::hasColumn('sportsbot_telegram_messages', 'idempotency_key');
        } catch (Throwable) {
            $supported = false;
        }

        return $supported;
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
