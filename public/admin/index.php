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
$view = admin_current_view();
$csrf = admin_csrf_token();
$flash = admin_take_flash();
$config = fb_config(true);

// View-specific data
$stateCounts = admin_state_counts($config);
$recentAlerts = admin_recent_alerts($config);
$recentMatches = admin_recent_matches($config);
$rateLimitInfo = admin_rate_limit_info($config);
$cacheEntries = admin_cache_entries($config);
$cardJobs = admin_card_jobs($config);
$cardDispatches = admin_card_dispatches($config);
$outboxItems = admin_outbox_items($config);
$alertDecisions = admin_alert_decisions($config);
$latestImages = admin_latest_images($config);
$renderHealthChecks = admin_render_health_checks($config);
$healthChecks = admin_render_health_checks_list($config);
$botLog = admin_tail_file($config['paths']['bot_log'] ?? '');
$cronLog = admin_tail_file($config['paths']['cron_log'] ?? '');
$tvChannels = admin_tv_channels($config);
$coverageSports = admin_coverage_sports($config);
$coverageLeagues = admin_coverage_leagues($config);
$tvChannelRegistry = admin_tv_channel_registry($config);
$telegramTopics = admin_telegram_topics($config);
$routeMatrix = admin_route_matrix($config);
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
$customerFollowState = admin_customer_follow_state($config);

// Render layout
require __DIR__ . '/layout.php';