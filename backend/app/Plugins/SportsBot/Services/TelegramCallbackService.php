<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\Content\FixturesTodayContentModule;
use App\Plugins\SportsBot\Services\Content\LiveNowContentModule;
use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramCallbackService
{
    private const VALID_CALLBACK_DATA = [
        'fixtures_today',
        'fixtures_football',
        'fixtures_basketball',
        'tv_guide',
        'live_now',
        'my_teams',
        'back_main',
    ];

    public function __construct(
        private readonly TelegramNotifier $notifier = new TelegramNotifier(),
        private readonly FixturesTodayContentModule $fixturesModule = new FixturesTodayContentModule(),
        private readonly TvGuideContentModule $tvGuideModule = new TvGuideContentModule(),
        private readonly LiveNowContentModule $liveNowModule = new LiveNowContentModule(),
        private readonly FixturesTodayService $fixturesService = new FixturesTodayService(),
        private readonly FixturesTodayFormatter $fixturesFormatter = new FixturesTodayFormatter(),
        private readonly TvGuideService $tvGuideService = new TvGuideService(),
        private readonly TvGuideFormatter $tvGuideFormatter = new TvGuideFormatter(),
        private readonly LiveNowService $liveNowService = new LiveNowService(),
        private readonly LiveNowFormatter $liveNowFormatter = new LiveNowFormatter(),
    ) {
    }

    public function isValidCallbackData(string $data): bool
    {
        return in_array($data, self::VALID_CALLBACK_DATA, true);
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function handle(array $callbackQuery): void
    {
        $callbackData = (string) ($callbackQuery['data'] ?? '');
        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $messageId = $message['message_id'] ?? null;
        $messageThreadId = $message['is_topic_message'] ?? false
            ? ($message['message_thread_id'] ?? null)
            : null;

        Log::info('sportsbot.telegram.callback_received', [
            'callback_data' => $callbackData,
            'callback_query_id' => $callbackQueryId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'message_thread_id' => $messageThreadId,
        ]);

        if (!$this->isValidCallbackData($callbackData)) {
            Log::warning('sportsbot.telegram.callback_invalid_data', [
                'callback_data' => $callbackData,
            ]);

            $this->answerCallbackQuery($callbackQueryId, 'Invalid option.');
            return;
        }

        $this->answerCallbackQuery($callbackQueryId);

        try {
            $this->processCallback($callbackData, $chatId, $messageId, $messageThreadId);
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.callback_processing_failed', [
                'callback_data' => $callbackData,
                'chat_id' => $chatId,
                'error' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ]);
        }

        SportsBotTelegramUpdateState::create([
            'update_id' => $callbackQueryId,
            'type' => 'callback_query',
            'chat_id' => $chatId,
            'message_thread_id' => $messageThreadId !== null ? (string) $messageThreadId : null,
            'callback_data' => $callbackData,
            'callback_query_id' => $callbackQueryId,
            'telegram_message_id' => $messageId !== null ? (string) $messageId : null,
            'status' => 'processed',
            'payload' => [
                'callback_query' => $callbackQuery,
            ],
        ]);
    }

    private function processCallback(string $callbackData, string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        switch ($callbackData) {
            case 'fixtures_today':
            case 'back_main':
                $this->sendFixturesTodayResponse($chatId, $messageId, $messageThreadId);
                break;

            case 'fixtures_football':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Football');
                break;

            case 'fixtures_basketball':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Basketball');
                break;

            case 'tv_guide':
                $this->sendTvGuideResponse($chatId, $messageId, $messageThreadId);
                break;

            case 'live_now':
                $this->sendLiveNowResponse($chatId, $messageId, $messageThreadId);
                break;

            case 'my_teams':
                $this->sendMyTeamsResponse($chatId, $messageId, $messageThreadId);
                break;
        }
    }

    private function sendFixturesTodayResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->fixturesService->buildSummary();
        $message = $this->fixturesFormatter->format($summary);

        $replyMarkup = SportsBotInlineKeyboardBuilder::fixturesTodayReplyMarkup();

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    private function sendSportFilteredFixtures(string $chatId, mixed $messageId, mixed $messageThreadId, string $sport): void
    {
        $summary = $this->fixturesService->buildSummary();
        $grouped = (array) ($summary['grouped'] ?? []);
        $sportFixtures = array_values(array_filter((array) ($grouped[$sport] ?? []), 'is_array'));

        $filteredSummary = [
            ...$summary,
            'grouped' => [$sport => $sportFixtures],
            'fixtures_total' => count($sportFixtures),
            'sport_order' => [$sport],
        ];

        $message = $this->fixturesFormatter->format($filteredSummary);
        $replyMarkup = SportsBotInlineKeyboardBuilder::fixturesTodayReplyMarkup();

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    private function sendTvGuideResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->tvGuideService->buildSummary();
        $message = $this->tvGuideFormatter->format($summary);
        $replyMarkup = SportsBotInlineKeyboardBuilder::backReplyMarkup();

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    private function sendLiveNowResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->liveNowService->buildSummary();
        $grouped = (array) ($summary['grouped'] ?? []);
        $totalLive = 0;
        $limitedGrouped = [];

        foreach ($grouped as $sport => $matches) {
            if (is_array($matches)) {
                $limited = array_slice($matches, 0, 10);
                $limitedGrouped[$sport] = $limited;
                $totalLive += count($limited);
            }
        }

        $filteredSummary = [
            ...$summary,
            'grouped' => $limitedGrouped,
            'live_total' => $totalLive,
        ];

        $message = $this->liveNowFormatter->format($filteredSummary);
        $replyMarkup = SportsBotInlineKeyboardBuilder::backReplyMarkup();

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    private function sendMyTeamsResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $message = "⭐ My Teams\n\nYour followed teams will appear here soon.\n\nStay tuned for team-specific alerts and fixtures!";
        $replyMarkup = SportsBotInlineKeyboardBuilder::backReplyMarkup();

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    private function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        if ($token === '') {
            return;
        }

        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        try {
            Http::asForm()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", $payload);
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.answer_callback_failed', [
                'callback_query_id' => $callbackQueryId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $replyMarkup
     */
    private function editMessageText(string $chatId, mixed $messageId, string $text, array $replyMarkup, mixed $messageThreadId = null): void
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        if ($token === '') {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'text' => $text,
            'reply_markup' => json_encode($replyMarkup),
        ];

        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = (string) $messageThreadId;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/editMessageText", $payload);

            $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
            if (!$ok) {
                Log::warning('sportsbot.telegram.edit_message_failed', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'response' => $response->json(),
                ]);
            }
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.edit_message_error', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $replyMarkup
     */
    private function sendMessageWithKeyboard(string $chatId, string $text, array $replyMarkup, mixed $messageThreadId = null): void
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        if ($token === '') {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($replyMarkup),
        ];

        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = (string) $messageThreadId;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

            $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
            if (!$ok) {
                Log::warning('sportsbot.telegram.send_message_with_keyboard_failed', [
                    'chat_id' => $chatId,
                    'response' => $response->json(),
                ]);
            }
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.send_message_with_keyboard_error', [
                'chat_id' => $chatId,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
