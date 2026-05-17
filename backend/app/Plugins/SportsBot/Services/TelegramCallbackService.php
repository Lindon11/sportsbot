<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\Content\FixturesTodayContentModule;
use App\Plugins\SportsBot\Services\Content\LiveNowContentModule;
use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use App\Plugins\SportsBot\Support\SportsBotSports;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramCallbackService
{
    private const VALID_CALLBACK_DATA = [
        'fixtures_today',
        'football',
        'basketball',
        'tennis',
        'mma',
        'cricket',
        'f1',
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
        'league_table',
        'news',
        'match_stats',
        'match_lineups',
        'match_highlights',
        'match_tv',
        'top_teams',
        'add_team',
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

        return preg_match('/^(match|stats|lineups|highlights|tv|match_stats|match_lineups|match_highlights|match_tv|league_table|team|follow_team|unfollow_team|follow_league|unfollow_league|table|scorers|team_next|team_prev|fixtures|live|tv_more|top_teams):[A-Za-z0-9_.-]+(?::[A-Za-z0-9_.-]+)?$/', $data) === 1;
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
        $richHandler = $this->processRichCallback($callbackData, $chatId, $messageId, $messageThreadId, $telegramUser);
        if ($richHandler !== null) {
            return $richHandler;
        }

        switch ($callbackData) {
            case 'football':
                $this->sendSportMenu($chatId, $messageId, $messageThreadId, 'football');
                return 'sport_menu.football';

            case 'basketball':
                $this->sendSportMenu($chatId, $messageId, $messageThreadId, 'basketball');
                return 'sport_menu.basketball';

            case 'tennis':
                $this->sendSportMenu($chatId, $messageId, $messageThreadId, 'tennis');
                return 'sport_menu.tennis';

            case 'mma':
                $this->sendSportMenu($chatId, $messageId, $messageThreadId, 'mma');
                return 'sport_menu.mma';

            case 'cricket':
                $this->sendSportMenu($chatId, $messageId, $messageThreadId, 'cricket');
                return 'sport_menu.cricket';

            case 'f1':
                $this->sendSportMenu($chatId, $messageId, $messageThreadId, 'formula_1');
                return 'sport_menu.f1';

            case 'fixtures_today':
                $this->sendFixturesTodayResponse($chatId, $messageId, $messageThreadId);
                return 'fixtures_today';

            case 'back_main':
                $this->sendMainMenuResponse($chatId, $messageId, $messageThreadId);
                return 'main_menu';

            case 'fixtures_football':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Football');
                return 'fixtures.football';

            case 'fixtures_basketball':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Basketball');
                return 'fixtures.basketball';

            case 'fixtures_tennis':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Tennis');
                return 'fixtures.tennis';

            case 'fixtures_mma':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Fighting');
                return 'fixtures.mma';

            case 'fixtures_cricket':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Cricket');
                return 'fixtures.cricket';

            case 'fixtures_formula_1':
                $this->sendSportFilteredFixtures($chatId, $messageId, $messageThreadId, 'Motorsport');
                return 'fixtures.f1';

            case 'tv_guide':
                $this->sendTvGuideResponse($chatId, $messageId, $messageThreadId);
                return 'tv_guide';

            case 'live_now':
                $this->sendLiveNowResponse($chatId, $messageId, $messageThreadId);
                return 'live_now';

            case 'my_teams':
                $this->sendMyTeamsResponse($chatId, $messageId, $messageThreadId, (string) ($telegramUser['id'] ?? ''));
                return 'my_teams';

            case 'tables':
            case 'league_table':
                $this->sendLeagueTablesResponse($chatId, $messageId, $messageThreadId);
                return 'league_tables';

            case 'news':
                $this->deliverRich($chatId, $messageId, $this->richContent->newsPlaceholder(), $messageThreadId);
                return 'news';

            case 'match_stats':
                $this->sendPlaceholder($chatId, $messageId, $messageThreadId, '📊 Match Stats', 'Open a match card first, then tap Match Stats to load event-specific stats.', SportsBotInlineKeyboardBuilder::backReplyMarkup());
                return 'match_stats.placeholder';

            case 'match_lineups':
                $this->sendPlaceholder($chatId, $messageId, $messageThreadId, '👥 Lineups', 'Open a match card first, then tap Lineups to load confirmed teams.', SportsBotInlineKeyboardBuilder::backReplyMarkup());
                return 'match_lineups.placeholder';

            case 'match_highlights':
                $this->sendPlaceholder($chatId, $messageId, $messageThreadId, '▶ Highlights', 'Highlights appear here when TheSportsDB has video data for the event.', SportsBotInlineKeyboardBuilder::backReplyMarkup());
                return 'match_highlights.placeholder';

            case 'match_tv':
                $this->sendTvGuideResponse($chatId, $messageId, $messageThreadId);
                return 'match_tv.tv_guide';

            case 'top_teams':
                $this->sendPlaceholder($chatId, $messageId, $messageThreadId, '⭐ Top Teams', 'Team rankings are coming soon. Use Tables for the current league standings.', SportsBotInlineKeyboardBuilder::backReplyMarkup());
                return 'top_teams.placeholder';

            case 'add_team':
                $this->sendPlaceholder($chatId, $messageId, $messageThreadId, '➕ Add Team', 'Team search/follow is ready for team pages. Search-driven add flow is coming next.', SportsBotInlineKeyboardBuilder::myTeamsReplyMarkup());
                return 'add_team.placeholder';
        }

        $this->sendMainMenuResponse($chatId, $messageId, $messageThreadId);
        return 'fallback.main_menu';
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
            'match_stats' => $this->richContent->stats($id),
            'lineups' => $this->richContent->lineups($id),
            'match_lineups' => $this->richContent->lineups($id),
            'highlights' => $this->richContent->highlights($id),
            'match_highlights' => $this->richContent->highlights($id),
            'tv' => $this->richContent->eventTv($id),
            'match_tv' => $this->richContent->eventTv($id),
            'table' => $this->richContent->leagueTable($id, $page),
            'league_table' => $this->richContent->leagueTable($id, $page),
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
                'reply_markup' => SportsBotInlineKeyboardBuilder::backReplyMarkup(),
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
                'reply_markup' => SportsBotInlineKeyboardBuilder::backReplyMarkup(),
                'card' => null,
            ], $messageThreadId);
            return 'live.sport_placeholder';
        }

        if ($action === 'top_teams') {
            $this->deliverRich($chatId, $messageId, [
                'caption' => '⭐ <b>Top Teams</b>' . "\n\nTop teams for " . e(SportsBotSports::label($id)) . " will use league standings as data becomes available.\n\nUse Tables for current standings.",
                'reply_markup' => SportsBotInlineKeyboardBuilder::backReplyMarkup(),
                'card' => null,
            ], $messageThreadId);
            return 'top_teams.placeholder';
        }

        return null;
    }

    private function sendFixturesTodayResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->fixturesModule->buildSummary();
        $message = $this->fixturesModule->format($summary);
        $options = $this->fixturesModule->telegramOptions($summary);
        $replyMarkup = (array) ($options['reply_markup'] ?? SportsBotInlineKeyboardBuilder::fixturesTodayReplyMarkup());

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    private function sendMainMenuResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $message = "🏟 <b>SportsBot</b>\n\nChoose a sport or section:";
        $this->deliverText($chatId, $messageId, $message, SportsBotInlineKeyboardBuilder::mainReplyMarkup(), $messageThreadId);
    }

    private function sendSportMenu(string $chatId, mixed $messageId, mixed $messageThreadId, string $sportKey): void
    {
        $label = SportsBotSports::label($sportKey);
        $icon = SportsBotSports::icon($sportKey);

        $message = implode("\n", [
            $icon . ' <b>' . e($label) . '</b>',
            '',
            'Choose an option:',
        ]);

        $this->deliverText(
            $chatId,
            $messageId,
            $message,
            SportsBotInlineKeyboardBuilder::sportMenuReplyMarkup($sportKey),
            $messageThreadId
        );
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

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    private function sendTvGuideResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->tvGuideModule->buildSummary();
        $message = $this->tvGuideModule->format($summary);
        $options = $this->tvGuideModule->telegramOptions($summary);
        $replyMarkup = (array) ($options['reply_markup'] ?? SportsBotInlineKeyboardBuilder::backReplyMarkup());

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    private function sendLiveNowResponse(string $chatId, mixed $messageId, mixed $messageThreadId): void
    {
        $summary = $this->liveNowModule->buildSummary();
        $message = $this->liveNowModule->format($summary);
        $options = $this->liveNowModule->telegramOptions($summary);
        $replyMarkup = (array) ($options['reply_markup'] ?? SportsBotInlineKeyboardBuilder::backReplyMarkup());

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    private function sendMyTeamsResponse(string $chatId, mixed $messageId, mixed $messageThreadId, string $telegramUserId = ''): void
    {
        $follows = $telegramUserId !== '' ? $this->followService->listForUser($telegramUserId) : [];
        $lines = ["⭐ <b>My Teams</b>", ''];

        if ($follows === []) {
            $lines[] = 'No followed teams yet.';
            $lines[] = 'Tap Add Team when team search is enabled, or follow from a Team Page.';
        } else {
            foreach ($follows as $follow) {
                $lines[] = '• ' . e((string) $follow->name) . ' · ' . e((string) $follow->followable_type);
            }
        }

        $message = implode("\n", $lines);
        $replyMarkup = SportsBotInlineKeyboardBuilder::myTeamsReplyMarkup();

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
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

        $this->deliverText($chatId, $messageId, $message, $replyMarkup, $messageThreadId);
    }

    private function sendPlaceholder(string $chatId, mixed $messageId, mixed $messageThreadId, string $title, string $body, array $replyMarkup): void
    {
        $this->deliverText($chatId, $messageId, '<b>' . e($title) . "</b>\n\n" . e($body), $replyMarkup, $messageThreadId);
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
    private function editMessageText(string $chatId, mixed $messageId, string $text, array $replyMarkup, mixed $messageThreadId = null): bool
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
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
