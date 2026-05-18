<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotDelivery;
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
use App\Plugins\SportsBot\Services\Content\MotorsportFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\RugbyFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\TvGuideContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use App\Plugins\SportsBot\Services\SportsBotRunner;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Services\FixtureQueueService;
use App\Plugins\SportsBot\Services\SportsFixturePublisher;
use App\Plugins\SportsBot\Services\DiscordNotifier;
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

    public function autopilotStatus(SportsBotRunner $runner, SportsBotSettingsService $settings): JsonResponse
    {
        return response()->json([
            'health' => $runner->health(),
            'scheduler' => $this->sportsBotSchedulerStatus(),
            'queue' => $this->fixtureQueueAutopilotStatus(),
            'deliveries' => [
                'recent' => $this->recentDeliveries(50),
                'last_24h' => $this->deliveryCountsSince(now()->subDay()),
            ],
            'settings' => [
                'discord_enabled' => (bool) $settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false)),
                'scrapers_enabled' => (bool) $settings->get('scraper_enabled', config('plugins.SportsBot.scrapers.enabled', true)),
                'auto_use_confidence' => (float) $settings->get('scraper_auto_use_confidence', config('plugins.SportsBot.scrapers.auto_use_confidence', 0.9)),
            ],
        ]);
    }

    public function postTimings(SportsBotSettingsService $settings): JsonResponse
    {
        return response()->json([
            'settings' => $this->postTimingSettings($settings),
            'frequencies' => $this->postTimingFrequencies(),
        ]);
    }

    public function savePostTimings(Request $request, SportsBotSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'schedule_enabled' => ['sometimes', 'boolean'],
            'schedule_frequency' => ['sometimes', 'string', 'max:80'],
            'fixtures_today_schedule_enabled' => ['sometimes', 'boolean'],
            'fixtures_today_schedule_time' => ['sometimes', 'date_format:H:i'],
            'tv_guide_schedule_enabled' => ['sometimes', 'boolean'],
            'tv_guide_schedule_time' => ['sometimes', 'date_format:H:i'],
            'live_now_schedule_enabled' => ['sometimes', 'boolean'],
            'live_now_schedule_frequency' => ['sometimes', 'string', 'max:80'],
            'fixture_queue_schedule_enabled' => ['sometimes', 'boolean'],
            'fixture_queue_prefetch_enabled' => ['sometimes', 'boolean'],
            'fixture_queue_prefetch_time' => ['sometimes', 'date_format:H:i'],
            'fixture_queue_enrich_enabled' => ['sometimes', 'boolean'],
            'fixture_queue_enrich_frequency' => ['sometimes', 'string', 'max:80'],
            'fixture_queue_enrich_days' => ['sometimes', 'integer', 'min:0', 'max:14'],
            'fixture_queue_enrich_limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'fixture_queue_render_enabled' => ['sometimes', 'boolean'],
            'fixture_queue_render_frequency' => ['sometimes', 'string', 'max:80'],
            'fixture_queue_publish_enabled' => ['sometimes', 'boolean'],
            'fixture_queue_publish_frequency' => ['sometimes', 'string', 'max:80'],
        ]);

        $frequencies = array_column($this->postTimingFrequencies(), 'value');
        foreach ([
            'schedule_frequency',
            'live_now_schedule_frequency',
            'fixture_queue_enrich_frequency',
            'fixture_queue_render_frequency',
            'fixture_queue_publish_frequency',
        ] as $frequencyKey) {
            if (isset($validated[$frequencyKey]) && !in_array($validated[$frequencyKey], $frequencies, true)) {
                return response()->json([
                    'saved' => false,
                    'error' => "Unsupported frequency for {$frequencyKey}.",
                ], 422);
            }
        }

        foreach ($validated as $key => $value) {
            $settings->set($key, $value);
        }

        Log::info('sportsbot.admin.post_timings_saved', [
            'keys' => array_keys($validated),
        ]);

        return response()->json([
            'saved' => true,
            'settings' => $this->postTimingSettings($settings),
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
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('football_fixture_card_version', 'v3'));

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
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
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
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('rugby_fixture_card_version', 'v3'));

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
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
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
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('fight_fixture_card_version', 'v3'));

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
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
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

    public function motorsportFixturesPreview(
        Request $request,
        MotorsportFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('formula_1_fixture_card_version', 'v3'));

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'captions_enabled' => (bool) $settings->get('formula_1_fixture_captions_enabled', false),
            'card_version' => $cardVersion,
        ]));
    }

    public function motorsportFixturesSend(
        Request $request,
        MotorsportFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotSettingsService $settings,
    ): JsonResponse
    {
        $validated = $request->validate([
            'captions_enabled' => ['sometimes', 'boolean'],
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);

        if (array_key_exists('captions_enabled', $validated)) {
            $settings->set('formula_1_fixture_captions_enabled', (bool) $validated['captions_enabled']);
        }
        if (array_key_exists('card_version', $validated)) {
            $settings->set('formula_1_fixture_card_version', $this->footballFixtureCardVersion($validated['card_version']));
        }

        try {
            return response()->json($publisher->send($module, 'admin_api'));
        } catch (Throwable $error) {
            Log::error('sportsbot.admin.motorsport_fixtures_send_failed', [
                'route_key' => TelegramRouteKeys::MOTORSPORT,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::MOTORSPORT,
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    public function sportFixturePreview(
        string $sport,
        Request $request,
        SportsFixturePublisher $fixturePublisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
    ): JsonResponse {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);

        $config = SportsFixtureConfig::for($sport);
        if ($config === null) {
            return response()->json(['error' => "Unknown sport: {$sport}"], 422);
        }

        $preview = $fixturePublisher->preview($sport);
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
        SportsFixturePublisher $fixturePublisher,
        SportsBotSettingsService $settings,
    ): JsonResponse {
        $config = SportsFixtureConfig::for($sport);
        if ($config === null) {
            return response()->json(['error' => "Unknown sport: {$sport}"], 422);
        }

        $validated = $request->validate([
            'captions_enabled' => ['sometimes', 'boolean'],
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
        ]);

        if (array_key_exists('captions_enabled', $validated)) {
            $settings->set($this->settingKey($sport, 'captions_enabled'), (bool) $validated['captions_enabled']);
        }
        if (array_key_exists('card_version', $validated)) {
            $settings->set($this->settingKey($sport, 'card_version'), $this->footballFixtureCardVersion($validated['card_version']));
        }

        try {
            return response()->json($fixturePublisher->send($sport, 'admin_api'));
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
                ->whereNull('sent_at')
                ->whereNull('telegram_message_id')
                ->ready()
                ->count(),
            'recent_items' => SportsBotFixtureQueue::query()
                ->latest('updated_at')
                ->limit(500)
                ->get()
                ->map(fn (SportsBotFixtureQueue $entry): array => $queue->itemData($entry))
                ->values()
                ->all(),
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

    public function fixtureQueueItem(int $id, FixtureQueueService $queue): JsonResponse
    {
        $item = $queue->find($id);
        if (!$item) {
            return response()->json(['error' => "Queue item {$id} not found"], 404);
        }

        return response()->json(['item' => $queue->itemData($item)]);
    }

    public function fixtureQueueReRender(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->reRenderItem($id));
    }

    public function fixtureQueuePublishNow(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->publishNow($id));
    }

    public function fixtureQueueFindPoster(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->findPoster($id));
    }

    public function fixtureQueueFindTvInfo(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->findTvInfo($id));
    }

    public function fixtureQueueRefreshScrapedData(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->refreshScrapedData($id));
    }

    public function fixtureQueueAcceptScrapedData(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->acceptScrapedData($id));
    }

    public function fixtureQueueRejectScrapedData(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->rejectScrapedData($id));
    }

    public function fixtureQueueSkip(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->skipItem($id));
    }

    public function fixtureQueueDelete(int $id, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->deleteItem($id));
    }

    public function fixtureQueueCard(int $id, FixtureQueueService $queue): mixed
    {
        $item = $queue->find($id);
        if (!$item || !$item->card_path || !is_file($item->card_path)) {
            abort(404);
        }

        return response()->file($item->card_path);
    }

    private function resolveContentModule(string $sport): ?object
    {
        return match (SportsBotSports::normalize($sport)) {
            'football' => app(\App\Plugins\SportsBot\Services\Content\FootballFixturesContentModule::class),
            'rugby' => app(\App\Plugins\SportsBot\Services\Content\RugbyFixturesContentModule::class),
            'fights', 'mma', 'boxing' => app(\App\Plugins\SportsBot\Services\Content\FightFixturesContentModule::class),
            'formula_1', 'motorsport' => app(\App\Plugins\SportsBot\Services\Content\MotorsportFixturesContentModule::class),
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

        if ($previews === [] && (int) ($summary['fixtures_total'] ?? 0) === 0) {
            try {
                $card = $cards->noFixturesCard($summary, $cardVersion);
                $path = (string) ($card['path'] ?? '');
                if ($path !== '' && is_file($path)) {
                    $previews[] = [
                        'event_id' => '',
                        'title' => (string) ($summary['title'] ?? 'No fixtures today'),
                        'league' => (string) ($summary['route_key'] ?? ''),
                        'time' => (string) ($summary['date'] ?? ''),
                        'tv_channel' => 'No fixtures',
                        'card_version' => $cardVersion,
                        'data_url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)),
                    ];
                }
            } catch (Throwable $error) {
                Log::warning('sportsbot.admin.no_fixtures_card_preview_failed', [
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $previews;
    }

    private function footballFixtureCardVersion(mixed $version): string
    {
        $version = strtolower(trim((string) $version));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
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
                'discord_enabled' => (bool) config('plugins.SportsBot.discord.enabled', false),
                'discord_default_webhook_url' => (string) config('plugins.SportsBot.discord.default_webhook_url', ''),
                'discord_username' => (string) config('plugins.SportsBot.discord.username', 'SportsBot'),
                'discord_avatar_url' => (string) config('plugins.SportsBot.discord.avatar_url', ''),
                'discord_route_webhooks' => (array) config('plugins.SportsBot.discord.route_webhooks', []),
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
            'discord_send_diagnostics' => [
                'configured' => app(DiscordNotifier::class)->configured(),
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
            'discord_enabled' => ['sometimes', 'boolean'],
            'discord_default_webhook_url' => ['nullable', 'string', 'max:500'],
            'discord_username' => ['nullable', 'string', 'max:80'],
            'discord_avatar_url' => ['nullable', 'string', 'max:500'],
            'discord_route_webhooks' => ['sometimes', 'array'],
            'discord_route_webhooks.*' => ['nullable', 'string', 'max:500'],
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

    public function scraperSettings(SportsBotSettingsService $settings): JsonResponse
    {
        $current = [
            'enabled' => (bool) $settings->get('scraper_enabled', config('plugins.SportsBot.scrapers.enabled', true)),
            'search_enabled' => (bool) $settings->get('scraper_search_enabled', config('plugins.SportsBot.scrapers.search_enabled', true)),
            'search_urls' => $this->stringList($settings->get('scraper_search_urls', config('plugins.SportsBot.scrapers.search_urls', []))),
            'search_max_results' => (int) $settings->get('scraper_search_max_results', config('plugins.SportsBot.scrapers.search_max_results', 5)),
            'timeout' => (int) $settings->get('scraper_timeout', config('plugins.SportsBot.scrapers.timeout', 8)),
            'auto_use_confidence' => (float) $settings->get('scraper_auto_use_confidence', config('plugins.SportsBot.scrapers.auto_use_confidence', 0.9)),
            'combat_poster_urls' => $this->stringList($settings->get('scraper_combat_poster_urls', config('plugins.SportsBot.scrapers.combat_poster_urls', []))),
            'broadcast_schedule_urls' => $this->stringList($settings->get('scraper_broadcast_schedule_urls', config('plugins.SportsBot.scrapers.broadcast_schedule_urls', []))),
            'f1_schedule_urls' => $this->stringList($settings->get('scraper_f1_schedule_urls', config('plugins.SportsBot.scrapers.f1_schedule_urls', []))),
            'combat_poster_search_queries' => $this->stringList($settings->get('scraper_combat_poster_search_queries', config('plugins.SportsBot.scrapers.combat_poster_search_queries', []))),
            'broadcast_schedule_search_queries' => $this->stringList($settings->get('scraper_broadcast_schedule_search_queries', config('plugins.SportsBot.scrapers.broadcast_schedule_search_queries', []))),
            'f1_schedule_search_queries' => $this->stringList($settings->get('scraper_f1_schedule_search_queries', config('plugins.SportsBot.scrapers.f1_schedule_search_queries', []))),
        ];

        $usesDefaultSearch = $current['search_urls'] === ['https://duckduckgo.com/html/?q={query}'];

        return response()->json([
            'settings' => $current,
            'diagnostics' => [
                'search_configured' => $current['search_enabled'] && $current['search_urls'] !== [],
                'uses_default_search' => $usesDefaultSearch,
                'known_source_count' => count($current['combat_poster_urls']) + count($current['broadcast_schedule_urls']) + count($current['f1_schedule_urls']),
                'default_search_warning' => $usesDefaultSearch
                    ? 'The default DuckDuckGo page often returns a human challenge to backend requests. Use known source URLs or a search endpoint you are allowed to call for reliable enrichment.'
                    : null,
            ],
            'examples' => [
                'search_url' => 'https://your-search.example/search?q={query}&format=json',
                'known_source_url' => 'https://www.skysports.com/watch/sport-on-sky',
            ],
        ]);
    }

    public function saveScraperSettings(Request $request, SportsBotSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'search_enabled' => ['sometimes', 'boolean'],
            'search_urls' => ['sometimes', 'array'],
            'search_urls.*' => ['string', 'max:500'],
            'search_max_results' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'timeout' => ['sometimes', 'integer', 'min:2', 'max:30'],
            'auto_use_confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'combat_poster_urls' => ['sometimes', 'array'],
            'combat_poster_urls.*' => ['string', 'max:500'],
            'broadcast_schedule_urls' => ['sometimes', 'array'],
            'broadcast_schedule_urls.*' => ['string', 'max:500'],
            'f1_schedule_urls' => ['sometimes', 'array'],
            'f1_schedule_urls.*' => ['string', 'max:500'],
            'combat_poster_search_queries' => ['sometimes', 'array'],
            'combat_poster_search_queries.*' => ['string', 'max:240'],
            'broadcast_schedule_search_queries' => ['sometimes', 'array'],
            'broadcast_schedule_search_queries.*' => ['string', 'max:240'],
            'f1_schedule_search_queries' => ['sometimes', 'array'],
            'f1_schedule_search_queries.*' => ['string', 'max:240'],
        ]);

        foreach ($validated as $key => $value) {
            if (is_array($value)) {
                $value = $this->stringList($value);
            }
            $settings->set('scraper_' . $key, $value);
        }

        Log::info('sportsbot.admin.scraper_settings_saved', [
            'keys' => array_keys($validated),
        ]);

        return response()->json([
            'saved' => true,
            'settings' => $this->scraperSettings($settings)->getData(true)['settings'] ?? [],
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

    public function sendDiscordDiagnostics(Request $request, DiscordNotifier $notifier, SportsBotCardRenderer $cards): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['sometimes', 'string', 'max:100'],
            'media' => ['sometimes', 'boolean'],
        ]);

        $routeKey = TelegramRouteKeys::normalize((string) ($validated['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $caption = 'SportsBot Discord diagnostic - ' . now()->toDateTimeString();

        try {
            if ((bool) ($validated['media'] ?? true)) {
                $card = $cards->breakingNewsCard([
                    'title' => 'SportsBot Diagnostics',
                    'summary' => 'Rich card generation and Discord webhook delivery are working.',
                    'source' => 'LaravelCP Admin',
                ]);
                $results = $notifier->sendPhoto((string) $card['path'], $caption, [
                    'route_key' => $routeKey,
                    'type' => 'DISCORD_DIAGNOSTIC',
                ]);
            } else {
                $results = $notifier->send($caption, [
                    'route_key' => $routeKey,
                    'type' => 'DISCORD_DIAGNOSTIC',
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

    public function discordRoutesIndex(SportsBotSettingsService $settings): JsonResponse
    {
        return response()->json([
            'route_keys' => TelegramRouteKeys::all(),
            'settings' => $this->discordSettings($settings),
            'routes' => $this->discordRoutes($settings),
            'route_statuses' => $this->discordRouteStatuses($settings),
        ]);
    }

    public function saveDiscordSettings(Request $request, SportsBotSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'discord_enabled' => ['sometimes', 'boolean'],
            'discord_default_webhook_url' => ['nullable', 'string', 'max:500'],
            'discord_username' => ['nullable', 'string', 'max:80'],
            'discord_avatar_url' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['discord_default_webhook_url', 'discord_avatar_url'] as $urlKey) {
            $url = trim((string) ($validated[$urlKey] ?? ''));
            if ($url !== '' && $urlKey === 'discord_default_webhook_url' && !$this->isDiscordWebhookUrl($url)) {
                return response()->json([
                    'saved' => false,
                    'error' => 'Default webhook URL must be a Discord webhook URL.',
                ], 422);
            }
        }

        foreach ($validated as $key => $value) {
            $settings->set($key, is_string($value) ? trim($value) : $value);
        }

        Log::info('sportsbot.admin.discord_settings_saved', [
            'keys' => array_keys($validated),
        ]);

        return response()->json([
            'saved' => true,
            'settings' => $this->discordSettings($settings),
            'route_statuses' => $this->discordRouteStatuses($settings),
        ]);
    }

    public function saveDiscordRoute(Request $request, SportsBotSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['required', 'string', 'max:100'],
            'webhook_url' => ['required', 'string', 'max:500'],
        ]);

        $routeKey = TelegramRouteKeys::normalize((string) $validated['route_key']);

        if (!in_array($routeKey, TelegramRouteKeys::all(), true)) {
            return response()->json([
                'saved' => false,
                'error' => 'Unsupported SportsBot route key.',
            ], 422);
        }

        $webhookUrl = trim((string) $validated['webhook_url']);
        if (!$this->isDiscordWebhookUrl($webhookUrl)) {
            return response()->json([
                'saved' => false,
                'error' => 'Webhook URL must start with https://discord.com/api/webhooks/ or https://discordapp.com/api/webhooks/.',
            ], 422);
        }

        if ($routeKey === TelegramRouteKeys::DEFAULT) {
            $settings->set('discord_default_webhook_url', $webhookUrl);
        } else {
            $routes = $this->discordWebhookMap($settings);
            $routes[$routeKey] = $webhookUrl;
            $settings->set('discord_route_webhooks', $routes);
        }

        Log::info('sportsbot.admin.discord_route_saved', [
            'route_key' => $routeKey,
            'has_webhook' => $webhookUrl !== '',
        ]);

        return response()->json([
            'saved' => true,
            'settings' => $this->discordSettings($settings),
            'routes' => $this->discordRoutes($settings),
            'route_statuses' => $this->discordRouteStatuses($settings),
        ]);
    }

    public function deleteDiscordRoute(string $routeKey, SportsBotSettingsService $settings): JsonResponse
    {
        $normalized = TelegramRouteKeys::normalize($routeKey);

        if ($normalized === TelegramRouteKeys::DEFAULT) {
            $settings->set('discord_default_webhook_url', '');
        } else {
            $routes = $this->discordWebhookMap($settings);
            unset($routes[$normalized]);
            $settings->set('discord_route_webhooks', $routes);
        }

        return response()->json([
            'deleted' => true,
            'settings' => $this->discordSettings($settings),
            'routes' => $this->discordRoutes($settings),
            'route_statuses' => $this->discordRouteStatuses($settings),
        ]);
    }

    public function testDiscordRoute(Request $request, DiscordNotifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['required', 'string', 'max:100'],
        ]);

        $routeKey = TelegramRouteKeys::normalize((string) $validated['route_key']);

        try {
            $results = $notifier->send('SportsBot Discord route test - ' . now()->toDateTimeString(), [
                'route_key' => $routeKey,
                'type' => 'DISCORD_ROUTE_TEST',
            ]);
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

    /**
     * @return array<string, mixed>
     */
    private function discordSettings(SportsBotSettingsService $settings): array
    {
        return [
            'discord_enabled' => (bool) $settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false)),
            'discord_default_webhook_url' => (string) $settings->get('discord_default_webhook_url', config('plugins.SportsBot.discord.default_webhook_url', '')),
            'discord_username' => (string) $settings->get('discord_username', config('plugins.SportsBot.discord.username', 'SportsBot')),
            'discord_avatar_url' => (string) $settings->get('discord_avatar_url', config('plugins.SportsBot.discord.avatar_url', '')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function discordRoutes(SportsBotSettingsService $settings): array
    {
        $routes = [];
        $defaultWebhook = trim((string) $settings->get('discord_default_webhook_url', config('plugins.SportsBot.discord.default_webhook_url', '')));

        if ($defaultWebhook !== '') {
            $routes[] = [
                'route_key' => TelegramRouteKeys::DEFAULT,
                'webhook_url' => $defaultWebhook,
                'source' => 'default',
            ];
        }

        foreach ($this->discordWebhookMap($settings) as $routeKey => $webhookUrl) {
            $routes[] = [
                'route_key' => $routeKey,
                'webhook_url' => $webhookUrl,
                'source' => 'route',
            ];
        }

        usort($routes, static fn (array $a, array $b): int => strcmp((string) $a['route_key'], (string) $b['route_key']));

        return $routes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function discordRouteStatuses(SportsBotSettingsService $settings): array
    {
        $enabled = (bool) $settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false));
        $defaultWebhook = trim((string) $settings->get('discord_default_webhook_url', config('plugins.SportsBot.discord.default_webhook_url', '')));
        $routes = $this->discordWebhookMap($settings);
        $statuses = [];

        foreach (TelegramRouteKeys::all() as $routeKey) {
            $hasRouteWebhook = isset($routes[$routeKey]) && trim((string) $routes[$routeKey]) !== '';
            $hasDefaultWebhook = $defaultWebhook !== '';

            $statuses[$routeKey] = [
                'configured' => $enabled && ($hasRouteWebhook || $hasDefaultWebhook),
                'enabled' => $enabled,
                'source' => $hasRouteWebhook ? 'route' : ($hasDefaultWebhook ? 'default' : 'none'),
                'fallback' => !$hasRouteWebhook && $hasDefaultWebhook && $routeKey !== TelegramRouteKeys::DEFAULT,
                'has_route_webhook' => $hasRouteWebhook,
                'has_default_webhook' => $hasDefaultWebhook,
            ];
        }

        return $statuses;
    }

    /**
     * @return array<string, string>
     */
    private function discordWebhookMap(SportsBotSettingsService $settings): array
    {
        $value = $settings->get('discord_route_webhooks', config('plugins.SportsBot.discord.route_webhooks', []));

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $routes = [];
        foreach ($value as $key => $url) {
            $routeKey = TelegramRouteKeys::normalize((string) $key);
            $url = trim((string) $url);

            if ($routeKey !== '' && $url !== '' && $this->isDiscordWebhookUrl($url)) {
                $routes[$routeKey] = $url;
            }
        }

        return $routes;
    }

    private function isDiscordWebhookUrl(string $url): bool
    {
        return str_starts_with($url, 'https://discord.com/api/webhooks/')
            || str_starts_with($url, 'https://discordapp.com/api/webhooks/');
    }

    /**
     * @return array<string, mixed>
     */
    private function sportsBotSchedulerStatus(): array
    {
        $timings = $this->postTimingSettings(app(SportsBotSettingsService::class));
        $fixtureQueue = (array) ($timings['fixture_queue'] ?? []);

        return [
            'plugin_enabled' => (bool) config('plugins.SportsBot.enabled', true),
            'live_alerts_enabled' => (bool) ($timings['live_alerts']['enabled'] ?? false),
            'live_alerts_frequency' => (string) ($timings['live_alerts']['frequency'] ?? 'everyTwoMinutes'),
            'fixture_queue' => $fixtureQueue,
            'logs' => $this->schedulerLogFiles(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postTimingSettings(SportsBotSettingsService $settings): array
    {
        return [
            'live_alerts' => [
                'enabled' => (bool) $settings->get('schedule_enabled', config('plugins.SportsBot.schedule.enabled', false)),
                'frequency' => (string) $settings->get('schedule_frequency', config('plugins.SportsBot.schedule.frequency', 'everyTwoMinutes')),
            ],
            'fixtures_today' => [
                'enabled' => (bool) $settings->get('fixtures_today_schedule_enabled', config('plugins.SportsBot.publishing.fixtures_today.enabled', false)),
                'time' => (string) $settings->get('fixtures_today_schedule_time', config('plugins.SportsBot.publishing.fixtures_today.time', '08:00')),
            ],
            'tv_guide' => [
                'enabled' => (bool) $settings->get('tv_guide_schedule_enabled', config('plugins.SportsBot.publishing.tv_guide.enabled', false)),
                'time' => (string) $settings->get('tv_guide_schedule_time', config('plugins.SportsBot.publishing.tv_guide.time', '08:00')),
            ],
            'live_now' => [
                'enabled' => (bool) $settings->get('live_now_schedule_enabled', config('plugins.SportsBot.publishing.live_now.enabled', false)),
                'frequency' => (string) $settings->get('live_now_schedule_frequency', config('plugins.SportsBot.publishing.live_now.frequency', 'everyFiveMinutes')),
            ],
            'fixture_queue' => [
                'enabled' => (bool) $settings->get('fixture_queue_schedule_enabled', config('plugins.SportsBot.publishing.fixture_queue.enabled', false)),
                'prefetch_enabled' => (bool) $settings->get('fixture_queue_prefetch_enabled', config('plugins.SportsBot.publishing.fixture_queue.prefetch_enabled', true)),
                'prefetch_time' => (string) $settings->get('fixture_queue_prefetch_time', config('plugins.SportsBot.publishing.fixture_queue.prefetch_time', '05:00')),
                'enrich_enabled' => (bool) $settings->get('fixture_queue_enrich_enabled', config('plugins.SportsBot.publishing.fixture_queue.enrich_enabled', true)),
                'enrich_frequency' => (string) $settings->get('fixture_queue_enrich_frequency', config('plugins.SportsBot.publishing.fixture_queue.enrich_frequency', 'everyThirtyMinutes')),
                'enrich_days' => (int) $settings->get('fixture_queue_enrich_days', config('plugins.SportsBot.publishing.fixture_queue.enrich_days', 2)),
                'enrich_limit' => (int) $settings->get('fixture_queue_enrich_limit', config('plugins.SportsBot.publishing.fixture_queue.enrich_limit', 30)),
                'render_enabled' => (bool) $settings->get('fixture_queue_render_enabled', config('plugins.SportsBot.publishing.fixture_queue.render_enabled', true)),
                'render_frequency' => (string) $settings->get('fixture_queue_render_frequency', config('plugins.SportsBot.publishing.fixture_queue.render_frequency', 'everyTenMinutes')),
                'publish_enabled' => (bool) $settings->get('fixture_queue_publish_enabled', config('plugins.SportsBot.publishing.fixture_queue.publish_enabled', true)),
                'publish_frequency' => (string) $settings->get('fixture_queue_publish_frequency', config('plugins.SportsBot.publishing.fixture_queue.publish_frequency', 'everyFiveMinutes')),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function postTimingFrequencies(): array
    {
        return [
            ['value' => 'everyMinute', 'label' => 'Every minute'],
            ['value' => 'everyTwoMinutes', 'label' => 'Every 2 minutes'],
            ['value' => 'everyFiveMinutes', 'label' => 'Every 5 minutes'],
            ['value' => 'everyTenMinutes', 'label' => 'Every 10 minutes'],
            ['value' => 'everyFifteenMinutes', 'label' => 'Every 15 minutes'],
            ['value' => 'everyThirtyMinutes', 'label' => 'Every 30 minutes'],
            ['value' => 'hourly', 'label' => 'Hourly'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureQueueAutopilotStatus(): array
    {
        if (!Schema::hasTable('sportsbot_fixture_queue')) {
            return [
                'counts' => [],
                'today' => [],
                'needs_attention' => [],
            ];
        }

        $counts = SportsBotFixtureQueue::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $today = Carbon::today()->toDateString();
        $windowEnd = Carbon::today()->addDays(2)->toDateString();
        $windowRows = SportsBotFixtureQueue::query()
            ->whereBetween('publish_date', [$today, $windowEnd])
            ->latest('id')
            ->limit(300)
            ->get();

        $missingTv = 0;
        $missingCard = 0;
        $scraperFound = 0;

        foreach ($windowRows as $row) {
            $fixture = (array) ($row->fixture_data ?? []);
            $payload = (array) ($row->payload ?? []);
            if (trim((string) ($fixture['tv_channel'] ?? '')) === '' && empty($fixture['tv_channels'] ?? [])) {
                $missingTv++;
            }
            if (trim((string) ($row->card_path ?? '')) === '' && $row->status !== SportsBotFixtureQueue::STATUS_SENT) {
                $missingCard++;
            }
            if (($payload['scraper']['status'] ?? null) === 'found') {
                $scraperFound++;
            }
        }

        return [
            'counts' => $counts,
            'today' => [
                'draft' => SportsBotFixtureQueue::query()->where('publish_date', $today)->where('status', SportsBotFixtureQueue::STATUS_DRAFT)->count(),
                'ready' => SportsBotFixtureQueue::query()->where('publish_date', $today)->where('status', SportsBotFixtureQueue::STATUS_READY)->count(),
                'sent' => SportsBotFixtureQueue::query()->where('publish_date', $today)->where('status', SportsBotFixtureQueue::STATUS_SENT)->count(),
                'failed' => SportsBotFixtureQueue::query()->where('publish_date', $today)->where('status', SportsBotFixtureQueue::STATUS_FAILED)->count(),
            ],
            'needs_attention' => [
                'window_rows' => $windowRows->count(),
                'missing_tv' => $missingTv,
                'missing_card' => $missingCard,
                'scraper_found' => $scraperFound,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentDeliveries(int $limit = 50): array
    {
        if (!Schema::hasTable('sportsbot_deliveries')) {
            return [];
        }

        return SportsBotDelivery::query()
            ->latest('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deliveryCountsSince(Carbon $since): array
    {
        if (!Schema::hasTable('sportsbot_deliveries')) {
            return [];
        }

        return SportsBotDelivery::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('platform, status, count(*) as total')
            ->groupBy('platform', 'status')
            ->get()
            ->map(fn (SportsBotDelivery $row): array => [
                'platform' => $row->platform,
                'status' => $row->status,
                'total' => (int) ($row->total ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function schedulerLogFiles(): array
    {
        $files = [
            'Live alerts' => 'sportsbot-scheduler.log',
            'Fixture prefetch' => 'sportsbot-fixture-queue-prefetch.log',
            'Fixture enrich' => 'sportsbot-fixture-queue-enrich.log',
            'Fixture render' => 'sportsbot-fixture-queue-render.log',
            'Fixture publish' => 'sportsbot-fixture-queue-publish.log',
            'Fixtures today' => 'sportsbot-fixtures-today.log',
            'TV guide' => 'sportsbot-tv-guide.log',
            'Live now' => 'sportsbot-live-now.log',
        ];

        $logs = [];
        foreach ($files as $label => $file) {
            $path = storage_path('logs/' . $file);
            $exists = is_file($path);
            $logs[] = [
                'label' => $label,
                'file' => $file,
                'exists' => $exists,
                'last_modified_at' => $exists ? date('c', filemtime($path)) : null,
                'size' => $exists ? filesize($path) : 0,
            ];
        }

        return $logs;
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
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $items = preg_split('/\r\n|\r|\n|,/', $value) ?: [];
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            $items = [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items
        ))));
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
