<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\Content\LiveNowContentModule;
use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use App\Plugins\SportsBot\Support\SportsBotSports;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramCallbackService
{
    public function __construct(
        private readonly TelegramNotifier $notifier = new TelegramNotifier(),
        private readonly TvGuideContentModule $tvGuideModule = new TvGuideContentModule(),
        private readonly LiveNowContentModule $liveNowModule = new LiveNowContentModule(),
        private readonly SportsBotRichContentService $richContent = new SportsBotRichContentService(),
        private readonly SportsBotFollowService $followService = new SportsBotFollowService(),
    ) {
    }

    public function isValidCallbackData(string $data): bool
    {
        return preg_match('/^(match|stats|lineups|highlights|tv|table|scorers|team|follow_team|unfollow_team|follow_league|unfollow_league|team_next|team_prev|fixtures|live|top_teams):[A-Za-z0-9_.-]+(?::[A-Za-z0-9_.-]+)?$|^(tv_guide|live_now)$/', $data) === 1;
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function handle(array $callbackQuery): void
    {
        $callbackData = (string) ($callbackQuery['data'] ?? '');
        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');
        $telegramUser = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];
        $message = $callbackQuery['message'] ?? [];
        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $messageId = $message['message_id'] ?? null;
        $messageThreadId = $message['is_topic_message'] ?? false
            ? ($message['message_thread_id'] ?? null)
            : null;
        $matchedHandler = null;
        $callbackError = null;
        $status = 'processed';

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
            SportsBotTelegramUpdateState::create([
                'update_id' => $callbackQueryId,
                'type' => 'callback_query',
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId !== null ? (string) $messageThreadId : null,
                'callback_data' => $callbackData,
                'callback_query_id' => $callbackQueryId,
                'telegram_message_id' => $messageId !== null ? (string) $messageId : null,
                'status' => 'invalid',
                'payload' => [
                    'callback_data' => $callbackData,
                    'callback_action' => $this->callbackAction($callbackData),
                    'callback_handler' => null,
                    'callback_error' => 'Invalid option.',
                    'callback_query' => $callbackQuery,
                ],
            ]);
            return;
        }

        if (!$this->claimCallback($callbackData, (string) ($telegramUser['id'] ?? $chatId))) {
            $this->answerCallbackQuery($callbackQueryId, 'One moment…');
            return;
        }

        $this->answerCallbackQuery($callbackQueryId);

        try {
            $matchedHandler = $this->processCallback($callbackData, $chatId, $messageId, $messageThreadId, $telegramUser);
            Log::info('sportsbot.telegram.callback_handler_matched', [
                'callback_data' => $callbackData,
                'handler' => $matchedHandler,
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
        } catch (Throwable $error) {
            $status = 'failed';
            $callbackError = $error->getMessage();
            Log::error('sportsbot.telegram.callback_processing_failed', [
                'callback_data' => $callbackData,
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'handler' => $matchedHandler,
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
            'status' => $status,
            'payload' => [
                'callback_data' => $callbackData,
                'callback_action' => $this->callbackAction($callbackData),
                'callback_handler' => $matchedHandler,
                'callback_error' => $callbackError,
                'callback_query' => $callbackQuery,
            ],
        ]);
    }

    private function processCallback(string $callbackData, string $chatId, mixed $messageId, mixed $messageThreadId, array $telegramUser = []): string
    {
        // Topic-first: top-level menus route to content module responses
        $topicHandler = match ($callbackData) {
            'tv_guide' => fn () => $this->sendTvGuideResponse($chatId, $messageId, $messageThreadId),
            'live_now' => fn () => $this->sendLiveNowResponse($chatId, $messageId, $messageThreadId),
            default => null,
        };

        if ($topicHandler !== null) {
            $topicHandler();
            return $callbackData;
        }

        // Rich content: parameterised callbacks like match:xxx, stats:xxx, fixtures:xxx, etc.
        $richHandler = $this->processRichCallback($callbackData, $chatId, $messageId, $messageThreadId, $telegramUser);
        if ($richHandler !== null) {
            return $richHandler;
        }

        Log::warning('sportsbot.telegram.callback_unhandled', [
            'callback_data' => $callbackData,
        ]);

        // Fallback: show TV guide (most general topic)
        $this->sendTvGuideResponse($chatId, $messageId, $messageThreadId);
        return 'fallback.tv_guide';
    }

    private function processRichCallback(string $callbackData, string $chatId, mixed $messageId, mixed $messageThreadId, array $telegramUser): ?string
    {
        $parts = explode(':', $callbackData);
        $action = $parts[0] ?? '';
        $id = $parts[1] ?? '';
        $page = max(1, (int) ($parts[2] ?? 1));

        if ($id === '') {
            return null;
        }

        $payload = match ($action) {
            'match' => $this->richContent->match($id),
            'stats' => $this->richContent->stats($id),
            'lineups' => $this->richContent->lineups($id),
            'highlights' => $this->richContent->highlights($id),
            'tv' => $this->richContent->eventTv($id),
            'table' => $this->richContent->leagueTable($id, $page),
            'scorers' => $this->richContent->topScorers($id, $page),
            'team' => $this->richContent->team($id),
            'team_next' => $this->richContent->teamSchedule($id, 'next', $page),
            'team_prev' => $this->richContent->teamSchedule($id, 'previous', $page),
            'fixtures' => $this->richContent->sportFixtures($id, $page),
            default => null,
        };

        if (is_array($payload)) {
            $this->deliverRich($chatId, $messageId, $payload, $messageThreadId);
            return 'rich.' . $action;
        }

        if ($action === 'follow_team') {
            $team = app(TheSportsDbClient::class)->lookupTeam($id) ?? ['idTeam' => $id, 'strTeam' => 'Team'];
            $this->followService->follow($telegramUser, 'team', $team, $chatId);
            $this->deliverRich($chatId, $messageId, [
                'caption' => '⭐ Following <b>' . e((string) ($team['strTeam'] ?? 'Team')) . "</b>\nAlerts enabled: goals, fixtures, TV, live and news.",
                'reply_markup' => SportsBotInlineKeyboardBuilder::teamReplyMarkup($id),
                'card' => null,
            ], $messageThreadId);
            return 'follow.team';
        }

        if ($action === 'unfollow_team') {
            $this->followService->unfollow((string) ($telegramUser['id'] ?? ''), 'team', $id);
            $this->deliverRich($chatId, $messageId, [
                'caption' => 'Removed that team from My Teams.',
                'reply_markup' => SportsBotInlineKeyboardBuilder::tvReplyMarkup(),
                'card' => null,
            ], $messageThreadId);
            return 'unfollow.team';
        }

        if ($action === 'follow_league') {
            $league = app(TheSportsDbClient::class)->lookupLeague($id) ?? ['idLeague' => $id, 'strLeague' => 'League'];
            $this->followService->follow($telegramUser, 'league', $league, $chatId);
            $this->deliverRich($chatId, $messageId, [
                'caption' => '⭐ Following <b>' . e((string) ($league['strLeague'] ?? 'League')) . '</b>.',
                'reply_markup' => SportsBotInlineKeyboardBuilder::tableReplyMarkup($id),
                'card' => null,
            ], $messageThreadId);
            return 'follow.league';
        }

        if ($action === 'live') {
            $this->deliverRich($chatId, $messageId, [
                'caption' => "🔴 <b>Live Now</b>\n\nShowing live digest for " . e(SportsBotSports::label($id)) . '.',
                'reply_markup' => SportsBotInlineKeyboardBuilder::liveReplyMarkup(),
                'card' => null,
            ], $messageThreadId);
            return 'live.sport_placeholder';
        }

        if ($action === 'top_teams') {
            $this->deliverRich($chatId, $messageId, [
                'caption' => '⭐ <b>Top Teams</b>' . "\n\nTop teams for " . e(SportsBotSports::label($id)) . " will use league standings as data becomes available.\n\nUse Tables for current standings.",
                'reply_markup' => SportsBotInlineKeyboardBuilder::tvReplyMarkup(),
                'card' => null,
            ], $messageThreadId);
            return 'top_teams.placeholder';
        }

        return null;
    }

    private function sendTvGuideResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->tvGuideModule->buildSummary();
        $message = $this->tvGuideModule->format($summary);
        $options = $this->tvGuideModule->telegramOptions($summary);
        $replyMarkup = (array) ($options['reply_markup'] ?? SportsBotInlineKeyboardBuilder::tvReplyMarkup());

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    private function sendLiveNowResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->liveNowModule->buildSummary();
        $message = $this->liveNowModule->format($summary);
        $options = $this->liveNowModule->telegramOptions($summary);
        $replyMarkup = (array) ($options['reply_markup'] ?? SportsBotInlineKeyboardBuilder::liveReplyMarkup());

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    /**
     * @param array<string, mixed> $replyMarkup
     */
    private function deliverText(string $chatId, mixed $messageId, string $text, array $replyMarkup, mixed $messageThreadId = null): void
    {
        if ($messageId !== null) {
            if ($this->editMessageText($chatId, $messageId, $text, $replyMarkup, $messageThreadId)) {
                return;
            }

            if ($this->notifier->editMessageCaption($chatId, $messageId, mb_substr($text, 0, 1000), $replyMarkup)) {
                return;
            }
        }

        $this->sendMessageWithKeyboard($chatId, $text, $replyMarkup, $messageThreadId);
    }

    /**
     * @param array{caption:string,reply_markup:array<string,mixed>,card:?array} $payload
     */
    private function deliverRich(string $chatId, mixed $messageId, array $payload, mixed $messageThreadId = null): void
    {
        $caption = (string) ($payload['caption'] ?? '');
        $replyMarkup = (array) ($payload['reply_markup'] ?? SportsBotInlineKeyboardBuilder::tvReplyMarkup());
        $card = is_array($payload['card'] ?? null) ? $payload['card'] : null;

        if ($messageId !== null && $card !== null && !empty($card['path'])) {
            if ($this->notifier->editMessageMedia($chatId, $messageId, (string) $card['path'], $caption, $replyMarkup)) {
                return;
            }
        }

        if ($messageId !== null) {
            if ($this->notifier->editMessageCaption($chatId, $messageId, $caption, $replyMarkup)) {
                return;
            }

            if ($this->notifier->editMessageText($chatId, $messageId, $caption, $replyMarkup)) {
                return;
            }
        }

        $this->sendMessageWithKeyboard($chatId, $caption, $replyMarkup, $messageThreadId);
    }

    private function claimCallback(string $callbackData, string $userKey): bool
    {
        $seconds = max(0, (int) config('plugins.SportsBot.features.callback_throttle_seconds', 2));
        if ($seconds <= 0) {
            return true;
        }

        return Cache::add('sportsbot:callback:' . sha1($userKey . '|' . $callbackData), true, now()->addSeconds($seconds));
    }

    private function callbackAction(string $callbackData): string
    {
        return explode(':', $callbackData, 2)[0] ?: $callbackData;
    }

    private function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
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
    private function editMessageText(string $chatId, mixed $messageId, string $text, array $replyMarkup, mixed $messageThreadId = null): bool
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        if ($token === '') {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => (int) $messageId,
            'text' => $text,
            'reply_markup' => json_encode($replyMarkup),
        ];

        $parseMode = (string) config('plugins.SportsBot.telegram.parse_mode', 'HTML');
        if (trim($parseMode) !== '') {
            $payload['parse_mode'] = $parseMode;
        }

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

            return $ok;
        } catch (Throwable $error) {
            Log::error('sportsbot.telegram.edit_message_error', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $replyMarkup
     */
    private function sendMessageWithKeyboard(string $chatId, string $text, array $replyMarkup, mixed $messageThreadId = null): void
    {
        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        if ($token === '') {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($replyMarkup),
        ];

        $parseMode = (string) config('plugins.SportsBot.telegram.parse_mode', 'HTML');
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
