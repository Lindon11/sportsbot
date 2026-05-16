<?php

declare(strict_types=1);

require_once __DIR__ . '/../../runner.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function admin_root(): string
{
    return dirname(__DIR__, 2);
}

function admin_env_path(): string
{
    return admin_root() . '/config/footballbot.env';
}

function admin_read_env_file(): array
{
    $path = admin_env_path();
    $values = [];

    if (!is_file($path)) {
        return $values;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = stripcslashes($value);
    }

    return $values;
}

function admin_format_env_value(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $value)) {
        return $value;
    }

    return '"' . addcslashes($value, "\\\"\n\r\t") . '"';
}

function admin_write_env_file(array $values): void
{
    $path = admin_env_path();
    $dir = dirname($path);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $orderedKeys = [
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_CHAT_ID',
        'TELEGRAM_MESSAGE_THREAD_ID',
        'TELEGRAM_ERROR_CHAT_ID',
        'TELEGRAM_EXTRA_CHAT_IDS',
        'BOT_TELEGRAM_ROUTES_JSON',
        'TELEGRAM_UPDATES_ENABLED',
        'BOT_TELEGRAM_DISABLE_NOTIFICATION',
        'THESPORTSDB_API_KEY',
        'BOT_TIMEZONE',
        'BOT_COVERAGE_PRESET',
        'BOT_ENABLED_SPORTS',
        'BOT_ENABLED_LEAGUE_IDS',
        'BOT_COVERAGE_COUNTRIES',
        'BOT_AUTO_ENABLE_DISCOVERED_LEAGUES',
        'BOT_MAX_SCHEDULE_LEAGUES',
        'BOT_ALLOWED_LEAGUE_IDS',
        'BOT_KICKOFF_PROGRESS_MAX',
        'BOT_SEND_RED_CARDS',
        'BOT_SEND_YELLOW_CARDS',
        'BOT_SEND_SUBSTITUTIONS',
        'BOT_SEND_MATCH_STARTS',
        'BOT_SEND_SCORE_UPDATES',
        'BOT_SEND_PERIOD_CHANGES',
        'BOT_SEND_MATCH_PREVIEWS',
        'BOT_PREVIEW_HOURS_AHEAD',
        'BOT_SEND_DAILY_CARD',
        'BOT_DAILY_CARD_TIME',
        'BOT_DAILY_CARD_SEND_IMAGE',
        'BOT_CARD_BURSTS_ENABLED',
        'BOT_CARD_ROUTE_MODE',
        'BOT_CARD_TYPES_ENABLED',
        'BOT_CARD_BURST_MIN_FIXTURES',
        'BOT_CARD_BURST_MIN_LIVE',
        'BOT_CARD_BURST_MIN_RESULTS',
        'BOT_CARD_BURST_COOLDOWN_MINUTES',
        'BOT_CARD_MAX_ITEMS_PER_TYPE',
        'BOT_CARD_MAX_PAGES_PER_RUN',
        'BOT_CARD_MAX_SENDS_PER_RUN',
        'BOT_CONTENT_PACKS_ENABLED',
        'BOT_CUSTOMER_GUIDE_ENABLED',
        'BOT_CUSTOMER_GUIDE_TIME',
        'BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS',
        'BOT_TEAM_WATCHLIST',
        'BOT_PLAYER_WATCHLIST',
        'BOT_FOLLOW_BUTTONS_ENABLED',
        'BOT_MAX_FOLLOW_BUTTONS',
        'BOT_SEND_KICKOFF_REMINDER',
        'BOT_KICKOFF_REMINDER_MINUTES',
        'BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS',
        'BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS',
        'BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS',
        'BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS',
        'BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS',
        'BOT_API_MIN_INTERVAL_MS',
        'BOT_LIVESCORE_CACHE_TTL',
        'BOT_TIMELINE_CACHE_TTL',
        'BOT_LOOKUP_CACHE_TTL',
        'BOT_MAX_LIVE_MATCHES_PER_RUN',
        'BOT_MAX_LIVE_MATCHES_PER_SPORT',
        'BOT_RENDER_ENGINE',
        'BOT_RENDER_CHROME_PATH',
        'BOT_RENDER_USER_DATA_DIR',
        'BOT_RENDER_EXTRA_ARGS',
        'BOT_FONT_REGULAR',
        'BOT_FONT_BOLD',
        'BOT_IMAGE_QUALITY',
        'BOT_IMAGE_CLEANUP_SECONDS',
        'BOT_IMAGE_PRESERVE_SAMPLE_IMAGES',
        'BOT_SPORT_PROFILES_JSON',
        'BOT_HEALTH_ALERTS_ENABLED',
        'BOT_HEALTH_ALERT_TIME',
        'BOT_TV_ENABLED',
        'BOT_TV_CHANNELS',
        'BOT_TV_SPORTS',
        'BOT_TV_DISCOVERY_COUNTRIES',
        'BOT_TV_DISCOVERY_DAYS_AHEAD',
        'BOT_TV_DAILY_ALERTS',
        'BOT_TV_SEND_IMAGE',
        'BOT_TV_DAILY_ALERT_TIME',
        'BOT_TV_LOOKAHEAD_HOURS',
        'BOT_TV_INCLUDE_IN_PREVIEWS',
        'BOT_TV_PREVIEW_REQUIRE_TV',
        'BOT_TV_FOOTBALL_ONLY',
        'BOT_TV_CACHE_TTL',
        'BOT_TV_MAX_EVENTS_PER_CHANNEL',
        'BOT_ADMIN_PASSWORD_HASH',
    ];

    $lines = [
        '# Football alert bot configuration',
        '# Managed by the private admin panel.',
    ];

    foreach ($orderedKeys as $key) {
        if (!array_key_exists($key, $values)) {
            continue;
        }

        $value = trim((string) $values[$key]);
        $lines[] = $key . '=' . admin_format_env_value($value);
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    @chmod($path, 0600);
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function admin_take_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function admin_require_csrf(): void
{
    $posted = (string) ($_POST['csrf'] ?? '');

    if ($posted === '' || !hash_equals(admin_csrf_token(), $posted)) {
        throw new RuntimeException('Security token expired. Refresh and try again.');
    }
}

function admin_is_logged_in(array $env): bool
{
    if (empty($env['BOT_ADMIN_PASSWORD_HASH'])) {
        return false;
    }

    return !empty($_SESSION['admin_authenticated']);
}

function admin_mask(?string $value): string
{
    $value = (string) $value;

    if ($value === '') {
        return 'Missing';
    }

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

function admin_env_bool(array $env, string $key, bool $default): bool
{
    if (!array_key_exists($key, $env)) {
        return $default;
    }

    return filter_var($env[$key], FILTER_VALIDATE_BOOLEAN);
}

function admin_env_value(array $env, string $key, string|int|bool|null $default = ''): string
{
    return (string) ($env[$key] ?? $default);
}

function admin_tail_file(string $path, int $lines = 80): string
{
    if (!is_file($path)) {
        return '';
    }

    $content = @file_get_contents($path);

    if ($content === false) {
        return '';
    }

    $allLines = explode("\n", $content);
    $tail = array_slice($allLines, -$lines);

    return implode("\n", $tail);
}

function admin_recent_alerts(array $config, int $limit = 20): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT alert_type, sport, event_id, created_at FROM sent_alerts ORDER BY created_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_recent_matches(array $config, int $limit = 20): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT event_id, sport, status, progress, home_score, away_score, updated_at FROM event_state ORDER BY updated_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_cache_entries(array $config, int $limit = 30): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT cache_key, status_code, expires_at FROM api_cache ORDER BY expires_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_card_jobs(array $config, int $limit = 20): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT card_type, sport, route_key, status, failed_dispatches, updated_at FROM card_jobs ORDER BY updated_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_card_dispatches(array $config, int $limit = 20): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT card_id, chat_id, message_thread_id, status, attempts, last_error, updated_at FROM card_dispatches ORDER BY updated_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_outbox_items(array $config, int $limit = 20): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT method, chat_id, message_thread_id, status, attempts, last_error, updated_at FROM telegram_outbox ORDER BY updated_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_customer_follow_state(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return ['counts' => ['total' => 0, 'teams' => 0, 'players' => 0, 'feeds' => 0, 'users' => 0], 'recent' => []];
    }

    $db = fb_open_db($config);
    $counts = [
        'total' => (int) ($db->querySingle('SELECT COUNT(*) FROM customer_follows') ?: 0),
        'teams' => (int) ($db->querySingle("SELECT COUNT(*) FROM customer_follows WHERE follow_type = 'team'") ?: 0),
        'players' => (int) ($db->querySingle("SELECT COUNT(*) FROM customer_follows WHERE follow_type = 'player'") ?: 0),
        'feeds' => (int) ($db->querySingle("SELECT COUNT(*) FROM customer_follows WHERE follow_type = 'feed'") ?: 0),
        'users' => (int) ($db->querySingle('SELECT COUNT(DISTINCT user_id) FROM customer_follows') ?: 0),
    ];

    $result = $db->query('SELECT user_id, follow_type, follow_id, follow_name, created_at FROM customer_follows ORDER BY created_at DESC LIMIT 20');

    if (!$result) {
        return ['counts' => $counts, 'recent' => []];
    }

    $recent = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $recent[] = $row;
    }

    return ['counts' => $counts, 'recent' => $recent];
}

function admin_alert_decisions(array $config, int $limit = 20): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $result = $db->query('SELECT decision, alert_type, sport, reason, created_at FROM alert_decisions ORDER BY created_at DESC LIMIT ' . ((int) $limit));

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_render_health_checks(array $config): array
{
    $path = $config['paths']['generated'] . '/render_health.json';

    if (!is_file($path)) {
        return [];
    }

    $content = @file_get_contents($path);

    if ($content === false) {
        return [];
    }

    $data = json_decode($content, true);

    return is_array($data) ? $data : [];
}

function admin_route_matrix(array $config): array
{
    $routesJson = $config['telegram']['routes_json'] ?? '';

    if ($routesJson === '') {
        return [];
    }

    $routes = json_decode($routesJson, true);

    if (!is_array($routes)) {
        return [];
    }

    $matrix = [];

    foreach ($routes as $sport => $targets) {
        if (!is_array($targets)) {
            continue;
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $chatId = (string) ($target['chat_id'] ?? '');
            $topicId = (string) ($target['thread_id'] ?? $target['topic_id'] ?? '');
            $route = $chatId . ($topicId !== '' ? ':' . $topicId : '');
            $chats = [$chatId];
            $topics = (int) ($topicId !== '' ? $topicId : 0);

            $matrix[] = [
                'sport' => (string) $sport,
                'route' => $route,
                'chats' => $chats,
                'topics' => $topics,
            ];
        }
    }

    return $matrix;
}

function admin_telegram_route_sport_options(array $config, array $availableSports): array
{
    $sports = $availableSports;

    $routesJson = $config['telegram']['routes_json'] ?? '';

    if ($routesJson !== '') {
        $routes = json_decode($routesJson, true);

        if (is_array($routes)) {
            foreach (array_keys($routes) as $sport) {
                if (!in_array($sport, $sports, true)) {
                    $sports[] = $sport;
                }
            }
        }
    }

    natcasesort($sports);

    return array_values($sports);
}

function admin_telegram_route_form_rows(array $config, array $availableSports): array
{
    $routesJson = $config['telegram']['routes_json'] ?? '';

    if ($routesJson === '') {
        return [['sport' => '', 'chat_id' => '', 'topic_id' => '']];
    }

    $routes = json_decode($routesJson, true);

    if (!is_array($routes)) {
        return [['sport' => '', 'chat_id' => '', 'topic_id' => '']];
    }

    $rows = [];

    foreach ($routes as $sport => $targets) {
        if (!is_array($targets)) {
            continue;
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $rows[] = [
                'sport' => (string) $sport,
                'chat_id' => (string) ($target['chat_id'] ?? ''),
                'topic_id' => (string) ($target['thread_id'] ?? $target['topic_id'] ?? ''),
            ];
        }
    }

    if ($rows === []) {
        $rows[] = ['sport' => '', 'chat_id' => '', 'topic_id' => ''];
    }

    return $rows;
}

function admin_decode_telegram_routes_json(string $json): array
{
    if ($json === '') {
        return [];
    }

    $routes = json_decode($json, true);

    return is_array($routes) ? $routes : [];
}

function admin_collect_telegram_route_rows(array $post): array
{
    $sports = $post['BOT_TELEGRAM_ROUTE_SPORT'] ?? [];
    $chatIds = $post['BOT_TELEGRAM_ROUTE_CHAT_ID'] ?? [];
    $topicIds = $post['BOT_TELEGRAM_ROUTE_TOPIC_ID'] ?? [];
    $rows = [];

    foreach ($sports as $i => $sport) {
        $sport = trim((string) $sport);
        $chatId = trim((string) ($chatIds[$i] ?? ''));
        $topicId = trim((string) ($topicIds[$i] ?? ''));

        if ($sport === '' && $chatId === '') {
            continue;
        }

        $rows[] = ['sport' => $sport, 'chat_id' => $chatId, 'topic_id' => $topicId];
    }

    return $rows;
}

function admin_save_telegram_topic_labels(array $config, array $post): int
{
    if (!is_file($config['paths']['state_db'])) {
        return 0;
    }

    $db = fb_open_db($config);
    $chatIds = $post['BOT_TELEGRAM_TOPIC_CHAT_ID'] ?? [];
    $topicIds = $post['BOT_TELEGRAM_TOPIC_ID'] ?? [];
    $names = $post['BOT_TELEGRAM_TOPIC_NAME'] ?? [];
    $routeValues = $post['BOT_TELEGRAM_TOPIC_ROUTE'] ?? [];
    $saved = 0;

    foreach ($chatIds as $i => $chatId) {
        $chatId = trim((string) $chatId);
        $topicId = (int) ($topicIds[$i] ?? 0);
        $name = trim((string) ($names[$i] ?? ''));
        $route = trim((string) ($routeValues[$i] ?? ''));

        if ($chatId === '' || $topicId <= 0) {
            continue;
        }

        $stmt = $db->prepare('UPDATE telegram_topics SET name = :name, route = :route WHERE chat_id = :chat_id AND message_thread_id = :topic_id');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':route', $route, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->bindValue(':topic_id', $topicId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($result) {
            $saved++;
        }
    }

    return $saved;
}

function admin_tv_channel_registry(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    return fb_list_tv_channel_registry(fb_open_db($config));
}

function admin_coverage_sports(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    return fb_list_coverage_sports(fb_open_db($config));
}

function admin_coverage_leagues(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    return fb_list_coverage_leagues(fb_open_db($config), 240);
}

function admin_telegram_topics(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    return fb_list_telegram_topics(fb_open_db($config), null, 120);
}

function admin_state_counts(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return ['matches' => 0, 'alerts' => 0, 'cache' => 0, 'coverage_sports' => 0, 'coverage_leagues' => 0, 'telegram_topics' => 0, 'cards_pending' => 0, 'cards_sent' => 0, 'cards_failed' => 0, 'dispatch_failed' => 0, 'outbox_pending' => 0, 'outbox_failed' => 0, 'decisions' => 0, 'alert_types' => []];
    }

    $db = fb_open_db($config);
    $alertTypes = [];
    $result = $db->query('SELECT alert_type, COUNT(*) as cnt FROM sent_alerts GROUP BY alert_type ORDER BY cnt DESC');

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $alertTypes[$row['alert_type']] = (int) $row['cnt'];
        }
    }

    return [
        'matches' => (int) ($db->querySingle('SELECT COUNT(*) FROM event_state') ?: 0),
        'alerts' => (int) ($db->querySingle('SELECT COUNT(*) FROM sent_alerts') ?: 0),
        'cache' => (int) ($db->querySingle('SELECT COUNT(*) FROM api_cache') ?: 0),
        'coverage_sports' => (int) ($db->querySingle('SELECT COUNT(*) FROM coverage_sports') ?: 0),
        'coverage_leagues' => (int) ($db->querySingle('SELECT COUNT(*) FROM coverage_leagues') ?: 0),
        'telegram_topics' => (int) ($db->querySingle('SELECT COUNT(*) FROM telegram_topics') ?: 0),
        'cards_pending' => (int) ($db->querySingle('SELECT COUNT(*) FROM card_jobs WHERE status IN ("pending", "rendering")') ?: 0),
        'cards_sent' => (int) ($db->querySingle('SELECT COUNT(*) FROM card_jobs WHERE status = "sent"') ?: 0),
        'cards_failed' => (int) ($db->querySingle('SELECT COUNT(*) FROM card_jobs WHERE status = "failed"') ?: 0),
        'dispatch_failed' => (int) ($db->querySingle('SELECT COUNT(*) FROM card_dispatches WHERE status = "failed"') ?: 0),
        'outbox_pending' => (int) ($db->querySingle('SELECT COUNT(*) FROM telegram_outbox WHERE status IN ("pending", "sending")') ?: 0),
        'outbox_failed' => (int) ($db->querySingle('SELECT COUNT(*) FROM telegram_outbox WHERE status = "failed"') ?: 0),
        'decisions' => (int) ($db->querySingle('SELECT COUNT(*) FROM alert_decisions') ?: 0),
        'alert_types' => $alertTypes,
    ];
}

