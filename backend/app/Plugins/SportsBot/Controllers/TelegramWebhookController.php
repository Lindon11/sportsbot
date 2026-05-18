<?php

namespace App\Plugins\SportsBot\Controllers;

use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\TelegramCallbackService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramCallbackService $callbackService,
    ) {
    }

    /**
     * Handle incoming Telegram webhook update.
     */
    public function handle(Request $request): JsonResponse
    {
        if (!app(SportsBotSettingsService::class)->resolveWebhookEnabled()) {
            Log::warning('sportsbot.telegram.webhook_disabled', [
                'remote_addr' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Webhook mode is disabled.'], 403);
        }

        // Validate secret token
        if (!$this->validateSecretToken($request)) {
            Log::warning('sportsbot.telegram.webhook_invalid_token', [
                'remote_addr' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Invalid webhook secret token.'], 403);
        }

        // Parse update
        $update = $request->all();

        if (empty($update)) {
            Log::warning('sportsbot.telegram.webhook_empty_payload');

            return response()->json(['ok' => false, 'error' => 'Empty webhook payload.'], 400);
        }

        // Log received update
        Log::info('sportsbot.telegram.webhook_received', [
            'has_message' => isset($update['message']),
            'has_callback_query' => isset($update['callback_query']),
            'update_id' => $update['update_id'] ?? null,
        ]);

        // Process message
        if (isset($update['message'])) {
            $this->handleMessage($update['message'], $update['update_id'] ?? null);
        }

        // Process callback query
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query'], $update['update_id'] ?? null);
        }

        // Always return 200 OK quickly
        return response()->json(['ok' => true]);
    }

    /**
     * Health check endpoint for webhook diagnostics.
     */
    public function health(Request $request): JsonResponse
    {
        $settings = app(SportsBotSettingsService::class);
        $enabled = $settings->resolveWebhookEnabled();
        $botToken = trim($settings->resolveBotToken());

        $latestUpdate = SportsBotTelegramUpdateState::query()
            ->orderByDesc('id')
            ->first();

        $latestCallback = SportsBotTelegramUpdateState::query()
            ->where('type', 'callback_query')
            ->orderByDesc('id')
            ->first();

        $lastHourCount = SportsBotTelegramUpdateState::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subHour())
            ->count();

        return response()->json([
            'ok' => true,
            'timestamp' => now()->toISOString(),
            'webhook' => [
                'enabled' => $enabled,
                'bot_token_configured' => $botToken !== '',
                'endpoint' => url('/sportsbot/telegram/webhook'),
            ],
            'stats' => [
                'total_updates' => SportsBotTelegramUpdateState::count(),
                'last_hour_updates' => $lastHourCount,
                'total_callbacks' => SportsBotTelegramUpdateState::where('type', 'callback_query')->count(),
            ],
            'latest_update' => $latestUpdate ? [
                'id' => $latestUpdate->id,
                'type' => $latestUpdate->type,
                'callback_data' => $latestUpdate->callback_data,
                'chat_id' => $latestUpdate->chat_id,
                'created_at' => $latestUpdate->created_at?->toISOString(),
            ] : null,
            'latest_callback' => $latestCallback ? [
                'id' => $latestCallback->id,
                'callback_data' => $latestCallback->callback_data,
                'chat_id' => $latestCallback->chat_id,
                'created_at' => $latestCallback->created_at?->toISOString(),
            ] : null,
        ]);
    }

    /**
     * Validate the X-Telegram-Bot-Api-Secret-Token header.
     */
    private function validateSecretToken(Request $request): bool
    {
        $expectedSecret = trim((string) config('plugins.SportsBot.telegram.webhook_secret', ''));

        if ($expectedSecret === '') {
            return true;
        }

        $providedSecret = trim((string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''));

        if ($providedSecret === '') {
            return false;
        }

        return hash_equals($expectedSecret, $providedSecret);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message, mixed $updateId): void
    {
        Log::info('sportsbot.telegram.message_received', [
            'chat_id' => $message['chat']['id'] ?? null,
            'message_id' => $message['message_id'] ?? null,
            'text' => mb_substr((string) ($message['text'] ?? ''), 0, 200),
        ]);

        try {
            SportsBotTelegramUpdateState::create([
                'update_id' => $updateId,
                'type' => 'message',
                'chat_id' => (string) ($message['chat']['id'] ?? ''),
                'message_thread_id' => isset($message['is_topic_message']) && $message['is_topic_message']
                    ? (string) ($message['message_thread_id'] ?? '')
                    : null,
                'telegram_message_id' => (string) ($message['message_id'] ?? ''),
                'status' => 'received',
                'payload' => [
                    'message' => $message,
                ],
            ]);
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.message_storage_failed', [
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery, mixed $updateId): void
    {
        $callbackData = (string) ($callbackQuery['data'] ?? '');
        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');

        Log::info('sportsbot.telegram.callback_query_received', [
            'callback_query_id' => $callbackQueryId,
            'callback_data' => $callbackData,
            'chat_id' => $callbackQuery['message']['chat']['id'] ?? null,
        ]);

        try {
            app(TelegramCallbackService::class)->handle($callbackQuery);
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.callback_query_handler_failed', [
                'callback_query_id' => $callbackQueryId,
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ]);
        }
    }
}
