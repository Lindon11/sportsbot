<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\Content\FixturesTodayContentModule;
use App\Plugins\SportsBot\Services\Content\LiveNowContentModule;
use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramCallbackService
{
    private const VALID_CALLBACK_DATA = [
        'fixtures_today',
        'fixtures_football',
        'fixtures_basketball',
        'fixtures_tennis',
        'fixtures_mma',
        'fixtures_cricket',
        'fixtures_formula_1',
        'tv_guide',
        'live_now',
        'my_teams',
        'tables',
        'news',
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
        private readonly SportsBotRichContentService $richContent = new SportsBotRichContentService(),
        private readonly SportsBotFollowService $followService = new SportsBotFollowService(),
    ) {
    }

    public function isValidCallbackData(string $data): bool
    {
        if (in_array($data, self::VALID_CALLBACK_DATA, true)) {
            return true;
        }

        return preg_match('/^(match|stats|lineups|highlights|tv|team|follow_team|unfollow_team|follow_league|unfollow_league|table|scorers|team_next|team_prev|fixtures|live|tv_more):[A-Za-z0-9_.-]+(?::[A-Za-z0-9_.-]+)?$/', $data) === 1;
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

        if (!$this->claimCallback($callbackData, (string) ($telegramUser['id'] ?? $chatId))) {
            $this->answerCallbackQuery($callbackQueryId, 'One moment…');
            return;
        }

        $this->answerCallbackQuery($callbackQueryId);

        try {
            $this->processCallback($callbackData, $chatId, $messageId, $messageThreadId, $telegramUser);
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

    private function processCallback(string $callbackData, string $chatId, mixed $messageId, mixed $messageThreadId, array $telegramUser = []): void
    {
        if ($this->processRichCallback($callbackData, $chatId, $messageId, $telegramUser)) {
            return;
        }

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

            case 'fixtures_tennis':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Tennis');
                break;

            case 'fixtures_mma':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Fighting');
                break;

            case 'fixtures_cricket':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Cricket');
                break;

            case 'fixtures_formula_1':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Motorsport');
                break;

            case 'tv_guide':
                $this->sendTvGuideResponse($chatId, $messageId, $messageThreadId);
                break;

            case 'live_now':
                $this->sendLiveNowResponse($chatId, $messageId, $messageThreadId);
                break;

            case 'my_teams':
                $this->sendMyTeamsResponse($chatId, $messageId, $messageThreadId, (string) ($telegramUser['id'] ?? ''));
                break;

            case 'tables':
                $this->sendLeagueTablesResponse($chatId, $messageId, $messageThreadId);
                break;

            case 'news':
                $this->deliverRich($chatId, $messageId, $this->richContent->newsPlaceholder());
                break;
        }
    }

    private function processRichCallback(string $callbackData, string $chatId, mixed $messageId, array $telegramUser): bool
    {
        $parts = explode(':', $callbackData);
        $action = $parts[0] ?? '';
        $id = $parts[1] ?? '';
        $page = max(1, (int) ($parts[2] ?? 1));

        if ($id === '') {
            return false;
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
            $this->deliverRich($chatId, $messageId, $payload);
            return true;
        }

        if ($action === 'follow_team') {
            $team = app(TheSportsDbClient::class)->lookupTeam($id) ?? ['idTeam' => $id, 'strTeam' => 'Team'];
            $this->followService->follow($telegramUser, 'team', $team, $chatId);
            $this->deliverRich($chatId, $messageId, [
                'caption' => '⭐ Following <b>' . e((string) ($team['strTeam'] ?? 'Team')) . "</b>\nAlerts enabled: goals, fixtures, TV, live and news.",
                'reply_markup' => SportsBotInlineKeyboardBuilder::teamReplyMarkup($id),
                'card' => null,
            ]);
            return true;
        }

        if ($action === 'unfollow_team') {
            $this->followService->unfollow((string) ($telegramUser['id'] ?? ''), 'team', $id);
            $this->deliverRich($chatId, $messageId, [
                'caption' => 'Removed that team from My Teams.',
                'reply_markup' => SportsBotInlineKeyboardBuilder::backReplyMarkup(),
                'card' => null,
            ]);
            return true;
        }

        if ($action === 'follow_league') {
            $league = app(TheSportsDbClient::class)->lookupLeague($id) ?? ['idLeague' => $id, 'strLeague' => 'League'];
            $this->followService->follow($telegramUser, 'league', $league, $chatId);
            $this->deliverRich($chatId, $messageId, [
                'caption' => '⭐ Following <b>' . e((string) ($league['strLeague'] ?? 'League')) . '</b>.',
                'reply_markup' => SportsBotInlineKeyboardBuilder::tableReplyMarkup($id),
                'card' => null,
            ]);
            return true;
        }

        return false;
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

    private function sendMyTeamsResponse(string $chatId, mixed $messageId, mixed $messageThreadId, string $telegramUserId = ''): void
    {
        $follows = $telegramUserId !== '' ? $this->followService->listForUser($telegramUserId) : [];
        $lines = ["⭐ <b>My Teams</b>", ''];

        if ($follows === []) {
            $lines[] = 'No followed teams or leagues yet.';
            $lines[] = 'Open a Team Page or League Table and tap Follow.';
        } else {
            foreach ($follows as $follow) {
                $lines[] = '• ' . e((string) $follow->name) . ' · ' . e((string) $follow->followable_type);
            }
        }

        $message = implode("\n", $lines);
        $replyMarkup = SportsBotInlineKeyboardBuilder::backReplyMarkup();

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    private function sendLeagueTablesResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $leagueIds = array_values(array_filter((array) config('plugins.SportsBot.fixtures_today.default_league_ids', [])));
        $keyboard = [];
        foreach (array_slice($leagueIds, 0, 8) as $leagueId) {
            $keyboard[] = [['text' => 'League ' . $leagueId, 'callback_data' => 'table:' . $leagueId . ':1']];
        }
        $keyboard[] = [['text' => '⬅ Back', 'callback_data' => 'back_main']];
        $message = "🏆 <b>League Tables</b>\n\nPick a featured league.";
        $replyMarkup = SportsBotInlineKeyboardBuilder::inlineKeyboardMarkup($keyboard);

        if ($messageId !== null) {
            $this->editMessageText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
        } else {
            $this->sendMessageWithKeyboard($chatId, $message, $replyMarkup, $messageThreadId);
        }
    }

    /**
     * @param array{caption:string,reply_markup:array<string,mixed>,card:?array} $payload
     */
    private function deliverRich(string $chatId, mixed $messageId, array $payload): void
    {
        $caption = (string) ($payload['caption'] ?? '');
        $replyMarkup = (array) ($payload['reply_markup'] ?? SportsBotInlineKeyboardBuilder::backReplyMarkup());
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

        $this->sendMessageWithKeyboard($chatId, $caption, $replyMarkup);
    }

    private function claimCallback(string $callbackData, string $userKey): bool
    {
        $seconds = max(0, (int) config('plugins.SportsBot.features.callback_throttle_seconds', 2));
        if ($seconds <= 0) {
            return true;
        }

        return Cache::add('sportsbot:callback:' . sha1($userKey . '|' . $callbackData), true, now()->addSeconds($seconds));
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