function admin_rate_limit_info(array $config): array
{
    $lockPath = $config['paths']['api_cache_lock'];

    if (!is_file($lockPath)) {
        return ['last_request_ago' => null, 'min_interval_ms' => $config['thesportsdb']['min_request_interval_ms']];
    }

    $content = @file_get_contents($lockPath);

    if ($content === false || trim($content) === '') {
        return ['last_request_ago' => null, 'min_interval_ms' => $config['thesportsdb']['min_request_interval_ms']];
    }

    $lastTime = (float) trim($content);
    $agoMs = (int) round((microtime(true) - $lastTime) * 1000);

    return [
        'last_request_ago_ms' => max(0, $agoMs),
        'min_interval_ms' => (int) $config['thesportsdb']['min_request_interval_ms'],
        'livescore_cache_ttl' => (int) $config['thesportsdb']['livescore_cache_ttl'],
        'timeline_cache_ttl' => (int) $config['thesportsdb']['timeline_cache_ttl'],
        'lookup_cache_ttl' => (int) $config['thesportsdb']['lookup_cache_ttl'],
        'tv_cache_ttl' => (int) ($config['thesportsdb']['tv_cache_ttl'] ?? 0),
    ];
}

function admin_latest_images(array $config): array
{
    $files = glob($config['paths']['generated'] . '/*.png') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return array_slice($files, 0, 8);
}

