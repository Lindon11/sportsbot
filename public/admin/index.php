<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$env = admin_read_env_file();

// Image serving handler
if (isset($_GET['image']) && admin_is_logged_in($env)) {
    $config = fb_config(true);
    $file = basename((string) $_GET['image']);
    $path = $config['paths']['generated'] . '/' . $file;

    if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png') {
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    http_response_code(404);
    exit('Image not found');
}

// Handle POST actions
$action = (string) ($_POST['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_require_csrf();
        require __DIR__ . '/actions.php';
    }
} catch (Throwable $e) {
    admin_flash('error', $e->getMessage());
    $redirectView = admin_action_view($action);
    admin_redirect($redirectView);
}

// Prepare view data
$csrf = admin_csrf_token();
$flash = admin_take_flash();
$hasPassword = !empty($env['BOT_ADMIN_PASSWORD_HASH']);
$loggedIn = admin_is_logged_in($env);
$config = fb_config(true);
fb_ensure_directories($config);

// Guard data queries with login state (matching original behaviour exactly)
$stateCounts = $loggedIn ? admin_state_counts($config) : ['matches' => 0, 'alerts' => 0, 'cache' => 0, 'coverage_sports' => 0, 'coverage_leagues' => 0, 'cards_pending' => 0, 'cards_sent' => 0, 'cards_failed' => 0, 'dispatch_failed' => 0, 'outbox_pending' => 0, 'outbox_failed' => 0, 'decisions' => 0, 'alert_types' => []];
$recentAlerts = $loggedIn ? admin_recent_alerts($config) : [];
$recentMatches = $loggedIn ? admin_recent_matches($config) : [];
$rateLimitInfo = $loggedIn ? admin_rate_limit_info($config) : [];
$cacheEntries = $loggedIn ? admin_cache_entries($config) : [];
$cardJobs = $loggedIn ? admin_card_jobs($config) : [];
$cardDispatches = $loggedIn ? admin_card_dispatches($config) : [];
$outboxItems = $loggedIn ? admin_outbox_items($config) : [];
$alertDecisions = $loggedIn ? admin_alert_decisions($config) : [];
$latestImages = $loggedIn ? admin_latest_images($config) : [];
$renderHealthChecks = $loggedIn ? admin_render_health_checks($config) : [];
$healthChecks = $loggedIn ? fb_system_health($config, is_file($config['paths']['state_db'] ?? '') ? fb_open_db($config) : null) : [];
$botLog = $loggedIn ? admin_tail_file($config['app']['log_file'] ?? '') : '';
$cronLog = $loggedIn ? admin_tail_file(($config['paths']['logs'] ?? '') . '/cron.log') : '';
$tvChannels = $loggedIn ? fb_tv_channels($config) : [];
$coverageSports = $loggedIn ? admin_coverage_sports($config) : [];
$coverageLeagues = $loggedIn ? admin_coverage_leagues($config) : [];
$tvChannelRegistry = $loggedIn ? admin_tv_channel_registry($config) : [];
$telegramTopics = admin_telegram_topics($config);
$routeMatrix = $loggedIn ? admin_route_matrix($config) : [];
$availableSports = $config['sports']['available'] ?? [];
$enabledSportKeys = fb_enabled_sport_keys($config);
$telegramRouteSports = admin_telegram_route_sport_options($config, $availableSports);
$telegramRouteRows = admin_telegram_route_form_rows($config, $availableSports);
$availableLeagues = $config['leagues']['available'] ?? $config['leagues']['allowed'];
$allowedLeagueIds = array_keys($config['leagues']['allowed']);
$configuredTvSlugs = fb_tv_configured_channel_slugs($config);
$registrySlugs = array_map(static fn (array $channel): string => (string) $channel['channel_slug'], $tvChannelRegistry);
$manualTvSlugs = array_values(array_diff($configuredTvSlugs, $registrySlugs));
$configuredCoverageLeagueIds = $config['coverage']['enabled_league_ids'] ?? [];
$coverageRegistryIds = array_map(static fn (array $league): string => (string) $league['league_id'], $coverageLeagues);
$manualCoverageLeagueIds = array_values(array_diff(array_map('strval', $configuredCoverageLeagueIds), $coverageRegistryIds));
$customerFollows = $loggedIn ? admin_customer_follow_state($config) : ['counts' => ['total' => 0, 'teams' => 0, 'players' => 0, 'feeds' => 0, 'users' => 0], 'recent' => []];
$sportProfiles = $loggedIn ? fb_sport_profiles($config) : [];
$adminViews = admin_views();
$activeView = $loggedIn ? admin_current_view() : 'dashboard';
$activeViewMeta = $adminViews[$activeView] ?? $adminViews['dashboard'];

// Render layout
require __DIR__ . '/layout.php';