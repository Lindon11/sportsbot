<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotMatchState;
use App\Plugins\SportsBot\Models\SportsBotRun;
use App\Plugins\SportsBot\Models\SportsBotSentAlert;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Models\SportsBotTelegramRoute;
use App\Plugins\SportsBot\Models\SportsBotTelegramTopic;
use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\Content\FixturesTodayContentModule;
use App\Plugins\SportsBot\Services\Content\FightFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\FootballFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\LiveNowContentModule;
use App\Plugins\SportsBot\Services\Content\RugbyFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use App\Plugins\SportsBot\Services\SportsBotRunner;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Services\FixtureQueueService;
use App\Plugins\SportsBot\Services\SportsFixturePublisher;
use App\Plugins\SportsBot\Services\TelegramNotifier;
use App\Plugins\SportsBot\Services\TelegramRoutingService;
use App\Plugins\SportsBot\Services\TelegramTopicDiscoveryService;
use App\Plugins\SportsBot\Support\SportsBotSports;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SportsBotController extends Controller
{
    public function status(SportsBotRunner $runner, TelegramRoutingService $routingService): JsonResponse
    {
        $fixturesRouteStatus = $routingService->resolveTargets(TelegramRouteKeys::FIXTURES_TODAY);
        $latestRun = SportsBotRun::latest('id')->first();

        return response()->json([
            'health' => $runner->health(),
            'latest_run' => $latestRun,
            'route_status' => [
                'route_key' => TelegramRouteKeys::FIXTURES_TODAY,
                'resolved_route_key' => $fixturesRouteStatus['resolved_route_key'] ?? TelegramRouteKeys::DEFAULT,
                'fallback' => (bool) ($fixturesRouteStatus['fallback'] ?? false),
                'target_count' => (int) ($fixturesRouteStatus['target_count'] ?? 0),
                'targets' => $fixturesRouteStatus['targets'] ?? [],
                'source' => $fixturesRouteStatus['source'] ?? null,
            ],
            'route_statuses' => $this->routeStatuses($routingService),
            'counts' => [
                'runs' => SportsBotRun::count(),
                'tracked_matches' => SportsBotMatchState::count(),
                'sent_alerts' => SportsBotSentAlert::count(),
            ],
            'recent_runs' => SportsBotRun::latest('id')->limit(10)->get(),
            'recent_alerts' => SportsBotSentAlert::latest('id')->limit(10)->get(),
            'recent_telegram_messages' => $this->recentTelegramMessages(TelegramRouteKeys::FIXTURES_TODAY),
            'recent_telegram_topics' => $this->recentTelegramTopics(),
        ]);
    }

    public function run(Request $request, SportsBotRunner $runner): JsonResponse
    {
        $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'send' => ['sometimes', 'boolean'],
        ]);

        $send = $request->boolean('send', false);
        $dryRun = $send ? false : $request->boolean('dry_run', true);
        $summary = $runner->run($dryRun, $send ? true : null);

        return response()->json([
            'summary' => $summary,
        ]);
    }

    public function testRoute(Request $request, TelegramRoutingService $routingService, TelegramNotifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['sometimes', 'string', 'max:100'],
            'send' => ['sometimes', 'boolean'],
        ]);

        $routeKey = TelegramRouteKeys::normalize((string) ($validated['route_key'] ?? TelegramRouteKeys::FIXTURES_TODAY));
        $send = (bool) ($validated['send'] ?? false);
        $resolved = $routingService->resolveTargets($routeKey);

        $response = [
            'route_key' => $routeKey,
            'resolved' => $resolved,
        ];

        if ($send) {
            $message = implode("\n", [
                'SportsBot route test',
                'Route: ' . $routeKey,
                'Resolved: ' . (string) ($resolved['resolved_route_key'] ?? TelegramRouteKeys::DEFAULT),
                'Time: ' . now()->toDateTimeString(),
            ]);

            try {
                $results = $notifier->send($message, [
                    'route_key' => $routeKey,
                    'type' => 'ROUTE_TEST',
                    'payload' => [
                        'source' => 'admin_api',
                    ],
                ]);
            } catch (Throwable $error) {
                Log::error('sportsbot.admin.test_route_failed', [
                    'route_key' => $routeKey,
                    'error' => $error->getMessage(),
                ]);

                return response()->json([
                    'route_key' => $routeKey,
                    'resolved' => $resolved,
                    'sent' => false,
                    'error' => $error->getMessage(),
                ], 422);
            }

            $response['sent'] = true;
            $response['results'] = $results;
        }

        return response()->json($response);
    }

    public function fixturesTodayPreview(FixturesTodayContentModule $module, SportsBotPublisher $publisher): JsonResponse
    {
        return response()->json($publisher->preview($module));
    }

    public function fixturesTodaySend(FixturesTodayContentModule $module, SportsBotPublisher $publisher): JsonResponse
    {
        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.fixtures_today_send_failed', [
                'route_key' => TelegramRouteKeys::FIXTURES_TODAY,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::FIXTURES_TODAY,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function footballFixturesPreview(
        Request $request,
        FootballFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('football_fixture_card_version', 'v1'));

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'captions_enabled' => (bool) $settings->get('football_fixture_captions_enabled', false),
            'card_version' => $cardVersion,
        ]));
    }

    public function footballFixturesSend(
        Request $request,
        FootballFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'captions_enabled' => ['sometimes', 'boolean'],
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);

        if (array_key_exists('captions_enabled', $validated)) {
            $settings->set('football_fixture_captions_enabled', (bool) $validated['captions_enabled']);
        }
        if (array_key_exists('card_version', $validated)) {
            $settings->set('football_fixture_card_version', $this->footballFixtureCardVersion($validated['card_version']));
        }

        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.football_fixtures_send_failed', [
                'route_key' => TelegramRouteKeys::FOOTBALL,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::FOOTBALL,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function rugbyFixturesPreview(
        Request $request,
        RugbyFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('rugby_fixture_card_version', 'v2'));

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'captions_enabled' => (bool) $settings->get('rugby_fixture_captions_enabled', false),
            'card_version' => $cardVersion,
        ]));
    }

    public function rugbyFixturesSend(
        Request $request,
        RugbyFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'captions_enabled' => ['sometimes', 'boolean'],
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);

        if (array_key_exists('captions_enabled', $validated)) {
            $settings->set('rugby_fixture_captions_enabled', (bool) $validated['captions_enabled']);
        }
        if (array_key_exists('card_version', $validated)) {
            $settings->set('rugby_fixture_card_version', $this->footballFixtureCardVersion($validated['card_version']));
        }

        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.rugby_fixtures_send_failed', [
                'route_key' => TelegramRouteKeys::RUGBY,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::RUGBY,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function fightFixturesPreview(
        Request $request,
        FightFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('fight_fixture_card_version', 'v2'));

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'captions_enabled' => (bool) $settings->get('fight_fixture_captions_enabled', false),
            'card_version' => $cardVersion,
        ]));
    }

    public function fightFixturesSend(
        Request $request,
        FightFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'captions_enabled' => ['sometimes', 'boolean'],
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);

        if (array_key_exists('captions_enabled', $validated)) {
            $settings->set('fight_fixture_captions_enabled', (bool) $validated['captions_enabled']);
        }
        if (array_key_exists('card_version', $validated)) {
            $settings->set('fight_fixture_card_version', $this->footballFixtureCardVersion($validated['card_version']));
        }

        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.fight_fixtures_send_failed', [
                'route_key' => TelegramRouteKeys::FIGHTS,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::FIGHTS,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function sportFixturePreview(
        string $sport,
        Request $request,
        SportsBotPublisher $publisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
    ): JsonResponse {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);

        $config = SportsFixtureConfig::for($sport);
        if ($config === null) {
            return response()->json(['error' => "Unknown sport: {$sport}"], 422);
        }

        $contentModule = $this->resolveContentModule($sport);
        if ($contentModule === null) {
            return response()->json(['error' => "No content module for sport: {$sport}"], 422);
        }

        $preview = $publisher->preview($contentModule);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get(
            $this->settingKey($sport, 'card_version'),
            $config['default_card_version'] ?? 'v1'
        ));

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'captions_enabled' => (bool) $settings->get($this->settingKey($sport, 'captions_enabled'), $config['captions_enabled_default'] ?? false),
            'card_version' => $cardVersion,
            'sport_config' => $config,
        ]));
    }

    public function sportFixtureSend(
        string $sport,
        Request $request,
        SportsBotPublisher $publisher,
        SportsBotSettingsService $settings,
    ): JsonResponse {
        $config = SportsFixtureConfig::for($sport);
        if ($config === null) {
            return response()->json(['error' => "Unknown sport: {$sport}"], 422);
        }

        $validated = $request->validate([
            'captions_enabled' => ['sometimes', 'boolean'],
            'card_version' => ['sometimes', 'string', 'in:v1,v2'],
        ]);

        if (array_key_exists('captions_enabled', $validated)) {
            $settings->set($this->settingKey($sport, 'captions_enabled'), (bool) $validated['captions_enabled']);
        }
        if (array_key_exists('card_version', $validated)) {
            $settings->set($this->settingKey($sport, 'card_version'), $this->footballFixtureCardVersion($validated['card_version']));
        }

        $contentModule = $this->resolveContentModule($sport);
        if ($contentModule === null) {
            return response()->json(['error' => "No content module for sport: {$sport}"], 422);
        }

        try {
            return response()->json($publisher->send($contentModule, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.sport_fixture_send_failed', [
                'sport' => $sport,
                'route_key' => $config['topic_key'] ?? null,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'sport' => $sport,
                'route_key' => $config['topic_key'] ?? null,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function sportFixturePublish(
        string $sport,
        Request $request,
        SportsFixturePublisher $fixturePublisher,
    ): JsonResponse {
        $config = SportsFixtureConfig::for($sport);
        if ($config === null) {
            return response()->json(['error' => "Unknown sport: {$sport}"], 422);
        }

        try {
            return response()->json($fixturePublisher->send($sport, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.sport_fixture_publish_failed', [
                'sport' => $sport,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'sport' => $sport,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function fixtureQueue(FixtureQueueService $queue): JsonResponse
    {
        $today = Carbon::today()->toDateString();

        return response()->json([
            'sports' => SportsFixtureConfig::all(),
            'queue_counts' => [
                'total' => SportsBotFixtureQueue::count(),
                'draft' => SportsBotFixtureQueue::where('status', SportsBotFixtureQueue::STATUS_DRAFT)->count(),
                'ready' => SportsBotFixtureQueue::where('status', SportsBotFixtureQueue::STATUS_READY)->count(),
                'sent' => SportsBotFixtureQueue::where('status', SportsBotFixtureQueue::STATUS_SENT)->count(),
                'failed' => SportsBotFixtureQueue::where('status', SportsBotFixtureQueue::STATUS_FAILED)->count(),
            ],
            'publish_today' => SportsBotFixtureQueue::query()
                ->where('publish_date', $today)
                ->ready()
                ->count(),
            'recent_items' => SportsBotFixtureQueue::query()
                ->latest('updated_at')
                ->limit(20)
                ->get()
                ->toArray(),
        ]);
    }

    public function fixtureQueuePrefetch(FixtureQueueService $queue): JsonResponse
    {
        try {
            return response()->json($queue->prefetchAll());
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }
    }

    public function fixtureQueueRender(FixtureQueueService $queue): JsonResponse
    {
        try {
            return response()->json($queue->renderAll());
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }
    }

    public function fixtureQueuePublish(FixtureQueueService $queue): JsonResponse
    {
        try {
            return response()->json($queue->publishAll());
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }
    }

    private function resolveContentModule(string $sport): ?object
    {
        return match (SportsBotSports::normalize($sport)) {
            'football' => app(\App\Plugins\SportsBot\Services\Content\FootballFixturesContentModule::class),
            'rugby' => app(\App\Plugins\SportsBot\Services\Content\RugbyFixturesContentModule::class),
            'fights', 'mma', 'boxing' => app(\App\Plugins\SportsBot\Services\Content\FightFixturesContentModule::class),
            default => null,
        };
    }

    private function settingKey(string $sport, string $key): string
    {
        $normalized = SportsBotSports::normalize($sport);

        return match ($key) {
            'captions_enabled' => $normalized . '_fixture_captions_enabled',
            'card_version' => $normalized . '_fixture_card_version',
            default => $normalized . '_fixture_' . $key,
        };
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function fixtureCardPreviews(array $summary, SportsBotCardRenderer $cards, string $cardVersion = 'v1'): array
    {
        $previews = [];
        $cardVersion = $this->footballFixtureCardVersion($cardVersion);

        foreach ((array) ($summary['grouped'] ?? []) as $fixtures) {
            foreach ((array) $fixtures as $fixture) {
                if (!is_array($fixture)) {
                    continue;
                }

                try {
                    $card = $cards->fixtureCard($fixture, $cardVersion);
                    $path = (string) ($card['path'] ?? '');
                    if ($path === '' || !is_file($path)) {
                        continue;
                    }

                    $previews[] = [
                        'event_id' => (string) ($fixture['event_id'] ?? ''),
                        'title' => $this->fixtureTitle($fixture),
                        'league' => (string) ($fixture['league'] ?? ''),
                        'time' => (string) ($fixture['kickoff_label'] ?? $fixture['time'] ?? ''),
                        'tv_channel' => (string) ($fixture['tv_channel'] ?? ''),
                        'card_version' => $cardVersion,
                        'data_url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)),
                    ];
                } catch (Throwable $error) {
                    Log::warning('sportsbot.admin.fixture_card_preview_failed', [
                        'event_id' => (string) ($fixture['event_id'] ?? ''),
                        'error' => $error->getMessage(),
                    ]);
                }

                if (count($previews) >= 3) {
                    return $previews;
                }
            }
        }

        return $previews;
    }

    private function footballFixtureCardVersion(mixed $version): string
    {
        return strtolower(trim((string) $version)) === 'v2' ? 'v2' : 'v1';
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function fixtureTitle(array $fixture): string
    {
        $home = trim((string) ($fixture['home_team'] ?? ''));
        $away = trim((string) ($fixture['away_team'] ?? ''));

        if ($home !== '' && $away !== '') {
            return $home . ' vs ' . $away;
        }

        return trim((string) ($fixture['event_name'] ?? 'Fixture TBC')) ?: 'Fixture TBC';
    }

    public function tvGuidePreview(TvGuideContentModule $module, SportsBotPublisher $publisher): JsonResponse
    {
        return response()->json($publisher->preview($module));
    }

    public function tvGuideSend(TvGuideContentModule $module, SportsBotPublisher $publisher): JsonResponse
    {
        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.tv_guide_send_failed', [
                'route_key' => TelegramRouteKeys::TV_GUIDE,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::TV_GUIDE,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function liveNowPreview(LiveNowContentModule $module, SportsBotPublisher $publisher): JsonResponse
    {
        return response()->json($publisher->preview($module));
    }

    public function liveNowSend(LiveNowContentModule $module, SportsBotPublisher $publisher): JsonResponse
    {
        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.live_now_send_failed', [
                'route_key' => TelegramRouteKeys::LIVE_NOW,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::LIVE_NOW,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function coverageSettings(SportsBotSettingsService $settings, TelegramRoutingService $routingService): JsonResponse
    {
        $cardDir = storage_path('app/sportsbot/cards');
        $cardFiles = is_dir($cardDir) ? glob($cardDir . '/*.png') ?: [] : [];

        return response()->json([
            'sports' => SportsBotSports::all(),
            'settings' => array_merge([
                'enabled_sports' => config('plugins.SportsBot.coverage.enabled_sports', []),
                'featured_league_ids' => config('plugins.SportsBot.fixtures_today.default_league_ids', []),
                'tv_channels' => config('plugins.SportsBot.tv.channels', []),
                'live_update_frequency' => config('plugins.SportsBot.publishing.live_now.frequency', 'everyFiveMinutes'),
                'cards_enabled' => (bool) config('plugins.SportsBot.cards.enabled', true),
                'rich_cards_enabled' => (bool) config('plugins.SportsBot.features.rich_cards', true),
                'send_messages' => (bool) config('plugins.SportsBot.send_messages', false),
            ], $settings->all()),
            'route_statuses' => $this->routeStatuses($routingService),
            'card_generation' => [
                'gd_loaded' => extension_loaded('gd'),
                'directory' => $cardDir,
                'recent_cards' => count($cardFiles),
                'last_card_at' => $cardFiles !== [] ? date('c', max(array_map('filemtime', $cardFiles))) : null,
            ],
            'telegram_send_diagnostics' => [
                'configured' => app(TelegramNotifier::class)->configured(),
                'recent_messages' => $this->recentTelegramMessages(null, 10),
            ],
        ]);
    }

    public function saveCoverageSettings(Request $request, SportsBotSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'enabled_sports' => ['sometimes', 'array'],
            'enabled_sports.*' => ['string', 'max:80'],
            'featured_league_ids' => ['sometimes', 'array'],
            'featured_league_ids.*' => ['string', 'max:40'],
            'tv_channels' => ['sometimes', 'array'],
            'tv_channels.*' => ['string', 'max:120'],
            'live_update_frequency' => ['sometimes', 'string', 'max:80'],
            'cards_enabled' => ['sometimes', 'boolean'],
            'rich_cards_enabled' => ['sometimes', 'boolean'],
            'send_messages' => ['sometimes', 'boolean'],
        ]);

        foreach ($validated as $key => $value) {
            $settings->set($key, $value);
        }

        Log::info('sportsbot.admin.coverage_settings_saved', [
            'keys' => array_keys($validated),
        ]);

        return response()->json([
            'saved' => true,
            'settings' => $settings->all(),
        ]);
    }

    public function sendTelegramDiagnostics(Request $request, TelegramNotifier $notifier, SportsBotCardRenderer $cards): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['sometimes', 'string', 'max:100'],
            'media' => ['sometimes', 'boolean'],
        ]);

        $routeKey = TelegramRouteKeys::normalize((string) ($validated['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $caption = 'SportsBot rich media diagnostic · ' . now()->toDateTimeString();

        try {
            if ((bool) ($validated['media'] ?? true)) {
                $card = $cards->breakingNewsCard([
                    'title' => 'SportsBot Diagnostics',
                    'summary' => 'Rich card generation and Telegram sendPhoto are working.',
                    'source' => 'LaravelCP Admin',
                ]);
                $results = $notifier->sendPhoto((string) $card['path'], $caption, [
                    'route_key' => $routeKey,
                    'type' => 'SEND_DIAGNOSTIC',
                    'reply_markup' => \App\Plugins\SportsBot\Services\SportsBotInlineKeyboardBuilder::tvReplyMarkup(),
                ]);
            } else {
                $results = $notifier->send($caption, [
                    'route_key' => $routeKey,
                    'type' => 'SEND_DIAGNOSTIC',
                ]);
            }
        } catch (Throwable $error) {
            return response()->json([
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }

        return response()->json([
            'sent' => true,
            'results' => $results,
        ]);
    }

    public function telegramTopics(): JsonResponse
    {
        $service = app(TelegramTopicDiscoveryService::class);

        return response()->json([
            'topics' => $this->recentTelegramTopics(100),
            'routes' => $this->telegramRoutes(),
            'diagnostics' => $service->diagnostics(),
        ]);
    }

    public function telegramMessages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $routeKey = array_key_exists('route_key', $validated) && $validated['route_key'] !== null
            ? TelegramRouteKeys::normalize((string) $validated['route_key'])
            : null;

        return response()->json([
            'messages' => $this->recentTelegramMessages($routeKey, (int) ($validated['limit'] ?? 20)),
        ]);
    }

    public function telegramRoutesIndex(TelegramRoutingService $routingService): JsonResponse
    {
        return response()->json([
            'route_keys' => TelegramRouteKeys::all(),
            'routes' => $this->telegramRoutes(),
            'topics' => $this->recentTelegramTopics(100),
            'route_statuses' => $this->routeStatuses($routingService),
            'diagnostics' => app(TelegramTopicDiscoveryService::class)->diagnostics(),
        ]);
    }

    public function saveTelegramTopic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'chat_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'message_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'topic_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $chatId = trim((string) ($validated['chat_id'] ?? ''));
        $threadId = $validated['message_thread_id'] ?? null;
        $topicUrl = trim((string) ($validated['topic_url'] ?? ''));

        $target = $this->normalizeTelegramTarget($topicUrl !== '' ? $topicUrl : $chatId, $threadId);
        if ($target === null) {
            return response()->json([
                'saved' => false,
                'error' => 'Could not parse target. Use chat_id + thread_id, chat_id:thread_id, or https://t.me/c/1234567890/777.',
            ], 422);
        }

        $chatId = $target['chat_id'];
        $threadId = $target['message_thread_id'];

        $topic = SportsBotTelegramTopic::query()
            ->where('chat_id', $chatId)
            ->where('message_thread_id', (int) $threadId)
            ->first();

        if (!$topic instanceof SportsBotTelegramTopic) {
            $topic = new SportsBotTelegramTopic([
                'chat_id' => $chatId,
                'message_thread_id' => (int) $threadId,
                'first_seen_at' => now(),
            ]);
        }

        $topic->title = trim((string) ($validated['title'] ?? '')) ?: $topic->title;
        $topic->source = $topicUrl !== '' ? 'manual_topic_url' : 'manual_admin';
        $topic->last_seen_at = now();
        $topic->save();

        return response()->json([
            'saved' => true,
            'topic' => $topic,
            'topics' => $this->recentTelegramTopics(100),
        ]);
    }

    public function saveTelegramRoute(Request $request, TelegramRoutingService $routingService): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['required', 'string', 'max:100'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'chat_id' => ['required', 'string', 'max:255'],
            'message_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
            'fallback' => ['sometimes', 'boolean'],
        ]);

        $routeKey = TelegramRouteKeys::normalize((string) $validated['route_key']);

        if (!in_array($routeKey, TelegramRouteKeys::all(), true)) {
            return response()->json([
                'saved' => false,
                'error' => 'Unsupported SportsBot route key.',
            ], 422);
        }

        $target = $this->normalizeTelegramTarget(
            (string) $validated['chat_id'],
            $validated['message_thread_id'] ?? null,
            false
        );

        if ($target === null) {
            return response()->json([
                'saved' => false,
                'error' => 'Could not parse Telegram target. Use chat_id, chat_id:thread_id, or a Telegram topic URL.',
            ], 422);
        }

        $route = SportsBotTelegramRoute::query()->updateOrCreate(
            ['route_key' => $routeKey],
            [
                'label' => trim((string) ($validated['label'] ?? '')) ?: $routeKey,
                'chat_id' => $target['chat_id'],
                'message_thread_id' => $target['message_thread_id'],
                'enabled' => (bool) ($validated['enabled'] ?? true),
                'fallback' => (bool) ($validated['fallback'] ?? ($routeKey === TelegramRouteKeys::DEFAULT)),
            ]
        );

        Log::info('sportsbot.admin.telegram_route_saved', [
            'route_key' => $routeKey,
            'chat_id' => $route->chat_id,
            'message_thread_id' => $route->message_thread_id,
            'enabled' => $route->enabled,
            'fallback' => $route->fallback,
        ]);

        return response()->json([
            'saved' => true,
            'route' => $route,
            'routes' => $this->telegramRoutes(),
            'route_statuses' => $this->routeStatuses($routingService),
        ]);
    }

    public function deleteTelegramRoute(string $routeKey, TelegramRoutingService $routingService): JsonResponse
    {
        $normalized = TelegramRouteKeys::normalize($routeKey);
        SportsBotTelegramRoute::query()
            ->where('route_key', $normalized)
            ->delete();

        return response()->json([
            'deleted' => true,
            'routes' => $this->telegramRoutes(),
            'route_statuses' => $this->routeStatuses($routingService),
        ]);
    }

    public function syncTelegramTopics(Request $request, TelegramTopicDiscoveryService $service): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'timeout' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'reset_offset' => ['sometimes', 'boolean'],
        ]);

        try {
            $summary = $service->sync(
                (int) ($validated['limit'] ?? 100),
                (int) ($validated['timeout'] ?? 0),
                (bool) ($validated['reset_offset'] ?? false)
            );
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.telegram_topics_sync_failed', [
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'synced' => false,
                'error' => $error->getMessage(),
            ], 422);
        }

        return response()->json([
            'synced' => true,
            'summary' => $summary,
            'topics' => $this->recentTelegramTopics(100),
            'diagnostics' => $service->diagnostics(),
        ]);
    }

    public function telegramWebhookDiagnostics(): JsonResponse
    {
        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        $webhookEnabled = (bool) config('plugins.SportsBot.telegram.webhook_enabled', false);
        $webhookUrl = url(route('sportsbot.telegram.webhook', [], false));
        $lastUpdate = null;
        $lastCallback = null;
        $recentUpdates = [];
        $recentCallbacks = [];

        if (Schema::hasTable('sportsbot_telegram_update_states')) {
            $lastUpdate = SportsBotTelegramUpdateState::query()->latest()->first();
            $lastCallback = SportsBotTelegramUpdateState::query()
                ->where('type', 'callback_query')
                ->latest()
                ->first();
            $recentUpdates = SportsBotTelegramUpdateState::query()
                ->latest()
                ->take(20)
                ->get()
                ->toArray();
            $recentCallbacks = SportsBotTelegramUpdateState::query()
                ->where('type', 'callback_query')
                ->latest()
                ->take(20)
                ->get()
                ->toArray();
        }

        $lastCallbackData = null;
        $lastCallbackAction = null;
        $lastCallbackHandler = null;
        $lastCallbackError = null;
        if ($lastCallback instanceof SportsBotTelegramUpdateState) {
            $payload = is_array($lastCallback->payload) ? $lastCallback->payload : [];
            $lastCallbackData = $payload['callback_data'] ?? $lastCallback->callback_data;
            $lastCallbackAction = $payload['callback_action'] ?? null;
            $lastCallbackHandler = $payload['callback_handler'] ?? null;
            $lastCallbackError = $payload['callback_error'] ?? null;
        }

        $telegramHealth = null;
        if ($token !== '') {
            try {
                $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getWebhookInfo");
                $result = $response->json();
                if ($response->successful() && ($result['ok'] ?? false)) {
                    $info = $result['result'] ?? [];
                    $telegramHealth = [
                        'url' => $info['url'] ?? '',
                        'pending_update_count' => $info['pending_update_count'] ?? 0,
                        'last_error_date' => isset($info['last_error_date']) ? date('Y-m-d H:i:s', $info['last_error_date']) : null,
                        'last_error_message' => $info['last_error_message'] ?? null,
                        'max_connections' => $info['max_connections'] ?? null,
                        'healthy' => ($info['pending_update_count'] ?? 0) < 100 && empty($info['last_error_message']),
                    ];
                } else {
                    $telegramHealth = [
                        'error' => $result['description'] ?? 'Unknown API error',
                        'healthy' => false,
                    ];
                }
            } catch (ConnectionException $e) {
                $telegramHealth = [
                    'error' => 'Could not connect to Telegram API: ' . $e->getMessage(),
                    'healthy' => false,
                ];
            } catch (Throwable $e) {
                $telegramHealth = [
                    'error' => 'Unexpected error: ' . $e->getMessage(),
                    'healthy' => false,
                ];
            }
        }

        return response()->json([
            'webhook_enabled' => $webhookEnabled,
            'webhook_url' => $webhookUrl,
            'bot_token_configured' => $token !== '',
            'error' => $webhookEnabled ? null : 'Webhook is not enabled. Set SPORTSBOT_TELEGRAM_WEBHOOK_ENABLED=true',
            'last_webhook_received' => $lastUpdate instanceof SportsBotTelegramUpdateState
                ? $lastUpdate->created_at->toIso8601String()
                : null,
            'last_callback_received' => $lastCallback instanceof SportsBotTelegramUpdateState
                ? $lastCallback->created_at->toIso8601String()
                : null,
            'last_callback_data' => $lastCallbackData,
            'last_callback_action' => $lastCallbackAction,
            'last_callback_handler' => $lastCallbackHandler,
            'last_callback_error' => $lastCallbackError,
            'callback_data_audit' => \App\Plugins\SportsBot\Services\SportsBotInlineKeyboardBuilder::callbackDataAudit(),
            'telegram_webhook_health' => $telegramHealth,
            'recent_updates' => $recentUpdates,
            'recent_callbacks' => $recentCallbacks,
        ]);
    }

    public function setTelegramWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_connections' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'drop_pending_updates' => ['sometimes', 'boolean'],
        ]);

        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        if ($token === '') {
            return response()->json([
                'set' => false,
                'error' => 'Telegram bot token is not configured.',
            ], 422);
        }

        $url = trim((string) ($validated['url'] ?? ''));
        if ($url === '') {
            $url = url(route('sportsbot.telegram.webhook', [], false));
        }

        $payload = [
            'url' => $url,
            'max_connections' => (int) ($validated['max_connections'] ?? 40),
            'drop_pending_updates' => (bool) ($validated['drop_pending_updates'] ?? false),
        ];

        $secret = trim((string) config('plugins.SportsBot.telegram.webhook_secret', ''));
        if ($secret !== '') {
            $payload['secret_token'] = $secret;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/setWebhook", $payload);
            $result = $response->json();

            if ($response->successful() && ($result['ok'] ?? false)) {
                Log::info('sportsbot.admin.telegram_webhook_set', [
                    'url' => $url,
                    'max_connections' => $payload['max_connections'],
                    'drop_pending_updates' => $payload['drop_pending_updates'],
                ]);

                return response()->json([
                    'set' => true,
                    'result' => $result,
                    'diagnostics' => $this->telegramWebhookDiagnostics()->getData(true),
                ]);
            }

            Log::error('sportsbot.admin.telegram_webhook_set_failed', [
                'response' => $result,
            ]);

            return response()->json([
                'set' => false,
                'error' => $result['description'] ?? 'Failed to set Telegram webhook.',
                'response' => $result,
            ], 422);
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.telegram_webhook_set_error', [
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'set' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function deleteTelegramWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'drop_pending_updates' => ['sometimes', 'boolean'],
        ]);

        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        if ($token === '') {
            return response()->json([
                'deleted' => false,
                'error' => 'Telegram bot token is not configured.',
            ], 422);
        }

        $payload = [
            'drop_pending_updates' => (bool) ($validated['drop_pending_updates'] ?? false),
        ];

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/deleteWebhook", $payload);
            $result = $response->json();

            if ($response->successful() && ($result['ok'] ?? false)) {
                Log::info('sportsbot.admin.telegram_webhook_deleted', [
                    'drop_pending_updates' => $payload['drop_pending_updates'],
                ]);

                return response()->json([
                    'deleted' => true,
                    'result' => $result,
                    'diagnostics' => $this->telegramWebhookDiagnostics()->getData(true),
                ]);
            }

            Log::error('sportsbot.admin.telegram_webhook_delete_failed', [
                'response' => $result,
            ]);

            return response()->json([
                'deleted' => false,
                'error' => $result['description'] ?? 'Failed to delete Telegram webhook.',
                'response' => $result,
            ], 422);
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.telegram_webhook_delete_error', [
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'deleted' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function importLegacyTelegramTopics(TelegramTopicDiscoveryService $service, TelegramRoutingService $routingService): JsonResponse
    {
        try {
            $summary = $service->importLegacyTopics();
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.telegram_topics_legacy_import_failed', [
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'imported' => false,
                'error' => $error->getMessage(),
                'diagnostics' => $service->diagnostics(),
            ], 422);
        }

        return response()->json([
            'imported' => true,
            'summary' => $summary,
            'topics' => $this->recentTelegramTopics(100),
            'routes' => $this->telegramRoutes(),
            'route_statuses' => $this->routeStatuses($routingService),
            'diagnostics' => $service->diagnostics(),
        ]);
    }

    private function recentTelegramMessages(?string $routeKey = null, int $limit = 20): array
    {
        if (!Schema::hasTable('sportsbot_telegram_messages')) {
            return [];
        }

        $query = SportsBotTelegramMessage::query()
            ->latest('id')
            ->limit(max(1, min(100, $limit)));

        if ($routeKey !== null) {
            $query->where('route_key', TelegramRouteKeys::normalize($routeKey));
        }

        return $query->get()->all();
    }

    private function recentTelegramTopics(int $limit = 20): array
    {
        if (!Schema::hasTable('sportsbot_telegram_topics')) {
            return [];
        }

        return SportsBotTelegramTopic::query()
            ->latest('last_seen_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->all();
    }

    private function telegramRoutes(): array
    {
        if (!Schema::hasTable('sportsbot_telegram_routes')) {
            return [];
        }

        return SportsBotTelegramRoute::query()
            ->orderBy('route_key')
            ->get()
            ->all();
    }

    private function routeStatuses(TelegramRoutingService $routingService): array
    {
        $statuses = [];

        foreach (TelegramRouteKeys::all() as $routeKey) {
            $statuses[$routeKey] = $routingService->resolveTargets($routeKey);
        }

        return $statuses;
    }

    /**
     * @return array{chat_id:string,message_thread_id:int|null}|null
     */
    private function normalizeTelegramTarget(string $target, mixed $messageThreadId = null, bool $requireThread = true): ?array
    {
        $target = trim($target);
        $threadId = $this->normalizeThreadId($messageThreadId);

        if ($target === '') {
            return null;
        }

        if (preg_match('#https?://t\.me/c/(\d+)/(\d+)(?:/\d+)?#i', $target, $matches) === 1) {
            return [
                'chat_id' => '-100' . $matches[1],
                'message_thread_id' => (int) $matches[2],
            ];
        }

        if (preg_match('/^(-?\d+)[|:](\d+)$/', $target, $matches) === 1) {
            return [
                'chat_id' => $matches[1],
                'message_thread_id' => (int) $matches[2],
            ];
        }

        if (!preg_match('/^-?\d+$/', $target)) {
            return null;
        }

        if ($requireThread && $threadId === null) {
            return null;
        }

        return [
            'chat_id' => $target,
            'message_thread_id' => $threadId,
        ];
    }

    private function normalizeThreadId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $threadId = is_numeric((string) $value) ? (int) $value : 0;

        return $threadId > 0 ? $threadId : null;
    }
}