function admin_public_image_url(string $path): ?string
{
    $root = realpath(admin_root());
    $real = realpath($path);

    if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return './?image=' . rawurlencode(basename($real));
}

function admin_delete_state_database(array $config): void
{
    foreach ([$config['paths']['state_db'], $config['paths']['state_db'] . '-shm', $config['paths']['state_db'] . '-wal'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function admin_tv_channels(array $config): array
{
    $slugs = fb_tv_configured_channel_slugs($config);
    $channels = [];
    foreach ($slugs as $slug) {
        $channels[] = [
            'label' => fb_tv_channel_label($slug),
            'slug' => $slug,
        ];
    }
    return $channels;
}

function admin_render_health_checks_list(array $config): array
{
    $db = is_file($config['paths']['state_db'] ?? '') ? fb_open_db($config) : null;
    return fb_system_health($config, $db);
}

function admin_views(): array
{
    return [
        'dashboard' => [
            'section' => 'Overview',
            'title' => 'Dashboard',
            'description' => 'A focused command surface for state, queues, health, and delivery risk.',
        ],
        'publishing' => [
            'section' => 'Publishing',
            'title' => 'Publishing Studio',
            'description' => 'Preview cards, push due card runs, and check the schedule without waiting for cron.',
        ],
        'activity' => [
            'section' => 'Publishing',
            'title' => 'Activity',
            'description' => 'Delivery history, outbox state, alert decisions, and recent match state.',
        ],
        'routing' => [
            'section' => 'Routing',
            'title' => 'Routing And Profiles',
            'description' => 'Review sport profiles and the labels used across score, start, period, and final alerts.',
        ],
        'data' => [
            'section' => 'Data Sources',
            'title' => 'Coverage And Data',
            'description' => 'Inspect API cache, enabled leagues, TV channels, and discovered source registries.',
        ],
        'media' => [
            'section' => 'Media',
            'title' => 'Image Library',
            'description' => 'Recently rendered cards and alert graphics from the generated image directory.',
        ],
        'health' => [
            'section' => 'System',
            'title' => 'Health',
            'description' => 'System checks, renderer diagnostics, and health-summary controls.',
        ],
        'system' => [
            'section' => 'System',
            'title' => 'Operations',
            'description' => 'Manual bot operations, discovery tools, cache controls, and reset controls.',
        ],
        'logs' => [
            'section' => 'System',
            'title' => 'Logs',
            'description' => 'Tail views for bot and cron logs.',
        ],
        'settings' => [
            'section' => 'Configuration',
            'title' => 'Settings',
            'description' => 'Connection keys, coverage, routing, alert rules, cards, TV listings, rendering, and access.',
        ],
    ];
}

function admin_current_view(): string
{
    $view = (string) ($_GET['view'] ?? 'dashboard');

    return array_key_exists($view, admin_views()) ? $view : 'dashboard';
}

function admin_view_url(string $view): string
{
    if (!array_key_exists($view, admin_views())) {
        $view = 'dashboard';
    }

    return './?view=' . rawurlencode($view);
}

function admin_action_view(string $action): string
{
    return match ($action) {
        'generate_card_preview',
        'send_cards_now',
        'send_daily_card_test',
        'send_kickoff_reminder_test' => 'publishing',
        'retry_failed_cards' => 'activity',
        'send_customer_guide_test' => 'publishing',
        'discover_coverage' => 'data',
        'discover_tv_channels',
        'send_tv_schedule_test' => 'data',
        'process_telegram_updates' => 'settings',
        'test_telegram_routes' => 'settings',
        'run_render_health',
        'send_health_summary' => 'health',
        'test_telegram',
        'send_manual_message',
        'generate_samples',
        'generate_last_match',
        'reset_state' => 'system',
        'clear_api_cache' => 'data',
        'save_settings' => 'settings',
        default => 'dashboard',
    };
}

function admin_redirect(string $view = 'dashboard'): void
{
    header('Location: ' . admin_view_url($view));
    exit;
}

function admin_nav_link(string $activeView, string $view, string $label, string|int $badge): string
{
    $class = $activeView === $view ? ' class="active" aria-current="page"' : '';

    return sprintf(
        '<a%s href="%s">%s <span>%s</span></a>',
        $class,
        htmlspecialchars(admin_view_url($view)),
        htmlspecialchars($label),
        htmlspecialchars((string) $badge)
    );
}