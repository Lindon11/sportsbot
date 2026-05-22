<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotDelivery;
use App\Plugins\SportsBot\Models\SportsBotEpgFixtureMatch;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabber;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabberRun;
use App\Plugins\SportsBot\Models\SportsBotEpgImportRun;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use App\Plugins\SportsBot\Models\SportsBotHighlightSent;
use App\Plugins\SportsBot\Models\SportsBotMatchState;
use App\Plugins\SportsBot\Models\SportsBotMonitorBot;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use App\Plugins\SportsBot\Models\SportsBotUptimeLog;
use App\Plugins\SportsBot\Models\SportsBotPipelineRun;
use App\Plugins\SportsBot\Models\SportsBotRun;
use App\Plugins\SportsBot\Models\SportsBotSentAlert;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Models\SportsBotTelegramRoute;
use App\Plugins\SportsBot\Models\SportsBotTelegramTopic;
use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\Content\FightFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\FootballFixturesContentModule;
use App\Plugins\SportsBot\Services\Content\HighlightsContentModule;
use App\Plugins\SportsBot\Services\Content\RugbyFixturesContentModule;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use App\Plugins\SportsBot\Services\SportsBotRunner;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotEpgGrabberRuntime;
use App\Plugins\SportsBot\Services\SportsBotEpgGuideService;
use App\Plugins\SportsBot\Services\SportsBotEpgHealthService;
use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use App\Plugins\SportsBot\Services\SportsBotEpgSourceImporter;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Services\SportsBotNotifier;
use App\Plugins\SportsBot\Services\SportsBotUptimeAlertCardService;
use App\Plugins\SportsBot\Services\FixtureQueueService;
use App\Plugins\SportsBot\Services\SportsFixturePublisher;
use App\Plugins\SportsBot\Services\DiscordNotifier;
use App\Plugins\SportsBot\Services\SportsBotAssetCache;
use App\Plugins\SportsBot\Services\TelegramNotifier;
use App\Plugins\SportsBot\Services\TheSportsDbClient;
use App\Plugins\SportsBot\Services\TelegramRoutingService;
use App\Plugins\SportsBot\Services\TelegramTopicDiscoveryService;
use App\Plugins\SportsBot\Support\CardTemplateRegistry;
use App\Plugins\SportsBot\Support\SportsBotPaths;
use App\Plugins\SportsBot\Support\SportsBotSports;
use App\Plugins\SportsBot\Support\SportsBotFixtureReadiness;
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
            'pipeline' => $this->pipelineRunStatus(),
            'deliveries' => [
                'recent' => $this->recentDeliveries(50),
                'last_24h' => $this->deliveryCountsSince(now()->subDay()),
            ],
            'settings' => [
                'discord_enabled' => (bool) $settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false)),
                'scrapers_enabled' => (bool) $settings->get('scraper_enabled', config('plugins.SportsBot.scrapers.enabled', true)),
                'auto_use_confidence' => (float) $settings->get('scraper_auto_use_confidence', config('plugins.SportsBot.scrapers.auto_use_confidence', 0.9)),
                'allow_gd_fallback_publish' => (bool) $settings->get('fixture_queue_allow_gd_fallback_publish', config('plugins.SportsBot.publishing.fixture_queue.allow_gd_fallback_publish', false)),
                'fallback_retry_enabled' => (bool) $settings->get('fixture_queue_fallback_retry_enabled', config('plugins.SportsBot.publishing.fixture_queue.fallback_retry_enabled', true)),
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
            'fixture_queue_publish_time' => ['sometimes', 'date_format:H:i'],
            'highlights_schedule_enabled' => ['sometimes', 'boolean'],
            'highlights_schedule_frequency' => ['sometimes', 'string', 'max:80'],
        ]);

        $frequencies = array_column($this->postTimingFrequencies(), 'value');
        foreach ([
            'schedule_frequency',
            'fixture_queue_enrich_frequency',
            'fixture_queue_render_frequency',
            'highlights_schedule_frequency',
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

    public function footballFixturesPreview(
        Request $request,
        FootballFixturesContentModule $module,
        SportsBotPublisher $publisher,
        SportsBotCardRenderer $cards,
        SportsBotSettingsService $settings,
        TheSportsDbClient $provider,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
            'preview_league' => ['sometimes', 'string', 'max:200'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('football_fixture_card_version', 'v3'));
        $previewLeague = trim((string) ($validated['preview_league'] ?? ''));

        $leaguePreview = $this->leagueCardPreview($summary, $cards, $cardVersion, $previewLeague);
        $allLeagues = $this->allFootballLeagues($cards, $provider, $settings, $cardVersion, $previewLeague);

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'league_card_preview' => $leaguePreview,
            'all_leagues' => $allLeagues,
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
        TheSportsDbClient $provider,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
            'preview_league' => ['sometimes', 'string', 'max:200'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('rugby_fixture_card_version', 'v3'));
        $previewLeague = trim((string) ($validated['preview_league'] ?? ''));

        $allLeagues = $this->allRugbyLeagues($cards, $provider, $cardVersion, $previewLeague);

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'league_card_preview' => $this->leagueCardPreview($summary, $cards, $cardVersion),
            'all_rugby_leagues' => $allLeagues,
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
        TheSportsDbClient $provider,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
            'preview_league' => ['sometimes', 'string', 'max:200'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('fight_fixture_card_version', 'v3'));
        $previewLeague = trim((string) ($validated['preview_league'] ?? ''));

        $allLeagues = $this->allSportLeagues('fight_league_ids', $cards, $provider, 'COMBAT_OTHER', $cardVersion, $previewLeague);

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'league_card_preview' => $this->leagueCardPreview($summary, $cards, $cardVersion),
            'all_fight_leagues' => $allLeagues,
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
                'route_key' => TelegramRouteKeys::COMBAT_OTHER,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::COMBAT_OTHER,
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
        TheSportsDbClient $provider,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
            'preview_league' => ['sometimes', 'string', 'max:200'],
        ]);
        $preview = $publisher->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? $settings->get('formula_1_fixture_card_version', 'v3'));
        $previewLeague = trim((string) ($validated['preview_league'] ?? ''));

        $allLeagues = $this->allSportLeagues('formula_1_league_ids', $cards, $provider, 'FORMULA_1', $cardVersion, $previewLeague);

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'league_card_preview' => $this->leagueCardPreview($summary, $cards, $cardVersion),
            'all_motorsport_leagues' => $allLeagues,
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
                'route_key' => TelegramRouteKeys::FORMULA_1,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'route_key' => TelegramRouteKeys::FORMULA_1,
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
            $config['default_card_version'] ?? 'v3'
        ));

        return response()->json(array_merge($preview, [
            'card_previews' => $this->fixtureCardPreviews($summary, $cards, $cardVersion),
            'league_card_preview' => $this->leagueCardPreview($summary, $cards, $cardVersion),
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

    public function highlightsPreview(
        Request $request,
        HighlightsContentModule $module,
        SportsBotCardRenderer $cards,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
            'render_cards' => ['sometimes'],
        ]);
        $cardVersion = $this->footballFixtureCardVersion($validated['card_version'] ?? 'v3');
        $summary = $module->buildSummary();
        $cards = !empty($validated['render_cards']) && filter_var($validated['render_cards'], FILTER_VALIDATE_BOOLEAN) ? $module->renderCards($summary, $cardVersion) : [];

        return response()->json([
            'summary' => $summary,
            'card_previews' => $cards,
            'card_version' => $cardVersion,
        ]);
    }

    public function highlightsSend(
        Request $request,
        HighlightsContentModule $module,
        SportsBotCardRenderer $cards,
        SportsBotNotifier $notifier,
    ): JsonResponse
    {
        $validated = $request->validate([
            'card_version' => ['sometimes', 'string', 'in:v1,v2,v3'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $summary = $module->buildSummary();
            $results = [];
            $limit = min((int) ($validated['limit'] ?? 10), 10);
            $selected = array_slice($summary['highlights'] ?? [], 0, $limit);
            $sentEventIds = [];

            if ($selected === []) {
                Log::info('sportsbot.admin.highlights.no_eligible_highlights', [
                    'provider_total' => (int) ($summary['provider_total'] ?? 0),
                    'filtered_out_total' => (int) ($summary['filtered_out_total'] ?? 0),
                    'already_sent_total' => (int) ($summary['already_sent_total'] ?? 0),
                ]);

                return response()->json([
                    'sent' => false,
                    'no_eligible_highlights' => true,
                    'total' => 0,
                    'results' => [],
                    'summary' => $summary,
                ]);
            }

            $leagueGroups = [];
            foreach ($selected as $h) {
                $league = $h['league'] ?? 'Other';
                $leagueGroups[$league][] = $h;
            }

            $currentLeague = null;
            foreach ($selected as $h) {
                $league = $h['league'] ?? 'Other';

                if ($league !== $currentLeague) {
                    $currentLeague = $league;
                    try {
                        $leagueInfo = [
                            'name' => $league,
                            'sport' => SportsBotSports::providerSport($h['sport_key'] ?? 'football'),
                            'badge' => $h['league_badge'] ?? '',
                            'logo' => $h['league_logo'] ?? '',
                            'date' => now()->format('j M Y'),
                        ];
                        $headerCard = $cards->leagueCard($leagueInfo, $validated['card_version'] ?? 'v3', [
                            'route_key' => TelegramRouteKeys::HIGHLIGHTS,
                        ]);
                        $headerPath = (string) ($headerCard['path'] ?? '');
                        if ($headerPath !== '' && is_file($headerPath)) {
                            foreach ($notifier->sendPhoto($headerPath, '', ['route_key' => TelegramRouteKeys::HIGHLIGHTS]) as $r) {
                                $results[] = $r;
                            }
                        }
                    } catch (Throwable) {
                    }
                }

                $fixture = [
                    'event_name' => $h['event_name'],
                    'home_team' => $h['home_team'],
                    'away_team' => $h['away_team'],
                    'home_score' => $h['home_score'],
                    'away_score' => $h['away_score'],
                    'score' => $h['score'],
                    'league' => $league,
                    'sport' => SportsBotSports::providerSport($h['sport_key']),
                    'event_thumb' => $h['thumb'],
                    'home_badge' => $h['home_badge'],
                    'away_badge' => $h['away_badge'],
                    'league_badge' => $h['league_badge'],
                    'sport_key' => $h['sport_key'],
                    'dateEvent' => $h['date'],
                    'date_label' => $h['date'],
                    'result_status' => 'Full Time',
                    'video_url' => $h['video_url'],
                    'background_image' => $h['thumb'],
                ];

                $eventId = $h['event_id'] ?? '';
                if ($eventId !== '') {
                    try {
                        $provider = app(\App\Plugins\SportsBot\Services\TheSportsDbClient::class);
                        $statRows = $provider->lookupEventStats($eventId);
                        $hasValues = false;
                        foreach ($statRows as $s) {
                            if (trim((string) ($s['strHome'] ?? '')) !== '' && trim((string) ($s['strHome'] ?? '')) !== '?') {
                                $hasValues = true;
                                break;
                            }
                        }
                        if ($hasValues) {
                            $stats = [];
                            foreach ($statRows as $s) {
                                $name = trim((string) ($s['strStat'] ?? ''));
                                $home = trim((string) ($s['strHome'] ?? ''));
                                $away = trim((string) ($s['strAway'] ?? ''));
                                if ($name !== '') {
                                    $key = strtolower(str_replace([' ', '%', '.', '-'], '_', $name));
                                    $stats[$key] = ['home' => $home, 'away' => $away];
                                }
                            }
                            $fixture['event_stats'] = $stats;
                        } else {
                            $fixture['event_stats'] = $this->scrapeStats($eventId, $h['event_name'] ?? '');
                        }
                    } catch (Throwable) {
                        $fixture['event_stats'] = $this->scrapeStats($eventId, $h['event_name'] ?? '');
                    }
                }

                try {
                    $card = $cards->fixtureCard($fixture, $validated['card_version'] ?? 'v3', [
                        'route_key' => TelegramRouteKeys::HIGHLIGHTS,
                        'kind' => 'result',
                    ]);
                    $path = (string) ($card['path'] ?? '');
                    if ($path !== '' && is_file($path)) {
                        $sportKey = $h['sport_key'] ?? 'football';
                        $watchLabel = $this->watchLabel($sportKey, $fixture);
                        $videoUrl = $h['video_url'];
                        $idempotencyKey = $this->highlightIdempotencyKey((string) $eventId, TelegramRouteKeys::HIGHLIGHTS);

                        $sendOptions = [
                            'route_key' => TelegramRouteKeys::HIGHLIGHTS,
                            'type' => 'HIGHLIGHTS',
                            'idempotency_key' => $idempotencyKey,
                            'parse_mode' => 'HTML',
                            'embed_color' => $this->embedColor($sportKey),
                            'embed_footer' => $league,
                            'payload' => [
                                'source' => 'admin_api',
                                'content_key' => 'HIGHLIGHTS',
                                'idempotency_key' => $idempotencyKey,
                                'event_id' => (string) $eventId,
                                'fixture_queue_id' => $h['fixture_queue_id'] ?? null,
                            ],
                        ];

                        if (trim((string) $videoUrl) !== '') {
                            $sendOptions['reply_markup'] = [
                                'inline_keyboard' => [[
                                    ['text' => $watchLabel, 'url' => $videoUrl],
                                ]],
                            ];
                            $sendOptions['embed_url'] = $videoUrl;
                            $sendOptions['embed_title'] = $watchLabel;
                        }

                        $sendResults = $notifier->sendPhoto($path, '', $sendOptions);
                        $sent = false;
                        foreach ($sendResults as $r) {
                            $results[] = $r;
                            if (empty($r['error'])) {
                                $sent = true;
                            }
                        }
                        if ($sent && $eventId !== '') {
                            $sentEventIds[(string) $eventId] = true;
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            foreach (array_keys($sentEventIds) as $eid) {
                SportsBotHighlightSent::query()->upsert(
                    ['event_id' => $eid, 'sent_at' => now()],
                    'event_id',
                    ['sent_at' => now()]
                );
            }

            return response()->json([
                'sent' => $results !== [],
                'total' => count($results),
                'highlight_count' => count($sentEventIds),
                'results' => $results,
            ]);
        } catch (Throwable $error) {
            return response()->json([
                'sent' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    private function highlightIdempotencyKey(string $eventId, string $routeKey): string
    {
        return mb_substr('highlight:' . $eventId . ':' . TelegramRouteKeys::normalize($routeKey), 0, 160);
    }

    private function watchLabel(string $sportKey, array $fixture): string
    {
        if (($fixture['league'] ?? '') === 'WWE') {
            return '▶ Watch Highlights';
        }
        return match ($sportKey) {
            'formula_1', 'motorsport' => '▶ Watch Race Highlights',
            'fights', 'boxing', 'mma' => '▶ Watch Fight Highlights',
            'rugby' => '▶ Watch Match Highlights',
            'basketball' => '▶ Watch Game Highlights',
            'baseball' => '▶ Watch Game Highlights',
            'american_football' => '▶ Watch Game Highlights',
            'tennis' => '▶ Watch Match Highlights',
            default => '▶ Watch Highlights',
        };
    }

    private function scrapeStats(string $eventId, string $eventName): array
    {
        $cacheKey = 'sportsbot:scraped_stats:' . $eventId;
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $eventName), '-'));
            $url = "https://www.thesportsdb.com/event/{$eventId}-{$slug}";
            $html = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'])
                ->get($url)
                ->body();

            preg_match_all('/<h5>([^<]+)<\/h5><div[^>]+container2[^>]*><div[^>]+score-left[^>]+>([^<]+)<\/div>.*?<div[^>]+score-right[^>]+>([^<]+)<\/div>/s', $html, $matches);
            $stats = [];
            for ($i = 0; $i < count($matches[1]); $i++) {
                $name = trim($matches[1][$i]);
                $home = trim($matches[2][$i]);
                $away = trim($matches[3][$i]);
                if ($name !== '') {
                    $key = strtolower(str_replace([' ', '%', '.', '-'], '_', $name));
                    $stats[$key] = ['home' => $home, 'away' => $away];
                }
            }

            \Illuminate\Support\Facades\Cache::put($cacheKey, $stats, now()->addDays(7));
            return $stats;
        } catch (Throwable) {
            return [];
        }
    }

    private function embedColor(string $sportKey): int
    {
        return match ($sportKey) {
            'football' => 10181043,
            'rugby' => 5763712,
            'fights', 'boxing', 'mma' => 16766720,
            'formula_1', 'motorsport' => 16733525,
            'basketball' => 16750592,
            'baseball' => 3722752,
            'american_football' => 16753920,
            'ice_hockey' => 6323584,
            'tennis' => 10070780,
            'cricket' => 5763712,
            default => 10181043,
        };
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
            'renderer' => [
                'primary' => (bool) config('plugins.SportsBot.cards.v3_browser_enabled', true) ? 'browser_v3' : 'gd_v3',
                'gd_fallback_enabled' => (bool) config('plugins.SportsBot.cards.gd_fallback_enabled', true),
                'allow_gd_fallback_publish' => (bool) app(SportsBotSettingsService::class)->get('fixture_queue_allow_gd_fallback_publish', config('plugins.SportsBot.publishing.fixture_queue.allow_gd_fallback_publish', false)),
                'browser_timeout' => (int) config('plugins.SportsBot.cards.browser_timeout', 15),
                'browser_concurrency' => (int) config('plugins.SportsBot.cards.browser_concurrency', 2),
            ],
            'templates' => app(CardTemplateRegistry::class)->catalog(),
            'asset_cache' => app(SportsBotAssetCache::class)->diagnostics(),
            'recent_items' => SportsBotFixtureQueue::query()
                ->latest('updated_at')
                ->limit(500)
                ->get()
                ->map(fn (SportsBotFixtureQueue $entry): array => $queue->itemData($entry))
                ->values()
                ->all(),
        ]);
    }

    public function epgProvider(SportsBotEpgGrabberRuntime $grabbers, SportsBotEpgHealthService $health, SportsBotEpgRuntimeLock $lock): JsonResponse
    {
        if (! Schema::hasTable('sportsbot_epg_sources')) {
            return response()->json([
                'ready' => false,
                'error' => 'EPG provider tables have not been migrated yet.',
            ], 503);
        }

        $programmeCount = Schema::hasTable('sportsbot_xmltv_programmes') ? SportsBotXmltvProgramme::query()->count() : 0;
        $channelCount = Schema::hasTable('sportsbot_xmltv_programmes')
            ? SportsBotXmltvProgramme::query()->whereNotNull('canonical_channel_id')->distinct('canonical_channel_id')->count('canonical_channel_id')
            : 0;
        $futureProgrammeCount = Schema::hasTable('sportsbot_xmltv_programmes') ? SportsBotXmltvProgramme::query()->where('start_time', '>=', now())->count() : 0;
        $reviewCount = SportsBotEpgFixtureMatch::query()->where('status', 'needs_review')->count();
        $autoCount = SportsBotEpgFixtureMatch::query()->whereIn('status', ['auto_applied', 'accepted'])->count();
        $fixtureWindowTotal = SportsBotFixtureQueue::query()
            ->whereBetween('publish_date', [Carbon::today()->subDay()->toDateString(), Carbon::today()->addDays(3)->toDateString()])
            ->count();

        return response()->json([
            'ready' => true,
            'summary' => [
                'source_count' => SportsBotEpgSource::query()->count(),
                'working_sources' => SportsBotEpgSource::query()->where('status', 'working')->count(),
                'stale_sources' => SportsBotEpgSource::query()->where('stale', true)->count(),
                'blocked_sources' => SportsBotEpgSource::query()->where('status', 'blocked')->count(),
                'empty_sources' => SportsBotEpgSource::query()->where('status', 'empty')->count(),
                'programme_count' => $programmeCount,
                'future_programme_count' => $futureProgrammeCount,
                'canonical_channel_count' => $channelCount,
                'auto_match_count' => $autoCount,
                'review_match_count' => $reviewCount,
                'fixture_window_total' => $fixtureWindowTotal,
                'match_rate' => $fixtureWindowTotal > 0 ? round($autoCount / $fixtureWindowTotal, 2) : 0,
            ],
            'sources' => SportsBotEpgSource::query()
                ->orderBy('enabled', 'desc')
                ->orderBy('priority')
                ->orderBy('id')
                ->limit(100)
                ->get(),
            'recent_runs' => SportsBotEpgImportRun::query()
                ->latest('id')
                ->limit(25)
                ->get(),
            'grabbers' => Schema::hasTable('sportsbot_epg_grabbers')
                ? SportsBotEpgGrabber::query()->latest('updated_at')->limit(100)->get()
                : [],
            'recent_grabber_runs' => Schema::hasTable('sportsbot_epg_grabber_runs')
                ? SportsBotEpgGrabberRun::query()->latest('id')->limit(25)->get()
                : [],
            'review_matches' => SportsBotEpgFixtureMatch::query()
                ->where('status', 'needs_review')
                ->latest('id')
                ->limit(50)
                ->get(),
            'performance_health' => [
                'runtime_lock' => $lock->status(),
                'last_import' => SportsBotEpgImportRun::query()->latest('id')->first(),
                'skipped_unchanged_24h' => SportsBotEpgImportRun::query()
                    ->where('status', 'skipped_unchanged')
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'import_chunk_size' => (int) config('plugins.SportsBot.epg.import_chunk_size', 2000),
                'max_programmes' => (int) config('plugins.SportsBot.epg.max_programmes', 80000),
                'source_policy' => (string) config('plugins.SportsBot.epg.source_policy', 'uk_sports_first'),
            ],
            'health' => $health->snapshot(),
            'missing_channels' => $grabbers->missingUkSportsChannels(),
            'export_health' => [
                'token_configured' => trim((string) app(SportsBotSettingsService::class)->get('epg_export_token', config('plugins.SportsBot.epg.export_token', ''))) !== '',
                'xml_path' => storage_path('app/sportsbot/epg/guide.xml'),
                'json_path' => storage_path('app/sportsbot/epg/guide.json'),
                'xml_exists' => is_file(storage_path('app/sportsbot/epg/guide.xml')),
                'json_exists' => is_file(storage_path('app/sportsbot/epg/guide.json')),
                'last_export_at' => is_file(storage_path('app/sportsbot/epg/guide.xml'))
                    ? Carbon::createFromTimestamp(filemtime(storage_path('app/sportsbot/epg/guide.xml')))->toIso8601String()
                    : null,
            ],
        ]);
    }

    public function epgProviderGuide(Request $request, SportsBotEpgGuideService $guide): JsonResponse
    {
        if (! Schema::hasTable('sportsbot_xmltv_programmes')) {
            return response()->json([
                'ready' => false,
                'error' => 'EPG programme tables have not been migrated yet.',
            ], 503);
        }

        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'region' => ['sometimes', 'nullable', 'string', 'max:40'],
            'uk_sports' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'channel_limit' => ['sometimes', 'integer', 'min:25', 'max:1000'],
        ]);

        return response()->json($guide->day(
            (string) ($validated['date'] ?? Carbon::today()->toDateString()),
            [
                'region' => (string) ($validated['region'] ?? config('plugins.SportsBot.epg.default_region', 'UK')),
                'uk_sports' => (bool) ($validated['uk_sports'] ?? false),
                'search' => (string) ($validated['search'] ?? ''),
                'channel_limit' => (int) ($validated['channel_limit'] ?? 400),
            ],
        ));
    }

    public function epgProviderImport(Request $request, SportsBotEpgSourceImporter $importer, SportsBotEpgMatcher $matcher, SportsBotEpgExporter $exporter, SportsBotEpgRuntimeLock $lock): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'region' => ['sometimes', 'nullable', 'string', 'max:40'],
            'source_limit' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_programmes' => ['sometimes', 'integer', 'min:0', 'max:500000'],
            'chunk_size' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'skip_unchanged' => ['sometimes', 'boolean'],
            'match' => ['sometimes', 'boolean'],
            'export' => ['sometimes', 'boolean'],
        ]);

        $days = (int) ($validated['days'] ?? 3);
        $result = $lock->run('admin-epg-import', function () use ($importer, $matcher, $exporter, $validated, $days): array {
            $result = ['import' => $importer->importAll([], $days, [
                'region' => (string) ($validated['region'] ?? config('plugins.SportsBot.epg.default_region', 'UK')),
                'source_limit' => (int) ($validated['source_limit'] ?? 0),
                'max_programmes' => (int) ($validated['max_programmes'] ?? config('plugins.SportsBot.epg.max_programmes', 80000)),
                'chunk_size' => (int) ($validated['chunk_size'] ?? config('plugins.SportsBot.epg.import_chunk_size', 2000)),
                'skip_unchanged' => (bool) ($validated['skip_unchanged'] ?? true),
            ])];

            if ((bool) ($validated['match'] ?? true)) {
                $result['match'] = $matcher->matchFixtures($days, 300, true);
            }

            if ((bool) ($validated['export'] ?? true)) {
                $result['export'] = $exporter->writeCachedExports();
            }

            return $result;
        }, 3600);

        return response()->json($result);
    }

    public function epgProviderMatch(Request $request, SportsBotEpgMatcher $matcher, SportsBotEpgRuntimeLock $lock): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'force' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        return response()->json($lock->run('admin-epg-match', fn (): array => $matcher->matchFixtures(
            (int) ($validated['days'] ?? 3),
            (int) ($validated['limit'] ?? 200),
            ! (bool) ($validated['dry_run'] ?? false),
            ['force' => (bool) ($validated['force'] ?? false)],
        ), 1800));
    }

    public function epgProviderExport(Request $request, SportsBotEpgExporter $exporter, SportsBotEpgRuntimeLock $lock): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:336'],
        ]);

        return response()->json($lock->run('admin-epg-export', fn (): array => $exporter->writeCachedExports((int) ($validated['hours'] ?? 72)), 900));
    }

    public function epgProviderDiscoverGrabbers(Request $request, SportsBotEpgGrabberRuntime $grabbers): JsonResponse
    {
        $validated = $request->validate([
            'region' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        return response()->json($grabbers->discover((string) ($validated['region'] ?? config('plugins.SportsBot.epg.default_region', 'UK'))));
    }

    public function epgProviderRunGrabbers(Request $request, SportsBotEpgGrabberRuntime $grabbers, SportsBotEpgRuntimeLock $lock): JsonResponse
    {
        $validated = $request->validate([
            'region' => ['sometimes', 'nullable', 'string', 'max:40'],
            'only' => ['sometimes', 'nullable', 'string', 'max:255'],
            'import' => ['sometimes', 'boolean'],
            'export' => ['sometimes', 'boolean'],
        ]);

        return response()->json($lock->run('admin-epg-grabbers-run', fn (): array => $grabbers->run(
            (string) ($validated['region'] ?? config('plugins.SportsBot.epg.default_region', 'UK')),
            isset($validated['only']) ? (string) $validated['only'] : null,
            (bool) ($validated['import'] ?? true),
            (bool) ($validated['export'] ?? true),
            ['region' => (string) ($validated['region'] ?? config('plugins.SportsBot.epg.default_region', 'UK'))],
        ), 3600));
    }

    public function epgProviderApplyPolicy(SportsBotEpgGrabberRuntime $grabbers): JsonResponse
    {
        return response()->json($grabbers->applyUkSportsPolicy());
    }

    public function epgProviderAcceptMatch(int $id, SportsBotEpgMatcher $matcher): JsonResponse
    {
        $match = SportsBotEpgFixtureMatch::query()->find($id);
        if (! $match) {
            return response()->json(['error' => "EPG match {$id} not found"], 404);
        }

        return response()->json($matcher->acceptMatch($match, auth()->id()));
    }

    public function epgProviderRejectMatch(int $id, SportsBotEpgMatcher $matcher): JsonResponse
    {
        $match = SportsBotEpgFixtureMatch::query()->find($id);
        if (! $match) {
            return response()->json(['error' => "EPG match {$id} not found"], 404);
        }

        return response()->json($matcher->rejectMatch($match, auth()->id()));
    }

    public function fixtureQueuePrefetch(FixtureQueueService $queue): JsonResponse
    {
        try {
            return response()->json($queue->prefetchAll());
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }
    }

    public function fixtureQueueEnrich(Request $request, FixtureQueueService $queue): JsonResponse
    {
        $validated = $request->validate([
            'sport' => ['sometimes', 'nullable', 'string', 'max:60'],
            'days' => ['sometimes', 'integer', 'min:0', 'max:14'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json($queue->enrichQueuedFixtures(
                isset($validated['sport']) && trim((string) $validated['sport']) !== '' ? (string) $validated['sport'] : null,
                (int) ($validated['days'] ?? 2),
                (int) ($validated['limit'] ?? 30),
                (bool) ($validated['force'] ?? false)
            ));
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

    public function fixtureQueuePublish(Request $request, FixtureQueueService $queue): JsonResponse
    {
        try {
            return response()->json($queue->publishAll([
                'dry_run' => $request->boolean('dry_run'),
            ]));
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }
    }

    public function fixtureQueueBulkReRender(Request $request, FixtureQueueService $queue): JsonResponse
    {
        $ids = $this->fixtureQueueBulkIds($request);
        $results = [];

        foreach ($ids as $id) {
            $results[$id] = $queue->reRenderItem($id);
        }

        return response()->json(['count' => count($ids), 'results' => $results]);
    }

    public function fixtureQueueBulkRepublish(Request $request, FixtureQueueService $queue): JsonResponse
    {
        $ids = $this->fixtureQueueBulkIds($request);
        $results = [];

        foreach ($ids as $id) {
            $results[$id] = $queue->publishNow($id, ['force' => true]);
        }

        return response()->json(['count' => count($ids), 'results' => $results]);
    }

    public function fixtureQueueRegenerateAssets(Request $request, FixtureQueueService $queue): JsonResponse
    {
        $ids = $this->fixtureQueueBulkIds($request);
        $results = [];

        foreach ($ids as $id) {
            $item = $queue->find($id);
            if (!$item) {
                $results[$id] = ['error' => "Queue item {$id} not found"];
                continue;
            }

            $item->asset_status = SportsBotFixtureQueue::ASSET_PENDING;
            $item->asset_failures = [];
            $item->save();
            $results[$id] = $queue->reRenderItem($id);
        }

        return response()->json(['count' => count($ids), 'results' => $results]);
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

    public function fixtureQueuePublishNow(int $id, Request $request, FixtureQueueService $queue): JsonResponse
    {
        return response()->json($queue->publishNow($id, [
            'force' => $request->boolean('force'),
        ]));
    }

    public function fixtureQueueRenderOptions(int $id, Request $request, FixtureQueueService $queue): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['sometimes', 'nullable', 'string', 'max:80'],
            'theme' => ['sometimes', 'nullable', 'string', 'max:80'],
            'card_version' => ['sometimes', 'nullable', 'string', 'in:v1,v2,v3'],
            'manual_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'custom_poster_url' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'custom_background_url' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'rerender' => ['sometimes', 'boolean'],
        ]);

        $result = $queue->updateRenderOptions($id, $validated);
        if (($result['updated'] ?? false) && $request->boolean('rerender', true)) {
            $result['render'] = $queue->reRenderItem($id);
        }

        return response()->json($result);
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

    /**
     * @return array<int, int>
     */
    private function fixtureQueueBulkIds(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return array_values(array_unique(array_map('intval', $validated['ids'])));
    }

    public function fixtureQueueCard(int $id, FixtureQueueService $queue): mixed
    {
        $item = $queue->find($id);
        $cardPath = SportsBotPaths::cardPath($item?->card_path);
        if (!$item || $cardPath === '' || !@is_file($cardPath)) {
            abort(404);
        }

        return response()->file($cardPath);
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
    private function fixtureCardPreviews(array $summary, SportsBotCardRenderer $cards, string $cardVersion = 'v3'): array
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
                        'route_key' => (string) ($fixture['route_key'] ?? ''),
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

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    private function leagueCardPreview(array $summary, SportsBotCardRenderer $cards, string $cardVersion = 'v3', string $previewLeague = ''): ?array
    {
        $cardVersion = $this->footballFixtureCardVersion($cardVersion);
        $leagues = [];
        $seen = [];
        $previewCard = null;

        foreach ((array) ($summary['grouped'] ?? []) as $fixtures) {
            foreach ((array) $fixtures as $fixture) {
                if (!is_array($fixture)) {
                    continue;
                }

                $leagueName = trim((string) ($fixture['league'] ?? $fixture['strLeague'] ?? ''));
                if ($leagueName === '' || isset($seen[$leagueName])) {
                    continue;
                }

                $seen[$leagueName] = true;
                $leagues[] = $leagueName;

                $isTargetLeague = $previewLeague !== '' && strcasecmp($leagueName, $previewLeague) === 0;
                $isFirstUnrequested = $previewLeague === '' && $previewCard === null;

                if ($isTargetLeague || $isFirstUnrequested) {
                    try {
                        $date = trim((string) ($fixture['date'] ?? $fixture['date_label'] ?? $fixture['dateEvent'] ?? ''));
                        $formattedDate = $date !== '' ? $date : now()->format('j M Y');

                        $leagueInfo = [
                            'name' => $leagueName,
                            'sport' => (string) ($fixture['sport'] ?? $fixture['strSport'] ?? $fixture['sport_key'] ?? ''),
                            'badge' => (string) ($fixture['league_badge'] ?? $fixture['strLeagueBadge'] ?? ''),
                            'logo' => (string) ($fixture['league_logo'] ?? $fixture['strLeagueLogo'] ?? ''),
                            'date' => $formattedDate,
                        ];

                        $card = $cards->leagueCard($leagueInfo, $cardVersion);
                        $path = (string) ($card['path'] ?? '');
                        if ($path !== '' && is_file($path)) {
                            $previewCard = [
                                'name' => $leagueName,
                                'data_url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)),
                            ];
                        }

                        if ($isTargetLeague) {
                            break 2;
                        }
                    } catch (Throwable $error) {
                        Log::warning('sportsbot.admin.league_card_preview_failed', [
                            'error' => $error->getMessage(),
                        ]);
                    }
                }
            }
        }

        if ($leagues === []) {
            return null;
        }

        if ($previewLeague !== '' && $previewCard === null) {
            $previewCard = [
                'name' => $previewLeague,
                'data_url' => null,
                'no_fixtures' => true,
            ];
        }

        return [
            'card' => $previewCard,
            'leagues' => $leagues,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allFootballLeagues(SportsBotCardRenderer $cards, TheSportsDbClient $provider, SportsBotSettingsService $settings, string $cardVersion = 'v3', string $previewLeague = ''): array
    {
        $cardVersion = $this->footballFixtureCardVersion($cardVersion);

        $defaultIds = array_map('strval', (array) config('plugins.SportsBot.fixtures_today.default_league_ids', []));
        $featuredIds = $settings->get('featured_league_ids', []);
        $allowedIds = (array) config('plugins.SportsBot.coverage.allowed_league_ids', []);
        $leagueIds = $featuredIds
            ? array_values(array_unique(array_merge($defaultIds, $featuredIds)))
            : ($allowedIds ?: $defaultIds);
        $leagueIds = array_values(array_unique(array_filter(array_map('strval', $leagueIds), fn ($id) => trim($id) !== '')));

        $cacheDir = storage_path('app/sportsbot/league-headers');
        if (!@is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !@is_dir($cacheDir)) {
            $cacheDir = null;
        }

        $metaCachePath = $cacheDir ? $cacheDir . '/league-meta.json' : null;
        $metaCache = [];
        if ($metaCachePath !== null && @is_file($metaCachePath)) {
            $metaCache = json_decode((string) file_get_contents($metaCachePath), true) ?? [];
        }

        $leagues = [];
        $needMetaSave = false;

        foreach ($leagueIds as $id) {
            $name = $metaCache[$id]['name'] ?? '';

            if ($name === '') {
                try {
                    $league = $provider->lookupLeague($id);
                    if ($league !== null && is_array($league)) {
                        $name = trim((string) ($league['strLeague'] ?? ''));
                        if ($name !== '') {
                            $metaCache[$id] = [
                                'name' => $name,
                                'badge' => trim((string) ($league['strBadge'] ?? $league['strLogo'] ?? '')),
                                'logo' => trim((string) ($league['strLogo'] ?? '')),
                            ];
                            $needMetaSave = true;
                        }
                    }
                } catch (Throwable $error) {
                    Log::debug('sportsbot.admin.league_meta_lookup_failed', ['league_id' => $id, 'error' => $error->getMessage()]);
                    continue;
                }
            }

            if ($name === '') {
                continue;
            }

            $cacheImgPath = $cacheDir ? $cacheDir . '/league-header-football-' . $id . '-' . $cardVersion . '.png' : null;
            $hasCache = $cacheImgPath !== null && @is_file($cacheImgPath) && filesize($cacheImgPath) > 0;
            $dataUrl = null;
            $generating = false;

            if ($hasCache) {
                $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($cacheImgPath));
            } elseif ($previewLeague !== '' && strcasecmp($name, $previewLeague) === 0) {
                $generating = true;
                try {
                    $today = now()->format('j M Y');
                    $badge = $metaCache[$id]['badge'] ?? '';
                    $logo = $metaCache[$id]['logo'] ?? '';

                    $leagueInfo = [
                        'name' => $name,
                        'sport' => 'football',
                        'badge' => $badge,
                        'logo' => $logo,
                        'date' => $today,
                    ];

                    $card = $cards->leagueCard($leagueInfo, $cardVersion, ['route_key' => 'FOOTBALL']);
                    $path = (string) ($card['path'] ?? '');
                    if ($path !== '' && is_file($path)) {
                        if ($cacheImgPath !== null) {
                            @copy($path, $cacheImgPath);
                        }
                        $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
                        $hasCache = true;
                    }
                } catch (Throwable $error) {
                    Log::warning('sportsbot.admin.league_header_generate_failed', ['league_id' => $id, 'error' => $error->getMessage()]);
                }
            }

            $leagues[] = [
                'name' => $name,
                'league_id' => $id,
                'has_cache' => $hasCache,
                'generating' => $generating,
                'data_url' => $dataUrl,
            ];
        }

        if ($needMetaSave && $metaCachePath !== null) {
            @file_put_contents($metaCachePath, json_encode($metaCache, JSON_UNESCAPED_UNICODE));
        }

        return $leagues;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allRugbyLeagues(SportsBotCardRenderer $cards, TheSportsDbClient $provider, string $cardVersion = 'v3', string $previewLeague = ''): array
    {
        $leagueIds = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) config('plugins.SportsBot.fixtures_today.rugby_league_ids', [])
        ), fn ($id) => trim($id) !== '')));

        $cacheDir = storage_path('app/sportsbot/league-headers');
        if (!@is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !@is_dir($cacheDir)) {
            $cacheDir = null;
        }

        $metaCachePath = $cacheDir ? $cacheDir . '/league-meta.json' : null;
        $metaCache = [];
        if ($metaCachePath !== null && @is_file($metaCachePath)) {
            $metaCache = json_decode((string) file_get_contents($metaCachePath), true) ?? [];
        }

        $leagues = [];
        $needMetaSave = false;

        foreach ($leagueIds as $id) {
            $name = $metaCache[$id]['name'] ?? '';

            if ($name === '') {
                try {
                    $league = $provider->lookupLeague($id);
                    if ($league !== null && is_array($league)) {
                        $name = trim((string) ($league['strLeague'] ?? ''));
                        if ($name !== '') {
                            $metaCache[$id] = [
                                'name' => $name,
                                'badge' => trim((string) ($league['strBadge'] ?? $league['strLogo'] ?? '')),
                                'logo' => trim((string) ($league['strLogo'] ?? '')),
                            ];
                            $needMetaSave = true;
                        }
                    }
                } catch (Throwable $error) {
                    Log::debug('sportsbot.admin.league_meta_lookup_failed', ['league_id' => $id, 'error' => $error->getMessage()]);
                    continue;
                }
            }

            if ($name === '') {
                continue;
            }

            $cacheImgPath = $cacheDir ? $cacheDir . '/league-header-rugby-' . $id . '-' . $cardVersion . '.png' : null;
            $hasCache = $cacheImgPath !== null && @is_file($cacheImgPath) && filesize($cacheImgPath) > 0;
            $dataUrl = null;
            $generating = false;

            if ($hasCache) {
                $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($cacheImgPath));
            } elseif ($previewLeague !== '' && strcasecmp($name, $previewLeague) === 0) {
                $generating = true;
                try {
                    $today = now()->format('j M Y');
                    $badge = $metaCache[$id]['badge'] ?? '';
                    $logo = $metaCache[$id]['logo'] ?? '';

                    $leagueInfo = [
                        'name' => $name,
                        'sport' => 'rugby',
                        'badge' => $badge,
                        'logo' => $logo,
                        'date' => $today,
                    ];

                    $card = $cards->leagueCard($leagueInfo, $cardVersion, ['route_key' => 'RUGBY']);
                    $path = (string) ($card['path'] ?? '');
                    if ($path !== '' && is_file($path)) {
                        if ($cacheImgPath !== null) {
                            @copy($path, $cacheImgPath);
                        }
                        $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
                        $hasCache = true;
                    }
                } catch (Throwable $error) {
                    Log::warning('sportsbot.admin.league_header_generate_failed', ['league_id' => $id, 'error' => $error->getMessage()]);
                }
            }

            $leagues[] = [
                'name' => $name,
                'league_id' => $id,
                'has_cache' => $hasCache,
                'generating' => $generating,
                'data_url' => $dataUrl,
            ];
        }

        if ($needMetaSave && $metaCachePath !== null) {
            @file_put_contents($metaCachePath, json_encode($metaCache, JSON_UNESCAPED_UNICODE));
        }

        return $leagues;
    }

    private function footballFixtureCardVersion(mixed $version): string
    {
        $version = strtolower(trim((string) $version));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allSportLeagues(string $configKey, SportsBotCardRenderer $cards, TheSportsDbClient $provider, string $routeKey, string $cardVersion = 'v3', string $previewLeague = ''): array
    {
        $leagueIds = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) config('plugins.SportsBot.fixtures_today.' . $configKey, [])
        ), fn ($id) => trim($id) !== '')));

        $cacheDir = storage_path('app/sportsbot/league-headers');
        if (!@is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !@is_dir($cacheDir)) {
            $cacheDir = null;
        }

        $metaCachePath = $cacheDir ? $cacheDir . '/league-meta.json' : null;
        $metaCache = [];
        if ($metaCachePath !== null && @is_file($metaCachePath)) {
            $metaCache = json_decode((string) file_get_contents($metaCachePath), true) ?? [];
        }

        $leagues = [];
        $needMetaSave = false;

        foreach ($leagueIds as $id) {
            $name = $metaCache[$id]['name'] ?? '';
            if ($name === '') {
                try {
                    $league = $provider->lookupLeague($id);
                    if ($league !== null && is_array($league)) {
                        $name = trim((string) ($league['strLeague'] ?? ''));
                        if ($name !== '') {
                            $metaCache[$id] = [
                                'name' => $name,
                                'badge' => trim((string) ($league['strBadge'] ?? $league['strLogo'] ?? '')),
                                'logo' => trim((string) ($league['strLogo'] ?? '')),
                            ];
                            $needMetaSave = true;
                        }
                    }
                } catch (Throwable $error) {
                    Log::debug('sportsbot.admin.league_meta_lookup_failed', ['league_id' => $id, 'error' => $error->getMessage()]);
                    continue;
                }
            }
            if ($name === '') {
                continue;
            }

            $cacheImgPath = $cacheDir ? $cacheDir . '/league-header-' . $configKey . '-' . $id . '-' . $cardVersion . '.png' : null;
            $hasCache = $cacheImgPath !== null && @is_file($cacheImgPath) && filesize($cacheImgPath) > 0;
            $dataUrl = null;
            $generating = false;

            if ($hasCache) {
                $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($cacheImgPath));
            } elseif ($previewLeague !== '' && strcasecmp($name, $previewLeague) === 0) {
                $generating = true;
                try {
                    $today = now()->format('j M Y');
                    $badge = $metaCache[$id]['badge'] ?? '';
                    $logo = $metaCache[$id]['logo'] ?? '';
                    $leagueInfo = [
                        'name' => $name,
                        'sport' => $routeKey,
                        'badge' => $badge,
                        'logo' => $logo,
                        'date' => $today,
                    ];
                    $card = $cards->leagueCard($leagueInfo, $cardVersion, ['route_key' => $routeKey]);
                    $path = (string) ($card['path'] ?? '');
                    if ($path !== '' && is_file($path)) {
                        if ($cacheImgPath !== null) {
                            @copy($path, $cacheImgPath);
                        }
                        $dataUrl = 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
                        $hasCache = true;
                    }
                } catch (Throwable $error) {
                    Log::warning('sportsbot.admin.league_header_generate_failed', ['league_id' => $id, 'error' => $error->getMessage()]);
                }
            }

            $leagues[] = [
                'name' => $name,
                'league_id' => $id,
                'has_cache' => $hasCache,
                'generating' => $generating,
                'data_url' => $dataUrl,
            ];
        }

        if ($needMetaSave && $metaCachePath !== null) {
            @file_put_contents($metaCachePath, json_encode($metaCache, JSON_UNESCAPED_UNICODE));
        }

        return $leagues;
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

    public function coverageSettings(SportsBotSettingsService $settings, TelegramRoutingService $routingService): JsonResponse
    {
        $cardDir = storage_path('app/sportsbot/cards');
        $cardFiles = is_dir($cardDir) ? glob($cardDir . '/*.png') ?: [] : [];
        $storedSettings = $settings->all();
        $storedSettings['discord_route_webhooks'] = $this->normalizeDiscordWebhookMapValue(
            $storedSettings['discord_route_webhooks'] ?? config('plugins.SportsBot.discord.route_webhooks', [])
        );

        return response()->json([
            'sports' => SportsBotSports::all(),
            'settings' => array_merge([
                'enabled_sports' => config('plugins.SportsBot.coverage.enabled_sports', []),
                'featured_league_ids' => config('plugins.SportsBot.fixtures_today.default_league_ids', []),
                'tv_channels' => config('plugins.SportsBot.tv.channels', []),
                'cards_enabled' => (bool) config('plugins.SportsBot.cards.enabled', true),
                'rich_cards_enabled' => (bool) config('plugins.SportsBot.features.rich_cards', true),
                'send_messages' => (bool) config('plugins.SportsBot.send_messages', false),
                'discord_enabled' => (bool) config('plugins.SportsBot.discord.enabled', false),
                'discord_default_webhook_url' => (string) config('plugins.SportsBot.discord.default_webhook_url', ''),
                'discord_username' => (string) config('plugins.SportsBot.discord.username', 'SportsBot'),
                'discord_avatar_url' => (string) config('plugins.SportsBot.discord.avatar_url', ''),
                'discord_route_webhooks' => (array) config('plugins.SportsBot.discord.route_webhooks', []),
            ], $storedSettings),
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
            if ($key === 'discord_route_webhooks') {
                $value = $this->normalizeDiscordWebhookMapValue($value);
            }

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

    public function allLeagues(SportsBotSettingsService $settings, TheSportsDbClient $provider): JsonResponse
    {
        $sportToConfigKeys = [
            'football' => ['default_league_ids', 'international_league_ids'],
            'rugby' => ['rugby_league_ids'],
            'fights' => ['fight_league_ids'],
            'motorsports' => ['formula_1_league_ids'],
            'usa_sports' => ['american_football_league_ids', 'ice_hockey_league_ids', 'basketball_league_ids', 'baseball_league_ids'],
            'other_sports' => ['tennis_league_ids', 'cricket_league_ids'],
        ];

        $dbFeaturedIds = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) $settings->get('featured_league_ids', [])
        ))));

        $cacheDir = storage_path('app/sportsbot/league-headers');
        if (!@is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !@is_dir($cacheDir)) {
            $cacheDir = null;
        }
        $metaCachePath = $cacheDir ? $cacheDir . '/league-meta.json' : null;
        $metaCache = [];
        if ($metaCachePath !== null && @is_file($metaCachePath)) {
            $metaCache = json_decode((string) file_get_contents($metaCachePath), true) ?? [];
        }

        $needMetaSave = false;
        $allSports = SportsBotSports::all();
        $result = [];

        $fallbackDefinitions = [
            'motorsports' => ['label' => 'Motorsports', 'sport' => 'Motorsport', 'icon' => '🏁', 'route_key' => TelegramRouteKeys::MOTORSPORT_OTHER],
            'usa_sports' => ['label' => 'USA Sports', 'sport' => 'Basketball', 'icon' => '🏀', 'route_key' => TelegramRouteKeys::USA_SPORTS],
            'other_sports' => ['label' => 'Other Sports', 'sport' => 'Tennis', 'icon' => '🎾', 'route_key' => TelegramRouteKeys::OTHER_SPORTS],
        ];

        foreach ($sportToConfigKeys as $sportKey => $configKeys) {
            $definition = $allSports[$sportKey] ?? $fallbackDefinitions[$sportKey] ?? null;
            if ($definition === null) {
                continue;
            }

            $ids = [];
            foreach ($configKeys as $configKey) {
                $configIds = (array) config('plugins.SportsBot.fixtures_today.' . $configKey, []);
                $ids = array_merge($ids, array_map('strval', $configIds));
            }
            $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => trim($id) !== '')));

            $leagues = [];
            foreach ($ids as $id) {
                $name = $metaCache[$id]['name'] ?? '';
                if ($name === '') {
                    try {
                        $league = $provider->lookupLeague($id);
                        if ($league !== null && is_array($league)) {
                            $name = trim((string) ($league['strLeague'] ?? ''));
                            if ($name !== '') {
                                $metaCache[$id] = [
                                    'name' => $name,
                                    'badge' => trim((string) ($league['strBadge'] ?? $league['strLogo'] ?? '')),
                                    'logo' => trim((string) ($league['strLogo'] ?? '')),
                                ];
                                $needMetaSave = true;
                            }
                        }
                    } catch (Throwable) {
                        continue;
                    }
                }
                if ($name === '') {
                    continue;
                }

                $leagues[] = [
                    'id' => $id,
                    'name' => $name,
                    'badge' => $metaCache[$id]['badge'] ?? '',
                    'logo' => $metaCache[$id]['logo'] ?? '',
                    'featured' => in_array($id, $dbFeaturedIds, true),
                ];
            }

            $result[$sportKey] = [
                'label' => $definition['label'],
                'sport' => $definition['sport'],
                'icon' => $definition['icon'],
                'route_key' => $definition['route_key'],
                'leagues' => $leagues,
            ];
        }

        if ($needMetaSave && $metaCachePath !== null) {
            @file_put_contents($metaCachePath, json_encode($metaCache, JSON_UNESCAPED_UNICODE));
        }

        return response()->json([
            'sports' => $result,
            'featured_ids' => $dbFeaturedIds,
        ]);
    }

    public function lookupLeague(Request $request, TheSportsDbClient $provider): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:100'],
        ]);

        $id = trim((string) ($validated['id'] ?? ''));
        $name = trim((string) ($validated['name'] ?? ''));

        if ($id !== '') {
            $league = $provider->lookupLeague($id);

            if ($league === null || !is_array($league)) {
                return response()->json(['found' => false], 404);
            }

            return response()->json([
                'found' => true,
                'id' => $id,
                'name' => trim((string) ($league['strLeague'] ?? '')),
                'badge' => trim((string) ($league['strBadge'] ?? '')),
                'logo' => trim((string) ($league['strLogo'] ?? '')),
                'sport' => trim((string) ($league['strSport'] ?? '')),
            ]);
        }

        if ($name !== '') {
            $results = $provider->searchLeague($name);

            $leagues = [];
            foreach ($results as $r) {
                $leagues[] = [
                    'id' => trim((string) ($r['idLeague'] ?? '')),
                    'name' => trim((string) ($r['strLeague'] ?? '')),
                    'badge' => trim((string) ($r['strBadge'] ?? '')),
                    'sport' => trim((string) ($r['strSport'] ?? '')),
                    'country' => trim((string) ($r['strCountry'] ?? '')),
                ];
            }

            return response()->json([
                'found' => $leagues !== [],
                'leagues' => $leagues,
                'total' => count($leagues),
            ]);
        }

        return response()->json(['found' => false], 422);
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
            'route_key' => ['sometimes', 'string', 'max:100'],
            'route_keys' => ['sometimes', 'array'],
            'route_keys.*' => ['string', 'max:100'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'chat_id' => ['required', 'string', 'max:255'],
            'message_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
            'fallback' => ['sometimes', 'boolean'],
            'branding' => ['sometimes', 'nullable', 'array'],
            'branding.watermark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.telegram' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.discord' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branding.sponsor_slot' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $routeKeys = $this->routeKeysFromPayload($validated);
        if ($routeKeys === []) {
            return response()->json([
                'saved' => false,
                'error' => 'Choose at least one supported SportsBot route key.',
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

        $routes = [];
        foreach ($routeKeys as $routeKey) {
            $query = SportsBotTelegramRoute::query()
                ->where('route_key', $routeKey)
                ->where('chat_id', $target['chat_id']);

            if ($target['message_thread_id'] === null) {
                $query->whereNull('message_thread_id');
            } else {
                $query->where('message_thread_id', $target['message_thread_id']);
            }

            $route = $query->first() ?? new SportsBotTelegramRoute([
                'route_key' => $routeKey,
                'chat_id' => $target['chat_id'],
                'message_thread_id' => $target['message_thread_id'],
            ]);

            $branding = $this->normalizeBranding($validated['branding'] ?? null);

            $route->fill([
                'label' => trim((string) ($validated['label'] ?? '')) ?: $routeKey,
                'enabled' => (bool) ($validated['enabled'] ?? true),
                'fallback' => (bool) ($validated['fallback'] ?? ($routeKey === TelegramRouteKeys::DEFAULT)),
                'branding' => $branding,
            ]);
            $route->save();
            $routes[] = $route;

            Log::info('sportsbot.admin.telegram_route_saved', [
                'route_key' => $routeKey,
                'chat_id' => $route->chat_id,
                'message_thread_id' => $route->message_thread_id,
                'enabled' => $route->enabled,
                'fallback' => $route->fallback,
            ]);
        }

        return response()->json([
            'saved' => true,
            'route' => $routes[0] ?? null,
            'saved_routes' => $routes,
            'routes' => $this->telegramRoutes(),
            'route_statuses' => $this->routeStatuses($routingService),
        ]);
    }

    public function deleteTelegramRoute(string $routeKey, TelegramRoutingService $routingService): JsonResponse
    {
        $normalized = TelegramRouteKeys::normalize($routeKey);
        $query = SportsBotTelegramRoute::query();

        if (ctype_digit($routeKey)) {
            $query->where('id', (int) $routeKey);
        } else {
            $query->where('route_key', $normalized);
        }

        $query->delete();

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
            'route_key' => ['sometimes', 'string', 'max:100'],
            'route_keys' => ['sometimes', 'array'],
            'route_keys.*' => ['string', 'max:100'],
            'webhook_url' => ['required', 'string', 'max:500'],
        ]);

        $routeKeys = $this->routeKeysFromPayload($validated);
        if ($routeKeys === []) {
            return response()->json([
                'saved' => false,
                'error' => 'Choose at least one supported SportsBot route key.',
            ], 422);
        }

        $webhookUrl = trim((string) $validated['webhook_url']);
        if (!$this->isDiscordWebhookUrl($webhookUrl)) {
            return response()->json([
                'saved' => false,
                'error' => 'Webhook URL must start with https://discord.com/api/webhooks/ or https://discordapp.com/api/webhooks/.',
            ], 422);
        }

        $routes = $this->discordWebhookMap($settings);
        foreach ($routeKeys as $routeKey) {
            if ($routeKey === TelegramRouteKeys::DEFAULT) {
                $settings->set('discord_default_webhook_url', $webhookUrl);
            } else {
                $routes[$routeKey] = $webhookUrl;
            }
        }

        $settings->set('discord_route_webhooks', $routes);

        Log::info('sportsbot.admin.discord_route_saved', [
            'route_keys' => $routeKeys,
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

    public function clearDiscordChannel(Request $request, DiscordNotifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'channel_id' => ['required', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $channelId = (string) $validated['channel_id'];
        $limit = (int) ($validated['limit'] ?? 1000);

        try {
            $result = $notifier->purgeBotMessages($channelId, $limit);

            return response()->json([
                'cleared' => true,
                'channel_id' => $channelId,
                'deleted' => $result['deleted'],
                'total' => $result['total'],
            ]);
        } catch (Throwable $error) {
            return response()->json([
                'cleared' => false,
                'error' => $error->getMessage(),
            ], 422);
        }
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

    public function telegramSettings(SportsBotSettingsService $settings): JsonResponse
    {
        $token = $settings->resolveBotToken();

        return response()->json([
            'bot_token_configured' => $token !== '',
            'bot_token' => $token !== '' ? substr($token, 0, 6) . '...' : '',
            'webhook_enabled' => $settings->resolveWebhookEnabled(),
            'webhook_url' => url(route('sportsbot.telegram.webhook', [], false)),
        ]);
    }

    public function saveTelegramSettings(Request $request, SportsBotSettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:200'],
            'webhook_enabled' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['bot_token'])) {
            $settings->set('telegram_bot_token', $validated['bot_token']);
        }

        if (isset($validated['webhook_enabled'])) {
            $settings->set('telegram_webhook_enabled', $validated['webhook_enabled']);
        }

        Log::info('sportsbot.admin.telegram_settings_saved');

        return response()->json([
            'saved' => true,
            'bot_token_configured' => $settings->resolveBotToken() !== '',
        ]);
    }

    public function telegramWebhookDiagnostics(): JsonResponse
    {
        $settings = app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class);
        $token = trim((string) $settings->resolveBotToken());
        $webhookEnabled = $settings->resolveWebhookEnabled();
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
            'error' => $webhookEnabled ? null : 'Webhook is not enabled.',
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

        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
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

        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
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
            ->orderBy('chat_id')
            ->orderBy('message_thread_id')
            ->get()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function discordSettings(SportsBotSettingsService $settings): array
    {
        $diagnostics = app(DiscordNotifier::class)->diagnostics();

        return [
            'discord_enabled' => (bool) $settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false)),
            'discord_default_webhook_url' => (string) $settings->get('discord_default_webhook_url', config('plugins.SportsBot.discord.default_webhook_url', '')),
            'discord_username' => (string) $settings->get('discord_username', config('plugins.SportsBot.discord.username', 'SportsBot')),
            'discord_avatar_url' => (string) $settings->get('discord_avatar_url', config('plugins.SportsBot.discord.avatar_url', '')),
            'discord_mode' => (string) ($diagnostics['mode'] ?? 'webhook'),
            'discord_bot_token_configured' => (bool) ($diagnostics['bot_token_configured'] ?? false),
            'discord_bot_channel_count' => (int) ($diagnostics['bot_channel_count'] ?? 0),
            'discord_default_bot_channel_configured' => (bool) ($diagnostics['default_bot_channel_configured'] ?? false),
            'discord_bot_channels' => (array) ($diagnostics['bot_channels'] ?? []),
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
        $diagnostics = app(DiscordNotifier::class)->diagnostics();
        if (($diagnostics['mode'] ?? 'webhook') === 'bot') {
            $statuses = [];
            $routeStatuses = (array) ($diagnostics['route_statuses'] ?? []);

            foreach (TelegramRouteKeys::all() as $routeKey) {
                $status = (array) ($routeStatuses[$routeKey] ?? []);
                $source = (string) ($status['source'] ?? 'none');

                $statuses[$routeKey] = [
                    'configured' => (bool) ($status['configured'] ?? false),
                    'enabled' => (bool) ($diagnostics['enabled'] ?? false),
                    'source' => $source,
                    'fallback' => $source === 'bot_default' && $routeKey !== TelegramRouteKeys::DEFAULT,
                    'has_route_webhook' => false,
                    'has_default_webhook' => false,
                    'has_bot_channel' => (bool) ($status['bot_channel_configured'] ?? false),
                    'has_default_bot_channel' => (bool) ($diagnostics['default_bot_channel_configured'] ?? false),
                ];
            }

            return $statuses;
        }

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

        return $this->normalizeDiscordWebhookMapValue($value);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeDiscordWebhookMapValue(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $routes = [];
        $supported = array_fill_keys(TelegramRouteKeys::all(), true);
        $legacy = TelegramRouteKeys::legacyGroupRouteMap();

        foreach ($value as $key => $url) {
            $routeKey = TelegramRouteKeys::normalize((string) $key);
            $url = trim((string) $url);

            if ($url === '' || !$this->isDiscordWebhookUrl($url)) {
                continue;
            }

            if (isset($legacy[$routeKey])) {
                foreach ($legacy[$routeKey] as $expandedRouteKey) {
                    $routes[$expandedRouteKey] ??= $url;
                }

                continue;
            }

            if (isset($supported[$routeKey])) {
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
                'publish_time' => (string) $settings->get('fixture_queue_publish_time', config('plugins.SportsBot.publishing.fixture_queue.publish_time', '00:00')),
            ],
            'highlights' => [
                'enabled' => (bool) $settings->get('highlights_schedule_enabled', config('plugins.SportsBot.publishing.highlights.enabled', true)),
                'frequency' => (string) $settings->get('highlights_schedule_frequency', config('plugins.SportsBot.publishing.highlights.frequency', 'everyThirtyMinutes')),
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
        $scraperError = 0;
        $gdFallback = 0;
        $enrichmentDue = 0;
        $routeFallback = 0;
        $blockedPublish = 0;
        $failedDelivery = 0;
        $routeStatusCache = [];
        $allowGdFallbackPublish = (bool) app(SportsBotSettingsService::class)->get(
            'fixture_queue_allow_gd_fallback_publish',
            config('plugins.SportsBot.publishing.fixture_queue.allow_gd_fallback_publish', false)
        );

        foreach ($windowRows as $row) {
            $fixture = (array) ($row->fixture_data ?? []);
            $payload = (array) ($row->payload ?? []);
            $config = SportsFixtureConfig::for((string) $row->sport_key) ?: [];
            $routeKey = $row->route_key ?: SportsFixtureConfig::routeKeyForFixture((string) $row->sport_key, $fixture);
            if (!isset($routeStatusCache[$routeKey])) {
                try {
                    $routeStatusCache[$routeKey] = app(TelegramRoutingService::class)->resolveTargets($routeKey);
                } catch (Throwable) {
                    $routeStatusCache[$routeKey] = ['fallback' => true, 'target_count' => 0];
                }
            }

            if (!SportsBotFixtureReadiness::hasTv($fixture)) {
                $missingTv++;
            }
            if (trim((string) ($row->card_path ?? '')) === '' && $row->status !== SportsBotFixtureQueue::STATUS_SENT) {
                $missingCard++;
            }
            if (($payload['scraper']['status'] ?? null) === 'found') {
                $scraperFound++;
            }
            if (($payload['scraper']['status'] ?? null) === 'error') {
                $scraperError++;
            }
            if (SportsBotFixtureReadiness::fallbackActive($row)) {
                $gdFallback++;
            }
            if ((SportsBotFixtureReadiness::enrichmentNeeds($row)['enrichment_due'] ?? false) === true) {
                $enrichmentDue++;
            }
            if ((bool) ($routeStatusCache[$routeKey]['fallback'] ?? false)) {
                $routeFallback++;
            }
            if ($row->status === SportsBotFixtureQueue::STATUS_FAILED
                || (trim((string) ($row->card_path ?? '')) === '' && $row->status !== SportsBotFixtureQueue::STATUS_SENT)
                || (!$allowGdFallbackPublish && SportsBotFixtureReadiness::fallbackActive($row) && $this->desiredQueueCardVersion((string) $row->sport_key, $config) === 'v3')
                || (int) ($routeStatusCache[$routeKey]['target_count'] ?? 0) <= 0
            ) {
                $blockedPublish++;
            }
        }

        $failedDelivery = $this->deliveryFailureCountSince(now()->subDay());

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
                'scraper_error' => $scraperError,
                'gd_fallback' => $gdFallback,
                'enrichment_due' => $enrichmentDue,
                'route_fallback' => $routeFallback,
                'blocked_publish' => $blockedPublish,
                'failed_delivery' => $failedDelivery,
            ],
        ];
    }

    private function desiredQueueCardVersion(string $sportKey, array $config): string
    {
        $version = strtolower(trim((string) app(SportsBotSettingsService::class)->get(
            $sportKey . '_fixture_card_version',
            $config['default_card_version'] ?? 'v3'
        )));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
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

    private function deliveryFailureCountSince(Carbon $since): int
    {
        if (!Schema::hasTable('sportsbot_deliveries')) {
            return 0;
        }

        return SportsBotDelivery::query()
            ->where('created_at', '>=', $since)
            ->where('status', 'failed')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineRunStatus(): array
    {
        if (!Schema::hasTable('sportsbot_pipeline_runs')) {
            return [
                'recent' => [],
                'latest_by_stage' => [],
            ];
        }

        $recent = SportsBotPipelineRun::query()
            ->latest('id')
            ->limit(40)
            ->get()
            ->all();

        $latestByStage = [];
        $lastStatusByStage = [];
        foreach ($recent as $run) {
            if (!$run instanceof SportsBotPipelineRun) {
                continue;
            }

            if (!isset($latestByStage[$run->stage])) {
                $latestByStage[$run->stage] = $run;
            }

            $status = (string) $run->status;
            if (!in_array($status, ['success', 'warning', 'failed'], true)) {
                continue;
            }

            $lastStatusByStage[$run->stage] ??= [];
            if (!isset($lastStatusByStage[$run->stage][$status])) {
                $lastStatusByStage[$run->stage][$status] = $run;
            }
        }

        return [
            'recent' => $recent,
            'latest_by_stage' => $latestByStage,
            'last_status_by_stage' => $lastStatusByStage,
        ];
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
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function routeKeysFromPayload(array $payload): array
    {
        $raw = [];

        if (isset($payload['route_keys']) && is_array($payload['route_keys'])) {
            $raw = array_merge($raw, $payload['route_keys']);
        }

        if (isset($payload['route_key'])) {
            $raw[] = $payload['route_key'];
        }

        $supported = array_fill_keys(TelegramRouteKeys::all(), true);
        $routeKeys = [];

        foreach ($raw as $item) {
            $routeKey = TelegramRouteKeys::normalize((string) $item);

            if (isset($supported[$routeKey])) {
                $routeKeys[$routeKey] = $routeKey;
            }
        }

        return array_values($routeKeys);
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

    /**
     * @param array<string, mixed>|null $branding
     * @return array<string, mixed>|null
     */
    private function normalizeBranding(?array $branding): ?array
    {
        if ($branding === null || $branding === []) {
            return null;
        }

        $cleaned = [];

        foreach (['watermark', 'telegram', 'discord', 'sponsor_slot'] as $key) {
            $value = $branding[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $cleaned[$key] = trim($value);
            }
        }

        return $cleaned !== [] ? $cleaned : null;
    }

    public function uptimeSites(): JsonResponse
    {
        $startDate = now()->subDays(29)->startOfDay();

        $sites = SportsBotUptimeSite::query()->with('monitorBot')->orderBy('name')->get()->map(function ($s) use ($startDate) {
            $dailyStatus = [];
            $cursor = $startDate->copy();

            for ($day = 0; $day < 30; $day++) {
                $dayStart = $cursor->copy();
                $dayEnd = $cursor->copy()->endOfDay();

                $failCount = SportsBotUptimeLog::where('site_id', $s->id)
                    ->where('checked_at', '>=', $dayStart)
                    ->where('checked_at', '<=', $dayEnd)
                    ->where('status', 'offline')
                    ->count();

                $totalCount = SportsBotUptimeLog::where('site_id', $s->id)
                    ->where('checked_at', '>=', $dayStart)
                    ->where('checked_at', '<=', $dayEnd)
                    ->count();

                if ($totalCount === 0) {
                    $dailyStatus[] = ['day' => $day, 'status' => 'none'];
                } elseif ($failCount === 0) {
                    $dailyStatus[] = ['day' => $day, 'label' => $cursor->format('M j'), 'status' => 'up'];
                } elseif ($failCount === $totalCount) {
                    $dailyStatus[] = ['day' => $day, 'label' => $cursor->format('M j'), 'status' => 'down'];
                } else {
                    $dailyStatus[] = ['day' => $day, 'label' => $cursor->format('M j'), 'status' => 'degraded'];
                }

                $cursor->addDay();
            }

            return [
                'id' => $s->id,
                'monitor_bot_id' => $s->monitor_bot_id,
                'monitor_bot_name' => $s->monitorBot?->name,
                'name' => $s->name,
                'url' => $s->url,
                'status' => $s->status,
                'uptime_percentage' => $s->uptime_percentage,
                'last_checked_at' => $s->last_checked_at?->diffForHumans(),
                'last_online_at' => $s->last_online_at?->diffForHumans(),
                'last_offline_at' => $s->last_offline_at?->diffForHumans(),
                'consecutive_failures' => $s->consecutive_failures,
                'failure_threshold' => $s->failure_threshold,
                'check_interval_seconds' => $s->check_interval_seconds,
                'alert_route_key' => $s->alert_route_key,
                'alerts_enabled' => $s->alerts_enabled,
                'enabled' => $s->enabled,
                'total_checks' => $s->total_checks,
                'total_failures' => $s->total_failures,
                'daily_status' => $dailyStatus,
            ];
        });

        return response()->json([
            'sites' => $sites,
            'monitor_bots' => SportsBotMonitorBot::query()
                ->withCount('sites')
                ->orderBy('name')
                ->get()
                ->map(fn (SportsBotMonitorBot $bot): array => $this->monitorBotData($bot))
                ->values(),
        ]);
    }

    public function uptimeSiteCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'monitor_bot_id' => ['sometimes', 'nullable', 'integer', 'exists:sportsbot_monitor_bots,id'],
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'check_interval_seconds' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'failure_threshold' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'alert_route_key' => ['sometimes', 'string', 'max:60'],
        ]);

        $site = SportsBotUptimeSite::create($validated);

        return response()->json(['site' => $site->fresh()], 201);
    }

    public function uptimeSiteUpdate(Request $request, int $id): JsonResponse
    {
        $site = SportsBotUptimeSite::findOrFail($id);

        $validated = $request->validate([
            'monitor_bot_id' => ['sometimes', 'nullable', 'integer', 'exists:sportsbot_monitor_bots,id'],
            'name' => ['sometimes', 'string', 'max:120'],
            'url' => ['sometimes', 'url', 'max:500'],
            'check_interval_seconds' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'failure_threshold' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'enabled' => ['sometimes', 'boolean'],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'alert_route_key' => ['sometimes', 'string', 'max:60'],
        ]);

        $site->update($validated);

        return response()->json(['site' => $site->fresh()]);
    }

    public function uptimeSiteDelete(int $id): JsonResponse
    {
        SportsBotUptimeSite::findOrFail($id)->delete();
        return response()->json(['deleted' => true]);
    }

    public function uptimeLogs(int $id): JsonResponse
    {
        $logs = SportsBotUptimeLog::where('site_id', $id)
            ->orderByDesc('checked_at')
            ->limit(100)
            ->get()
            ->map(fn ($l) => [
                'status' => $l->status,
                'status_code' => $l->status_code,
                'response_time_ms' => $l->response_time_ms,
                'error' => $l->error,
                'checked_at' => $l->checked_at->diffForHumans(),
            ]);

        return response()->json(['logs' => $logs]);
    }

    public function uptimeMonitorBotCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'telegram_token' => ['required', 'string', 'max:255'],
            'telegram_chat_id' => ['required', 'string', 'max:120'],
            'telegram_message_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'telegram_extra_targets' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $bot = SportsBotMonitorBot::query()->create($validated);

        return response()->json(['monitor_bot' => $this->monitorBotData($bot->loadCount('sites'))], 201);
    }

    public function uptimeMonitorBotUpdate(Request $request, int $id): JsonResponse
    {
        $bot = SportsBotMonitorBot::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'owner_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'telegram_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['sometimes', 'string', 'max:120'],
            'telegram_message_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'telegram_extra_targets' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('telegram_token', $validated) && trim((string) $validated['telegram_token']) === '') {
            unset($validated['telegram_token']);
        }

        $bot->fill($validated)->save();

        return response()->json(['monitor_bot' => $this->monitorBotData($bot->fresh()->loadCount('sites'))]);
    }

    public function uptimeMonitorBotDelete(int $id): JsonResponse
    {
        SportsBotMonitorBot::query()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function uptimeMonitorBotTest(
        int $id,
        MonitorBotTelegramNotifier $notifier,
        SportsBotUptimeAlertCardService $cards
    ): JsonResponse {
        $bot = SportsBotMonitorBot::query()->findOrFail($id);

        if (!$notifier->configured($bot)) {
            return response()->json([
                'message' => 'This Monitor Bot needs an enabled token and Telegram target before it can send a test alert.',
            ], 422);
        }

        try {
            $cardPath = $cards->renderTestAlertCard($bot);
            $results = $notifier->sendPhoto($cardPath, '', ['monitor_bot' => $bot]);

            return response()->json([
                'sent' => count($results),
                'results' => $results,
            ]);
        } catch (Throwable $error) {
            Log::warning('monitor_bot.telegram.test_alert_failed', [
                'monitor_bot_id' => $bot->id,
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'Monitor Bot test alert failed.',
                'error' => $error->getMessage(),
            ], 422);
        }
    }

    private function monitorBotData(SportsBotMonitorBot $bot): array
    {
        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'owner_label' => $bot->owner_label,
            'telegram_chat_id' => $bot->telegram_chat_id,
            'telegram_message_thread_id' => $bot->telegram_message_thread_id,
            'telegram_extra_targets' => $bot->telegram_extra_targets,
            'enabled' => $bot->enabled,
            'token_configured' => $bot->tokenConfigured(),
            'sites_count' => (int) ($bot->sites_count ?? $bot->sites()->count()),
            'updated_at' => $bot->updated_at?->toIso8601String(),
        ];
    }

    public function monitorSettings(): JsonResponse
    {
        $settings = app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class);

        return response()->json([
            'chat_id' => $settings->get('monitor_bot_chat_id', config('services.monitor_bot.telegram_chat_id', '')),
            'message_thread_id' => $settings->get('monitor_bot_message_thread_id', config('services.monitor_bot.telegram_message_thread_id', '')),
            'extra_targets' => $settings->get('monitor_bot_extra_targets', ''),
            'status' => [
                'configured' => config('services.monitor_bot.telegram_token', '') !== '' && ($settings->get('monitor_bot_chat_id', '') !== '' || config('services.monitor_bot.telegram_chat_id', '') !== ''),
                'has_token' => config('services.monitor_bot.telegram_token', '') !== '',
            ],
        ]);
    }

    public function saveMonitorSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => ['sometimes', 'string', 'max:120'],
            'message_thread_id' => ['sometimes', 'string', 'max:20'],
            'extra_targets' => ['sometimes', 'string', 'max:2000'],
        ]);

        $settings = app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class);

        if (isset($validated['chat_id'])) {
            $settings->set('monitor_bot_chat_id', $validated['chat_id']);
        }
        if (isset($validated['message_thread_id'])) {
            $settings->set('monitor_bot_message_thread_id', $validated['message_thread_id']);
        }
        if (isset($validated['extra_targets'])) {
            $settings->set('monitor_bot_extra_targets', $validated['extra_targets']);
        }

        return $this->monitorSettings();
    }
}
