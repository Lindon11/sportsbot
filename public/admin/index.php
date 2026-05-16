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
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $data = file($path, FILE_IGNORE_NEW_LINES);

    if ($data === false) {
        return '';
    }

    return implode("\n", array_slice($data, -$lines));
}

function admin_recent_alerts(array $config, int $limit = 8): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT alert_key, event_id, sport, alert_type, created_at
         FROM sent_alerts
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_recent_matches(array $config, int $limit = 8): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT event_id, sport, league_id, status, progress, home_score, away_score, updated_at
         FROM event_state
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_cache_entries(array $config, int $limit = 8): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT cache_key, status_code, expires_at, updated_at
         FROM api_cache
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_card_jobs(array $config, int $limit = 12): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT
            j.*,
            SUM(CASE WHEN d.status = "sent" THEN 1 ELSE 0 END) AS sent_dispatches,
            SUM(CASE WHEN d.status = "failed" THEN 1 ELSE 0 END) AS failed_dispatches
         FROM card_jobs j
         LEFT JOIN card_dispatches d ON d.job_key = j.job_key
         GROUP BY j.job_key
         ORDER BY j.updated_at DESC, j.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_card_dispatches(array $config, int $limit = 12): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT job_key, chat_id, page_no, status, image_path, sent_at, last_error
         FROM card_dispatches
         ORDER BY COALESCE(sent_at, "") DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_outbox_items(array $config, int $limit = 12): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT outbox_key, alert_key, method, chat_id, message_thread_id, status, attempts, image_path, sent_at, updated_at, last_error
         FROM telegram_outbox
         ORDER BY updated_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_customer_follow_state(array $config): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [
            'counts' => ['total' => 0, 'teams' => 0, 'players' => 0, 'feeds' => 0, 'users' => 0],
            'recent' => [],
        ];
    }

    $db = fb_open_db($config);

    return [
        'counts' => fb_customer_follow_counts($db),
        'recent' => fb_recent_customer_follows($db),
    ];
}

function admin_alert_decisions(array $config, int $limit = 14): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT alert_key, event_id, sport, alert_type, decision, reason, created_at
         FROM alert_decisions
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_render_health_checks(array $config, int $limit = 6): array
{
    if (!is_file($config['paths']['state_db'])) {
        return [];
    }

    $db = fb_open_db($config);
    $stmt = $db->prepare(
        'SELECT engine, status, message, image_path, duration_ms, created_at
         FROM render_health_checks
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function admin_route_matrix(array $config): array
{
    $rows = [];

    foreach ($config['coverage']['enabled_sports'] ?? [] as $sport) {
        $targets = fb_telegram_route_targets($config, (string) $sport);
        $defaultTargets = fb_telegram_default_targets($config);
        $rows[] = [
            'sport' => fb_canonical_sport((string) $sport, (string) $sport),
            'chats' => array_map(static fn (array $target): string => fb_telegram_target_key($target), $targets),
            'topics' => count(array_filter($targets, static fn (array $target): bool => !empty($target['message_thread_id']))),
            'route' => $targets === $defaultTargets ? 'default' : 'sport',
        ];
    }

    return $rows;
}

function admin_telegram_route_sport_options(array $config, array $availableSports): array
{
    $sports = [];

    foreach ($availableSports as $sport) {
        $label = fb_canonical_sport((string) $sport, (string) $sport);
        $sports[fb_sport_key($label)] = $label;
    }

    foreach ($config['coverage']['enabled_sports'] ?? [] as $sport) {
        $label = fb_canonical_sport((string) $sport, (string) $sport);
        $sports[fb_sport_key($label)] = $label;
    }

    foreach (($config['telegram']['routes'] ?? []) as $sport => $routeValue) {
        if (fb_sport_key((string) $sport) === 'default') {
            continue;
        }

        $label = fb_canonical_sport((string) $sport, (string) $sport);
        $sports[fb_sport_key($label)] = $label;
    }

    natcasesort($sports);

    return array_values($sports);
}

function admin_telegram_route_form_rows(array $config, array $availableSports, int $blankRows = 4): array
{
    $rows = [];
    $seenSports = [];
    $routes = $config['telegram']['routes'] ?? [];

    if (is_array($routes)) {
        foreach ($routes as $sport => $routeValue) {
            if (fb_sport_key((string) $sport) === 'default') {
                continue;
            }

            $sportLabel = fb_canonical_sport((string) $sport, (string) $sport);
            $targets = fb_telegram_targets_from_value($routeValue);

            if ($targets === []) {
                $rows[] = [
                    'sport' => $sportLabel,
                    'chat_id' => '',
                    'topic_id' => '',
                ];
                $seenSports[fb_sport_key($sportLabel)] = true;
                continue;
            }

            foreach ($targets as $target) {
                $rows[] = [
                    'sport' => $sportLabel,
                    'chat_id' => (string) ($target['chat_id'] ?? ''),
                    'topic_id' => (string) ($target['message_thread_id'] ?? ''),
                ];
            }

            $seenSports[fb_sport_key($sportLabel)] = true;
        }
    }

    foreach ($config['coverage']['enabled_sports'] ?? $availableSports as $sport) {
        $sportLabel = fb_canonical_sport((string) $sport, (string) $sport);
        $sportKey = fb_sport_key($sportLabel);

        if (isset($seenSports[$sportKey])) {
            continue;
        }

        $rows[] = [
            'sport' => $sportLabel,
            'chat_id' => '',
            'topic_id' => '',
        ];
        $seenSports[$sportKey] = true;
    }

    for ($idx = 0; $idx < $blankRows; $idx++) {
        $rows[] = [
            'sport' => '',
            'chat_id' => '',
            'topic_id' => '',
        ];
    }

    return $rows;
}

function admin_decode_telegram_routes_json(string $json): array
{
    $json = trim($json);

    if ($json === '') {
        return [];
    }

    $routes = json_decode($json, true);

    if (!is_array($routes) || array_is_list($routes)) {
        throw new RuntimeException('Telegram routes JSON must be an object like {"Rugby":[{"chat_id":"-100...","thread_id":12}]}.');
    }

    return $routes;
}

function admin_collect_telegram_route_rows(array $post): array
{
    $sports = $post['BOT_TELEGRAM_ROUTE_SPORT'] ?? [];
    $chatIds = $post['BOT_TELEGRAM_ROUTE_CHAT_ID'] ?? [];
    $topicIds = $post['BOT_TELEGRAM_ROUTE_TOPIC_ID'] ?? [];

    $sports = is_array($sports) ? array_values($sports) : [];
    $chatIds = is_array($chatIds) ? array_values($chatIds) : [];
    $topicIds = is_array($topicIds) ? array_values($topicIds) : [];
    $rowCount = max(count($sports), count($chatIds), count($topicIds));
    $routes = [];

    for ($idx = 0; $idx < $rowCount; $idx++) {
        $sport = trim((string) ($sports[$idx] ?? ''));
        $chatId = trim((string) ($chatIds[$idx] ?? ''));
        $topicId = trim((string) ($topicIds[$idx] ?? ''));

        if ($sport === '' && $chatId === '' && $topicId === '') {
            continue;
        }

        if ($sport === '') {
            throw new RuntimeException('Choose a sport for every Telegram topic route row you fill in.');
        }

        if ($chatId === '' && $topicId !== '') {
            throw new RuntimeException('Add a Telegram chat ID before setting a topic ID for ' . $sport . '.');
        }

        if ($chatId === '') {
            continue;
        }

        if ($topicId !== '' && preg_match('/^\d+$/', $topicId) !== 1) {
            throw new RuntimeException('Topic IDs must be positive numbers. Check the ' . $sport . ' route.');
        }

        $target = ['chat_id' => $chatId];

        if ($topicId !== '') {
            $target['thread_id'] = (int) $topicId;
        }

        $routes[$sport][] = $target;
    }

    return $routes;
}

function admin_save_telegram_topic_labels(array $config, array $post): int
{
    $chatIds = $post['BOT_TELEGRAM_TOPIC_CHAT_ID'] ?? [];
    $topicIds = $post['BOT_TELEGRAM_TOPIC_ID'] ?? [];
    $names = $post['BOT_TELEGRAM_TOPIC_NAME'] ?? [];

    $chatIds = is_array($chatIds) ? array_values($chatIds) : [];
    $topicIds = is_array($topicIds) ? array_values($topicIds) : [];
    $names = is_array($names) ? array_values($names) : [];
    $rowCount = max(count($chatIds), count($topicIds), count($names));

    if ($rowCount === 0 || !is_file($config['paths']['state_db'])) {
        return 0;
    }

    $db = fb_open_db($config);
    $saved = 0;

    for ($idx = 0; $idx < $rowCount; $idx++) {
        $chatId = trim((string) ($chatIds[$idx] ?? ''));
        $topicId = trim((string) ($topicIds[$idx] ?? ''));
        $name = trim((string) ($names[$idx] ?? ''));

        if ($chatId === '' || $topicId === '' || $name === '') {
            continue;
        }

        if (preg_match('/^\d+$/', $topicId) !== 1) {
            throw new RuntimeException('Discovered topic IDs must be positive numbers.');
        }

        if (fb_save_telegram_topic($db, $chatId, (int) $topicId, $name, [], 'admin_label')) {
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

$env = admin_read_env_file();

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

$action = (string) ($_POST['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_require_csrf();

        if ($action === 'bootstrap') {
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if (strlen($password) < 12) {
                throw new RuntimeException('Use an admin password of at least 12 characters.');
            }

            if ($password !== $confirm) {
                throw new RuntimeException('Password confirmation did not match.');
            }

            $env['BOT_ADMIN_PASSWORD_HASH'] = password_hash($password, PASSWORD_DEFAULT);
            $env['BOT_TIMEZONE'] = $env['BOT_TIMEZONE'] ?? 'Europe/London';
            admin_write_env_file($env);
            $_SESSION['admin_authenticated'] = true;
            admin_flash('success', 'Admin password created. You are signed in.');
            admin_redirect('dashboard');
        }

        if ($action === 'login') {
            $hash = (string) ($env['BOT_ADMIN_PASSWORD_HASH'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if ($hash === '' || !password_verify($password, $hash)) {
                throw new RuntimeException('Invalid admin password.');
            }

            $_SESSION['admin_authenticated'] = true;
            admin_flash('success', 'Signed in.');
            admin_redirect('dashboard');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            admin_redirect('dashboard');
        }

        if (!admin_is_logged_in($env)) {
            throw new RuntimeException('Please sign in first.');
        }

        $config = fb_config(true);
        fb_ensure_directories($config);

        if ($action === 'save_settings') {
            foreach ([
                'TELEGRAM_BOT_TOKEN',
                'TELEGRAM_CHAT_ID',
                'TELEGRAM_MESSAGE_THREAD_ID',
                'TELEGRAM_ERROR_CHAT_ID',
                'TELEGRAM_EXTRA_CHAT_IDS',
                'BOT_TELEGRAM_ROUTES_JSON',
                'TELEGRAM_UPDATES_ENABLED',
                'THESPORTSDB_API_KEY',
                'BOT_TIMEZONE',
                'BOT_COVERAGE_PRESET',
                'BOT_COVERAGE_COUNTRIES',
                'BOT_MAX_SCHEDULE_LEAGUES',
                'BOT_KICKOFF_PROGRESS_MAX',
                'BOT_PREVIEW_HOURS_AHEAD',
                'BOT_DAILY_CARD_TIME',
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
                'BOT_CUSTOMER_GUIDE_TIME',
                'BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS',
                'BOT_TEAM_WATCHLIST',
                'BOT_PLAYER_WATCHLIST',
                'BOT_MAX_FOLLOW_BUTTONS',
                'BOT_KICKOFF_REMINDER_MINUTES',
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
                'BOT_SPORT_PROFILES_JSON',
                'BOT_HEALTH_ALERT_TIME',
                'BOT_TV_SPORTS',
                'BOT_TV_DISCOVERY_COUNTRIES',
                'BOT_TV_DISCOVERY_DAYS_AHEAD',
                'BOT_TV_DAILY_ALERT_TIME',
                'BOT_TV_LOOKAHEAD_HOURS',
                'BOT_TV_CACHE_TTL',
                'BOT_TV_MAX_EVENTS_PER_CHANNEL',
            ] as $key) {
                $env[$key] = trim((string) ($_POST[$key] ?? ''));
            }

            $advancedRoutes = admin_decode_telegram_routes_json($env['BOT_TELEGRAM_ROUTES_JSON']);

            if (!empty($_POST['BOT_TELEGRAM_ROUTES_USE_ADVANCED'])) {
                $routes = $advancedRoutes;
            } else {
                $routes = [];

                if (array_key_exists('default', $advancedRoutes)) {
                    $routes['default'] = $advancedRoutes['default'];
                }

                foreach (admin_collect_telegram_route_rows($_POST) as $sport => $targets) {
                    $routes[$sport] = $targets;
                }
            }

            $env['BOT_TELEGRAM_ROUTES_JSON'] = $routes === []
                ? ''
                : (json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

            $savedTopicLabels = admin_save_telegram_topic_labels($config, $_POST);
            if ($savedTopicLabels > 0) {
                admin_flash('success', sprintf('Saved %d Telegram topic label(s).', $savedTopicLabels));
            }

            $env['BOT_RENDER_ENGINE'] = strtolower($env['BOT_RENDER_ENGINE'] ?: 'auto');
            if (!in_array($env['BOT_RENDER_ENGINE'], ['auto', 'puppeteer', 'gd'], true)) {
                throw new RuntimeException('Render engine must be auto, puppeteer, or gd.');
            }

            if ($env['BOT_SPORT_PROFILES_JSON'] !== '') {
                $profiles = json_decode($env['BOT_SPORT_PROFILES_JSON'], true);

                if (!is_array($profiles)) {
                    throw new RuntimeException('Sport profiles JSON must be an object keyed by sport name.');
                }
            }

            $allowedLeagueIds = $_POST['BOT_ALLOWED_LEAGUE_IDS'] ?? [];
            $allowedLeagueIds = is_array($allowedLeagueIds) ? array_values(array_filter(array_map('strval', $allowedLeagueIds))) : [];
            $validLeagueIds = array_keys(fb_config(true)['leagues']['available'] ?? []);
            $allowedLeagueIds = array_values(array_intersect($allowedLeagueIds, $validLeagueIds));

            if ($allowedLeagueIds === []) {
                throw new RuntimeException('Select at least one enabled league.');
            }

            $env['BOT_ALLOWED_LEAGUE_IDS'] = implode(',', $allowedLeagueIds);

            $enabledSports = $_POST['BOT_ENABLED_SPORTS'] ?? [];
            $enabledSports = is_array($enabledSports) ? array_values(array_filter(array_map('strval', $enabledSports))) : [];
            $validSports = fb_config(true)['sports']['available'] ?? [];
            $enabledSports = array_values(array_intersect($enabledSports, $validSports));

            if ($enabledSports === []) {
                throw new RuntimeException('Select at least one enabled sport.');
            }

            $env['BOT_ENABLED_SPORTS'] = implode(',', $enabledSports);

            $selectedCoverageLeagues = $_POST['BOT_ENABLED_LEAGUE_IDS_SELECTED'] ?? [];
            $selectedCoverageLeagues = is_array($selectedCoverageLeagues) ? array_values(array_filter(array_map('strval', $selectedCoverageLeagues))) : [];
            $manualCoverageLeagues = preg_split('/[\r\n,]+/', (string) ($_POST['BOT_ENABLED_LEAGUE_IDS'] ?? '')) ?: [];
            $manualCoverageLeagues = array_values(array_filter(array_map('trim', $manualCoverageLeagues)));
            $env['BOT_ENABLED_LEAGUE_IDS'] = implode(',', array_values(array_unique(array_merge($selectedCoverageLeagues, $manualCoverageLeagues))));

            $selectedTvChannels = $_POST['BOT_TV_SELECTED_CHANNELS'] ?? [];
            $selectedTvChannels = is_array($selectedTvChannels) ? array_values(array_filter(array_map('fb_tv_channel_slug', $selectedTvChannels))) : [];
            $manualTvChannels = preg_split('/[\r\n,]+/', (string) ($_POST['BOT_TV_CHANNELS'] ?? '')) ?: [];
            $manualTvChannels = array_values(array_filter(array_map('fb_tv_channel_slug', $manualTvChannels)));
            $env['BOT_TV_CHANNELS'] = implode(',', array_values(array_unique(array_merge($selectedTvChannels, $manualTvChannels))));

            foreach ([
                'BOT_TELEGRAM_DISABLE_NOTIFICATION',
                'TELEGRAM_UPDATES_ENABLED',
                'BOT_SEND_RED_CARDS',
                'BOT_SEND_YELLOW_CARDS',
                'BOT_SEND_SUBSTITUTIONS',
                'BOT_SEND_MATCH_STARTS',
                'BOT_SEND_SCORE_UPDATES',
                'BOT_SEND_PERIOD_CHANGES',
                'BOT_SEND_MATCH_PREVIEWS',
                'BOT_SEND_DAILY_CARD',
                'BOT_DAILY_CARD_SEND_IMAGE',
                'BOT_CARD_BURSTS_ENABLED',
                'BOT_CUSTOMER_GUIDE_ENABLED',
                'BOT_FOLLOW_BUTTONS_ENABLED',
                'BOT_SEND_KICKOFF_REMINDER',
                'BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS',
                'BOT_IMAGE_PRESERVE_SAMPLE_IMAGES',
                'BOT_HEALTH_ALERTS_ENABLED',
                'BOT_AUTO_ENABLE_DISCOVERED_LEAGUES',
                'BOT_TV_ENABLED',
                'BOT_TV_DAILY_ALERTS',
                'BOT_TV_SEND_IMAGE',
                'BOT_TV_INCLUDE_IN_PREVIEWS',
                'BOT_TV_PREVIEW_REQUIRE_TV',
                'BOT_TV_FOOTBALL_ONLY',
            ] as $key) {
                $env[$key] = isset($_POST[$key]) ? 'true' : 'false';
            }

            if (!empty($_POST['new_password'])) {
                $newPassword = (string) $_POST['new_password'];
                $confirm = (string) ($_POST['confirm_password'] ?? '');

                if (strlen($newPassword) < 12) {
                    throw new RuntimeException('Use an admin password of at least 12 characters.');
                }

                if ($newPassword !== $confirm) {
                    throw new RuntimeException('Password confirmation did not match.');
                }

                $env['BOT_ADMIN_PASSWORD_HASH'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            admin_write_env_file($env);
            fb_config(true);
            admin_flash('success', 'Settings saved.');
            admin_redirect('settings');
        }

        if ($action === 'test_telegram') {
            fb_require_env($config, true);

            // Always regenerate samples to ensure latest template is used
            foreach (fb_sample_alerts() as $alert) {
                $path = fb_generate_alert_image($config, $alert);
                rename($path, $config['paths']['generated'] . '/sample_' . basename($path));
            }
            $image = (glob($config['paths']['generated'] . '/sample_*goal*.png') ?: [$config['paths']['generated'] . '/sample_goal.png'])[0];

            fb_telegram_send_photo($config, $image, 'Test alert from football bot');
            admin_flash('success', 'Telegram test image sent.');
            admin_redirect('system');
        }

        if ($action === 'send_manual_message') {
            fb_require_env($config, true);
            $message = (string) ($_POST['manual_message'] ?? '');
            fb_telegram_send_message($config, $message);
            admin_flash('success', 'Message sent to Telegram.');
            admin_redirect('system');
        }

        if ($action === 'generate_samples') {
            $count = 0;

            foreach (fb_sample_alerts() as $alert) {
                $path = fb_generate_alert_image($config, $alert);
                rename($path, $config['paths']['generated'] . '/sample_' . basename($path));
                $count++;
            }

            admin_flash('success', sprintf('Generated %d sample image(s).', $count));
            admin_redirect('system');
        }

        if ($action === 'generate_last_match') {
            $result = fb_generate_last_english_match_test_image($config);
            $match = $result['match'];
            admin_flash('success', sprintf(
                'Generated last English match test: %s %d-%d %s.',
                $match['home_team'],
                $match['home_score'],
                $match['away_score'],
                $match['away_team']
            ));
            admin_flash('info', 'Image: ' . basename((string) $result['image']));
            admin_redirect('system');
        }

        if ($action === 'dry_run') {
            $summary = fb_run_live_check($config, true);
            admin_flash('success', sprintf(
                'Dry run complete: %d live scores, %d allowed matches, %d generated alert(s).',
                $summary['total_live_scores'],
                $summary['allowed_matches'],
                $summary['generated_alerts']
            ));

            foreach (array_slice($summary['messages'], 0, 6) as $message) {
                admin_flash('info', $message);
            }

            admin_redirect('dashboard');
        }

        if ($action === 'generate_card_preview') {
            fb_require_env($config, false);
            $db = fb_open_db($config);
            $liveRows = fb_filter_allowed_matches($config, fb_fetch_live_scores($config, $db), $db);
            $jobs = fb_schedule_matchday_card_jobs($config, $db, $liveRows, false);
            $wantedType = trim((string) ($_POST['preview_card_type'] ?? ''));

            if ($wantedType !== '') {
                $jobs = array_values(array_filter($jobs, static fn (array $job): bool => (string) ($job['card_type'] ?? '') === $wantedType));
            }

            if ($jobs === []) {
                admin_flash('info', 'No card jobs are due for that preview window right now.');
            } else {
                $page = $jobs[0]['payload']['pages'][0] ?? null;

                if (!is_array($page)) {
                    throw new RuntimeException('The selected card job has no renderable pages.');
                }

                $imagePath = fb_generate_matchday_card_image($config, $page);
                admin_flash('success', 'Generated card preview: ' . basename($imagePath));
            }

            admin_redirect('publishing');
        }

        if ($action === 'send_cards_now') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $liveRows = fb_filter_allowed_matches($config, fb_fetch_live_scores($config, $db), $db);
            fb_schedule_matchday_card_jobs($config, $db, $liveRows, true);
            $cardSummary = fb_dispatch_matchday_card_jobs($config, $db, false);
            admin_flash('success', sprintf(
                'Card dispatch complete: %d job(s), %d page(s), %d sent, %d failed.',
                $cardSummary['jobs'],
                $cardSummary['pages'],
                $cardSummary['sent'],
                $cardSummary['failed']
            ));

            admin_redirect('publishing');
        }

        if ($action === 'retry_failed_cards') {
            fb_require_env($config, true);
            $cardSummary = fb_dispatch_matchday_card_jobs($config, fb_open_db($config), false);
            admin_flash('success', sprintf(
                'Retried cards: %d job(s), %d sent dispatch(es), %d failed dispatch(es).',
                $cardSummary['jobs'],
                $cardSummary['sent'],
                $cardSummary['failed']
            ));

            admin_redirect('activity');
        }

        if ($action === 'send_tv_schedule_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $events = fb_fetch_tv_events($config, $db);
            $message = fb_format_tv_schedule_message(
                $config,
                $events,
                (int) ($config['tv']['lookahead_hours'] ?? 24)
            );
            if (!empty($config['tv']['send_image'])) {
                $imagePath = fb_generate_tv_schedule_image(
                    $config,
                    fb_tv_events_in_window($config, $events, (int) ($config['tv']['lookahead_hours'] ?? 24)),
                    (int) ($config['tv']['lookahead_hours'] ?? 24)
                );
                fb_telegram_send_photo_all_groups($config, $imagePath, strtok($message, "\n") ?: 'TV Sports Guide');
            } else {
                fb_telegram_send_message_all_groups($config, $message);
            }
            admin_flash('success', sprintf(
                'TV schedule test sent with %d listed event(s).',
                count(fb_tv_events_in_window($config, $events, (int) ($config['tv']['lookahead_hours'] ?? 24)))
            ));
            admin_redirect('data');
        }

        if ($action === 'send_daily_card_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $alerts = fb_detect_daily_card_alerts($config, $db);
            if ($alerts === []) {
                admin_flash('info', 'No matches found for today\'s daily card.');
            } else {
                $alert = $alerts[0];
                $caption = $alert['text'] ?? fb_format_daily_card_message($config, $alert['leagues'] ?? []);
                $sportRoute = (string) ($alert['meta']['sport'] ?? '');
                if (!empty($config['alerts']['daily_card_send_image'])) {
                    $imagePath = fb_generate_daily_card_image($config, $alert['leagues'] ?? []);
                    if ($sportRoute !== '') {
                        fb_telegram_send_photo_route($config, $imagePath, strtok($caption, "\n") ?: "Today's Matches", $sportRoute);
                    } else {
                        fb_telegram_send_photo_all_groups($config, $imagePath, strtok($caption, "\n") ?: "Today's Matches");
                    }
                } else {
                    if ($sportRoute !== '') {
                        fb_telegram_send_message_route($config, $caption, $sportRoute);
                    } else {
                        fb_telegram_send_message_all_groups($config, $caption);
                    }
                }
                admin_flash('success', sprintf('Daily card test sent with %d match(es).', $alert['meta']['match_count'] ?? 0));
            }
            admin_redirect('publishing');
        }

        if ($action === 'send_customer_guide_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $guide = fb_format_customer_guide_message($config, $db);
            $options = !empty($guide['reply_markup']) ? ['reply_markup' => $guide['reply_markup']] : [];
            fb_telegram_send_message_all_groups($config, $guide['text'], $options);
            admin_flash('success', sprintf(
                'Customer guide test sent: %d live, %d fixtures, %d TV listings.',
                (int) ($guide['meta']['live_count'] ?? 0),
                (int) ($guide['meta']['fixture_count'] ?? 0),
                (int) ($guide['meta']['tv_count'] ?? 0)
            ));
            admin_redirect('publishing');
        }

        if ($action === 'send_kickoff_reminder_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $alerts = fb_detect_kickoff_reminder_alerts($config, $db);
            if ($alerts === []) {
                admin_flash('info', 'No matches kicking off within the reminder window right now.');
            } else {
                $alert = $alerts[0];
                try {
                    $alert = fb_enrich_alert_assets($config, $db, $alert);
                } catch (Throwable $e) {
                    // Continue without enrichment
                }
                $caption = fb_caption_for_alert($alert);
                $imagePath = fb_generate_alert_image($config, $alert);
                fb_telegram_send_photo_route($config, $imagePath, $caption, $alert['match']['sport'] ?? null);
                admin_flash('success', sprintf('Kickoff reminder test sent for %s vs %s.', $alert['match']['home_team'] ?? 'Home', $alert['match']['away_team'] ?? 'Away'));
            }
            admin_redirect('publishing');
        }

        if ($action === 'discover_coverage') {
            fb_require_env($config, false);
            $result = fb_discover_coverage($config, fb_open_db($config));
            admin_flash('success', sprintf(
                'Coverage discovery found %d sport(s), %d league(s), and auto-enabled %d league(s).',
                $result['sports'],
                $result['leagues'],
                $result['enabled_leagues']
            ));

            foreach (array_slice($result['errors'], 0, 3) as $error) {
                admin_flash('error', $error);
            }

            admin_redirect('data');
        }

        if ($action === 'test_telegram_routes') {
            fb_require_env($config, true);
            fb_telegram_send_message_all_groups($config, 'Route test: default sports digest');

            foreach (array_values(fb_configured_telegram_route_sports($config)) as $sport) {
                fb_telegram_send_message_route($config, 'Route test: ' . $sport, $sport);
            }

            admin_flash('success', 'Telegram route test sent.');
            admin_redirect('settings');
        }

        if ($action === 'process_telegram_updates') {
            fb_require_env($config, true);
            $summary = fb_process_telegram_updates($config, fb_open_db($config));
            admin_flash('success', sprintf(
                'Telegram update sync complete: %d update(s), %d message(s), %d callback(s), %d topic touch(es), %d menu action(s).',
                (int) ($summary['updates'] ?? 0),
                (int) ($summary['messages'] ?? 0),
                (int) ($summary['callbacks'] ?? 0),
                (int) ($summary['topics'] ?? 0),
                (int) ($summary['menus'] ?? 0)
            ));

            foreach (array_slice($summary['errors'] ?? [], 0, 3) as $error) {
                admin_flash('error', (string) $error);
            }

            admin_redirect('settings');
        }

        if ($action === 'run_render_health') {
            $result = fb_run_render_health_check($config, fb_open_db($config));
            $type = $result['status'] === 'ok' ? 'success' : 'error';
            admin_flash($type, sprintf(
                'Render health %s via %s in %dms: %s',
                $result['status'],
                $result['engine'],
                $result['duration_ms'],
                $result['message']
            ));
            admin_redirect('health');
        }

        if ($action === 'send_health_summary') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $checks = fb_system_health($config, $db);
            $failed = array_values(array_filter($checks, static fn(array $check): bool => empty($check['ok'])));
            $lines = ['Bot health summary', count($failed) === 0 ? 'All checks passed.' : count($failed) . ' check(s) need attention.', ''];
            foreach ($checks as $check) {
                $lines[] = sprintf('%s: %s (%s)', $check['label'], $check['status'], $check['detail']);
            }
            $errorChatId = trim((string) ($config['alerts']['error_alert_chat_id'] ?? ''));
            if ($errorChatId === '') {
                throw new RuntimeException('Configure TELEGRAM_ERROR_CHAT_ID before sending a health summary.');
            }
            $result = fb_telegram_send_message_to_outbox($config, $db, 'admin-health:' . date('YmdHis'), $errorChatId, implode("\n", $lines), 'admin-health');
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['error'] ?? 'Health summary could not be sent.'));
            }
            admin_flash('success', 'Health summary sent to the configured error chat.');
            admin_redirect('health');
        }

        if ($action === 'discover_tv_channels') {
            fb_require_env($config, false);
            $result = fb_discover_tv_channels($config, fb_open_db($config));
            admin_flash('success', sprintf(
                'TV discovery scanned %d listing row(s) across %d endpoint(s) and found %d channel(s).',
                $result['rows'],
                $result['paths'],
                $result['channels']
            ));

            foreach (array_slice($result['errors'], 0, 3) as $error) {
                admin_flash('error', $error);
            }

            admin_redirect('data');
        }

        if ($action === 'clear_api_cache') {
            $db = fb_open_db($config);
            $db->exec('DELETE FROM api_cache');
            admin_flash('success', 'API cache cleared.');
            admin_redirect('data');
        }

        if ($action === 'reset_state') {
            $lock = fb_acquire_run_lock($config);

            if ($lock === null) {
                throw new RuntimeException('A live check is running. Try resetting state again in a moment.');
            }

            try {
                admin_delete_state_database($config);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            admin_flash('success', 'Match state and sent-alert history reset.');
            admin_redirect('system');
        }
    }
} catch (Throwable $error) {
    admin_flash('error', $error->getMessage());
    admin_redirect(admin_action_view($action));
}

$env = admin_read_env_file();
$hasPassword = !empty($env['BOT_ADMIN_PASSWORD_HASH']);
$loggedIn = admin_is_logged_in($env);
$config = fb_config(true);
fb_ensure_directories($config);
$stateCounts = $loggedIn ? admin_state_counts($config) : ['matches' => 0, 'alerts' => 0, 'cache' => 0, 'coverage_sports' => 0, 'coverage_leagues' => 0, 'cards_pending' => 0, 'cards_sent' => 0, 'cards_failed' => 0, 'dispatch_failed' => 0, 'outbox_pending' => 0, 'outbox_failed' => 0, 'decisions' => 0, 'alert_types' => []];
$rateLimitInfo = $loggedIn ? admin_rate_limit_info($config) : [];
$tvChannels = $loggedIn ? fb_tv_channels($config) : [];
$tvChannelRegistry = $loggedIn ? admin_tv_channel_registry($config) : [];
$coverageSports = $loggedIn ? admin_coverage_sports($config) : [];
$coverageLeagues = $loggedIn ? admin_coverage_leagues($config) : [];
$sportProfiles = $loggedIn ? fb_sport_profiles($config) : [];
$routeMatrix = $loggedIn ? admin_route_matrix($config) : [];
$healthChecks = $loggedIn ? fb_system_health($config, is_file($config['paths']['state_db']) ? fb_open_db($config) : null) : [];
$outboxItems = $loggedIn ? admin_outbox_items($config) : [];
$customerFollows = $loggedIn ? admin_customer_follow_state($config) : ['counts' => ['total' => 0, 'teams' => 0, 'players' => 0, 'feeds' => 0, 'users' => 0], 'recent' => []];
$alertDecisions = $loggedIn ? admin_alert_decisions($config) : [];
$renderHealthChecks = $loggedIn ? admin_render_health_checks($config) : [];
$recentAlerts = $loggedIn ? admin_recent_alerts($config) : [];
$recentMatches = $loggedIn ? admin_recent_matches($config) : [];
$cacheEntries = $loggedIn ? admin_cache_entries($config) : [];
$cardJobs = $loggedIn ? admin_card_jobs($config) : [];
$cardDispatches = $loggedIn ? admin_card_dispatches($config) : [];
$latestImages = $loggedIn ? admin_latest_images($config) : [];
$botLog = $loggedIn ? admin_tail_file($config['app']['log_file']) : '';
$cronLog = $loggedIn ? admin_tail_file($config['paths']['logs'] . '/cron.log') : '';
$flash = admin_take_flash();
$csrf = admin_csrf_token();
$availableLeagues = $config['leagues']['available'] ?? $config['leagues']['allowed'];
$allowedLeagueIds = array_keys($config['leagues']['allowed']);
$registrySlugs = array_map(static fn (array $channel): string => (string) $channel['channel_slug'], $tvChannelRegistry);
$configuredTvSlugs = fb_tv_configured_channel_slugs($config);
$manualTvSlugs = array_values(array_diff($configuredTvSlugs, $registrySlugs));
$availableSports = $config['sports']['available'] ?? [];
$enabledSportKeys = fb_enabled_sport_keys($config);
$telegramRouteSports = admin_telegram_route_sport_options($config, $availableSports);
$telegramRouteRows = admin_telegram_route_form_rows($config, $availableSports);
$telegramTopics = admin_telegram_topics($config);
$configuredCoverageLeagueIds = $config['coverage']['enabled_league_ids'] ?? [];
$coverageRegistryIds = array_map(static fn (array $league): string => (string) $league['league_id'], $coverageLeagues);
$manualCoverageLeagueIds = array_values(array_diff(array_map('strval', $configuredCoverageLeagueIds), $coverageRegistryIds));
$adminViews = admin_views();
$activeView = $loggedIn ? admin_current_view() : 'dashboard';
$activeViewMeta = $adminViews[$activeView] ?? $adminViews['dashboard'];

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sports Alert Bot Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ── Navy Dark Theme — easy on the eyes, Telegram-style ── */
        :root {
            color-scheme: dark;
            --bg: #0a0e1a;
            --surface: #0f1628;
            --surface-2: #141e35;
            --surface-3: #1a2742;
            --field: #0b1020;
            --line: rgba(148, 163, 194, 0.12);
            --line-strong: rgba(148, 163, 194, 0.22);
            --text: #e8edf5;
            --muted: #8b9dc3;
            --soft: #6b7fa3;
            --accent: #4a90d9;
            --accent-2: #3d7fc4;
            --danger: #ff4a5c;
            --warn: #ffbe4b;
            --info: #83a7ff;
            --shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
        }

        /* ── Bootstrap dark overrides — kill all white backgrounds ── */
        .card, .badge, .table, .table th, .table td,
        .form-control, .form-select, .form-check-input,
        .list-group-item, .alert, .modal-content, .dropdown-menu,
        .btn-light, .btn-outline-light, .navbar, .offcanvas,
        .popover, .toast, .progress, .accordion-item,
        .accordion-body, .accordion-button, .page-link,
        .breadcrumb-item, .list-group, .panel, .well {
            background-color: var(--surface) !important;
            color: var(--text) !important;
            border-color: var(--line) !important;
        }

        .table th { background-color: var(--surface-2) !important; }
        .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(148, 163, 194, 0.04) !important; }
        .table-hover tbody tr:hover { background-color: rgba(74, 144, 217, 0.08) !important; }
        .form-control, .form-select { background-color: var(--field) !important; color: var(--text) !important; border-color: var(--line) !important; }
        .form-control:focus, .form-select:focus { border-color: var(--accent) !important; box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15) !important; }
        .form-check-input { background-color: var(--field) !important; border-color: var(--line) !important; }
        .form-check-input:checked { background-color: var(--accent) !important; border-color: var(--accent) !important; }
        .form-check-input:focus { box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15) !important; }
        .modal-backdrop { background-color: rgba(0, 0, 0, 0.7) !important; }
        .text-muted { color: var(--muted) !important; }
        .text-dark { color: var(--text) !important; }
        a { color: var(--accent); }
        a:hover { color: var(--accent-2); }
        ::selection { background: rgba(74, 144, 217, 0.35); color: #fff; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 194, 0.2); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 194, 0.35); }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        a {
            color: inherit;
        }

        .shell {
            width: min(1440px, calc(100% - 28px));
            margin: 0 auto;
            padding: 18px 0 40px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin: 0 0 14px;
            padding: 14px 18px;
            background: rgba(10, 14, 26, 0.88);
            border: 1px solid var(--line);
            border-radius: 8px;
            backdrop-filter: blur(16px);
            box-shadow: 0 10px 32px rgba(0, 0, 0, 0.18);
        }

        h1, h2, h3, p {
            margin: 0;
        }

        h1 {
            font-size: 21px;
            line-height: 1.15;
            font-weight: 800;
        }

        h2 {
            font-size: 15px;
            line-height: 1.2;
            margin-bottom: 12px;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        h3 {
            font-size: 14px;
            line-height: 1.2;
        }

        .muted {
            color: var(--muted);
            line-height: 1.45;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 16px;
        }

        .card {
            grid-column: span 12;
            background: rgba(15, 22, 40, 0.94);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            box-shadow: none;
            transition: border-color 0.2s;
        }

        .card:hover {
            border-color: rgba(148, 163, 194, 0.18);
        }

        .span-4 {
            grid-column: span 4;
        }

        .span-5 {
            grid-column: span 5;
        }

        .span-6 {
            grid-column: span 6;
        }

        .span-7 {
            grid-column: span 7;
        }

        .span-8 {
            grid-column: span 8;
        }

        label {
            display: block;
            color: var(--soft);
            font-size: 12px;
            font-weight: 800;
            margin: 12px 0 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        input, textarea, select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--field);
            color: var(--text);
            padding: 0 12px;
            font-size: 14px;
        }

        input, select {
            height: 40px;
        }

        textarea {
            min-height: 118px;
            padding-top: 12px;
            padding-bottom: 12px;
            line-height: 1.45;
            resize: vertical;
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            color: var(--text);
            font-size: 14px;
        }

        .checkbox input {
            width: 18px;
            height: 18px;
            padding: 0;
            flex: 0 0 auto;
            accent-color: var(--accent);
        }

        input:focus, textarea:focus, select:focus {
            border-color: rgba(74, 144, 217, 0.66);
            box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.12);
            outline: none;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-top: 18px;
        }

        button, .button {
            height: 38px;
            border: 0;
            border-radius: 6px;
            padding: 0 13px;
            background: #4a90d9;
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 8px 18px rgba(74, 144, 217, 0.13);
            transition: filter 0.15s, transform 0.1s;
        }

        button:hover, .button:hover {
            filter: brightness(1.08);
        }

        button:active, .button:active {
            transform: scale(0.98);
        }

        button.secondary, .button.secondary {
            background: #1a2742;
            color: #e8edf5;
            box-shadow: none;
        }

        button.secondary:hover, .button.secondary:hover {
            background: #1e2f50;
        }

        button.warning {
            background: #e6b95f;
            color: #181109;
            box-shadow: none;
        }

        button.danger {
            background: #e86d66;
            color: #fff7f5;
            box-shadow: none;
        }

        button.danger:hover {
            background: #f07a74;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .stat {
            background: var(--surface-2);
            border-radius: 8px;
            padding: 14px;
            border: 1px solid var(--line);
        }

        .stat strong {
            display: block;
            font-size: 26px;
            margin-top: 4px;
        }

        .flash {
            display: grid;
            gap: 8px;
            margin-bottom: 16px;
        }

        .notice {
            border-radius: 6px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            background: rgba(15, 22, 40, 0.96);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.22);
        }

        .notice.success {
            border-color: rgba(74, 144, 217, 0.4);
        }

        .notice.error {
            border-color: rgba(239, 106, 99, 0.55);
        }

        .notice.info {
            border-color: rgba(131, 167, 255, 0.45);
        }

        .status-list {
            display: grid;
            gap: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        .status-list b {
            color: var(--text);
        }

        pre {
            max-height: 420px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            background: #080c18;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 14px;
            color: #c8d6e5;
            font-size: 13px;
        }

        .images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 12px;
        }

        .images img {
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: var(--field);
        }

        .login {
            width: min(480px, 100%);
            margin: 10vh auto 0;
            background: rgba(15, 22, 40, 0.96);
            box-shadow: var(--shadow);
        }

        .topline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 0 12px;
            background: rgba(148, 163, 194, 0.055);
            color: var(--muted);
            font-size: 12px;
        }

        .pill strong {
            color: var(--text);
            margin-left: 6px;
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .metric {
            min-height: 86px;
            background: rgba(10, 14, 26, 0.46);
            border: 1px solid rgba(148, 163, 194, 0.1);
            border-radius: 6px;
            padding: 13px;
            box-shadow: none;
        }

        .metric span {
            display: block;
            color: var(--soft);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .metric strong {
            display: block;
            font-size: 31px;
            line-height: 1;
            margin-top: 9px;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .nav a {
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 9px 12px;
            color: var(--muted);
            text-decoration: none;
            background: rgba(148, 163, 194, 0.05);
            font-size: 14px;
            transition: border-color 0.15s, color 0.15s, background 0.15s;
        }

        .nav a:hover {
            border-color: rgba(74, 144, 217, 0.6);
            color: var(--text);
            background: rgba(74, 144, 217, 0.08);
        }

        .app-layout {
            display: grid;
            grid-template-columns: 284px minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        .sidebar-card {
            position: sticky;
            top: 84px;
            min-height: 0;
            max-height: calc(100dvh - 104px);
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
            background: linear-gradient(180deg, rgba(15, 22, 40, 0.96), rgba(10, 14, 26, 0.96));
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            box-shadow: var(--shadow);
        }

        .sidebar-card::-webkit-scrollbar {
            width: 10px;
        }

        .sidebar-card::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-card::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 194, 0.16);
            border: 3px solid transparent;
            border-radius: 999px;
            background-clip: padding-box;
        }

        .sidebar-card::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 194, 0.28);
            border: 3px solid transparent;
            background-clip: padding-box;
        }

        .sidebar-card h2 {
            font-size: 16px;
            text-transform: none;
            letter-spacing: 0;
            margin-bottom: 5px;
        }

        .sidebar-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .sidebar-card .nav {
            display: grid;
            gap: 6px;
            margin: 18px 0;
        }

        .sidebar-card .nav a {
            display: flex;
            justify-content: space-between;
            min-height: 38px;
            align-items: center;
            border-radius: 6px;
            border-color: transparent;
            background: transparent;
            color: #c8d6e5;
            font-weight: 700;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .sidebar-card .nav a:hover {
            background: rgba(74, 144, 217, 0.1);
            border-color: rgba(74, 144, 217, 0.22);
            color: var(--text);
        }

        .sidebar-card .nav a.active {
            background: rgba(74, 144, 217, 0.16);
            border-color: rgba(74, 144, 217, 0.34);
            color: var(--text);
        }

        .sidebar-card .nav a span {
            min-width: 22px;
            border-radius: 5px;
            padding: 2px 6px;
            background: rgba(148, 163, 194, 0.06);
            color: var(--muted);
            text-align: center;
            font-size: 11px;
        }

        .nav-tree {
            border-top: 1px solid rgba(148, 163, 194, 0.08);
            padding-top: 12px;
        }

        .nav-group {
            border-bottom: 1px solid rgba(148, 163, 194, 0.07);
            padding: 4px 0 8px;
        }

        .nav-group summary {
            display: flex;
            align-items: center;
            min-height: 30px;
            color: var(--soft);
            cursor: pointer;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            list-style: none;
            text-transform: uppercase;
        }

        .nav-group summary::-webkit-details-marker {
            display: none;
        }

        .nav-group summary::after {
            content: "+";
            margin-left: auto;
            color: var(--muted);
        }

        .nav-group[open] summary::after {
            content: "−";
        }

        .sidebar-widgets {
            display: grid;
            gap: 10px;
            margin: 14px 0;
        }

        .side-widget {
            border: 1px solid rgba(148, 163, 194, 0.1);
            border-radius: 8px;
            background: rgba(10, 14, 26, 0.38);
            padding: 12px;
        }

        .widget-title {
            color: var(--soft);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .widget-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-height: 25px;
            color: var(--muted);
            font-size: 12px;
        }

        .widget-row b {
            color: var(--text);
            font-size: 12px;
            text-align: right;
        }

        .widget-meter {
            height: 6px;
            border-radius: 999px;
            background: rgba(148, 163, 194, 0.08);
            margin: 2px 0 9px;
            overflow: hidden;
        }

        .widget-meter span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
        }

        .main-pane {
            min-width: 0;
        }

        .main-pane.grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 14px;
        }

        .page-title {
            grid-column: 1 / -1;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            padding: 4px 2px 6px;
        }

        .page-title span {
            display: block;
            color: var(--soft);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .page-title h2 {
            margin: 4px 0 5px;
            color: var(--text);
            font-size: 24px;
            letter-spacing: 0;
            text-transform: none;
        }

        .page-title .badge {
            flex: 0 0 auto;
        }

        .ops-hero {
            background:
                linear-gradient(135deg, rgba(74, 144, 217, 0.14), rgba(61, 127, 196, 0.08) 38%, rgba(15, 22, 40, 0.96) 72%),
                var(--surface);
            border-color: rgba(74, 144, 217, 0.22);
        }

        .ops-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(300px, 0.75fr);
            gap: 16px;
            align-items: start;
        }

        .queue-list {
            display: grid;
            gap: 8px;
        }

        .queue-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 11px;
            background: rgba(10, 14, 26, 0.42);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 22px;
            border-radius: 5px;
            padding: 0 8px;
            background: rgba(74, 144, 217, 0.13);
            color: #7ab8e8;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .badge.warn {
            background: rgba(255, 190, 75, 0.15);
            color: #f0c76f;
        }

        .badge.danger {
            background: rgba(255, 74, 92, 0.14);
            color: #ff8a82;
        }

        .mini-actions {
            display: grid;
            gap: 8px;
        }

        .mini-actions button {
            width: 100%;
            justify-content: center;
        }

        .form-section {
            border-top: 1px solid rgba(148, 163, 194, 0.1);
            padding-top: 18px;
            margin-top: 18px;
        }

        .form-section:first-of-type {
            border-top: 0;
            padding-top: 0;
            margin-top: 0;
        }

        .section-title {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .section-title h3 {
            font-size: 16px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 14px;
        }

        .field-grid.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .field-full {
            grid-column: 1 / -1;
        }

        .route-builder {
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }

        .route-builder-row {
            display: grid;
            grid-template-columns: minmax(150px, 1fr) minmax(190px, 1.2fr) minmax(120px, 0.7fr);
            gap: 10px;
            align-items: end;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px;
            background: rgba(148, 163, 194, 0.04);
        }

        .route-builder-row label {
            margin-top: 0;
        }

        .toggle-grid, .league-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 12px;
        }

        .toggle, .league-option {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            border: 1px solid rgba(148, 163, 194, 0.1);
            border-radius: 6px;
            padding: 10px;
            background: rgba(10, 14, 26, 0.35);
            color: var(--text);
            min-height: 50px;
            transition: border-color 0.15s, background 0.15s;
        }

        .toggle:hover, .league-option:hover {
            border-color: rgba(74, 144, 217, 0.28);
            background: rgba(74, 144, 217, 0.055);
        }

        .toggle input, .league-option input {
            width: 18px;
            height: 18px;
            margin-top: 1px;
            flex: 0 0 auto;
            accent-color: var(--accent);
        }

        .toggle b, .league-option b {
            font-size: 13px;
            line-height: 1.25;
        }

        .toggle span, .league-option span {
            display: block;
            color: var(--soft);
            font-size: 12px;
            margin-top: 3px;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
        }

        .table th {
            position: sticky;
            top: 68px;
            z-index: 1;
            background: var(--surface);
            color: var(--soft);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .table th, .table td {
            border-bottom: 1px solid rgba(148, 163, 194, 0.08);
            padding: 8px 9px;
            text-align: left;
            vertical-align: top;
        }

        .table td {
            color: #d8e6f7;
        }

        .table tr:hover td {
            background: rgba(148, 163, 194, 0.035);
        }

        .split {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .sticky-actions {
            position: sticky;
            bottom: 0;
            margin: 22px -20px -20px;
            padding: 14px 20px;
            background: rgba(10, 14, 26, 0.96);
            border-top: 1px solid rgba(148, 163, 194, 0.12);
            border-radius: 0 0 8px 8px;
            backdrop-filter: blur(12px);
        }

        .flash {
            position: sticky;
            top: 78px;
            z-index: 30;
        }

        @media (max-width: 1180px) {
            .app-layout {
                grid-template-columns: 236px minmax(0, 1fr);
            }

            .metric-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .shell {
                width: min(100% - 14px, 720px);
                padding: 7px 0 28px;
            }

            header {
                position: static;
                margin-bottom: 8px;
                padding: 12px;
            }

            .span-4, .span-5, .span-6, .span-7, .span-8 {
                grid-column: span 12;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .sidebar-card {
                position: sticky;
                top: 7px;
                z-index: 18;
                height: auto;
                max-height: 58dvh;
                min-height: 0;
                overflow-y: auto;
                padding: 12px;
                box-shadow: 0 14px 36px rgba(0, 0, 0, 0.34);
            }

            .sidebar-head {
                align-items: center;
            }

            .sidebar-head h2 {
                font-size: 15px;
            }

            .sidebar-head .muted {
                display: none;
            }

            .sidebar-card .nav {
                margin: 10px 0;
            }

            .nav-tree {
                padding-top: 8px;
            }

            .nav-group {
                padding-bottom: 5px;
            }

            .nav-group summary {
                min-height: 34px;
            }

            .sidebar-card .nav a {
                min-height: 34px;
                padding: 8px 10px;
                font-size: 13px;
            }

            .sidebar-widgets {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .side-widget {
                padding: 9px;
            }

            .side-widget:last-child {
                grid-column: 1 / -1;
            }

            .widget-title {
                margin-bottom: 5px;
            }

            .widget-row {
                min-height: 22px;
            }

            .mini-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .mini-actions button {
                min-width: 0;
                padding: 0 9px;
                font-size: 12px;
            }

            .metric-grid, .field-grid, .field-grid.three, .toggle-grid, .league-grid, .route-builder-row, .split, .app-layout, .ops-grid {
                grid-template-columns: 1fr;
            }

            .table {
                display: block;
                overflow-x: auto;
            }

            .status-list > div {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 520px) {
            h1 {
                font-size: 18px;
            }

            .sidebar-card {
                max-height: 64dvh;
            }

            .sidebar-widgets, .mini-actions {
                grid-template-columns: 1fr;
            }

            .metric strong {
                font-size: 26px;
            }

            .card {
                padding: 14px;
            }
        }

    </style>
</head>
<body>
<main class="shell">
    <header>
        <div>
            <h1>Sports Alert Bot</h1>
            <p class="muted">Football alerts, TV listings, testing, and monitoring</p>
        </div>
        <?php if ($loggedIn): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="secondary" type="submit">Sign Out</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($flash !== []): ?>
        <div class="flash">
            <?php foreach ($flash as $message): ?>
                <div class="notice <?= htmlspecialchars((string) $message['type']) ?>">
                    <?= htmlspecialchars((string) $message['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasPassword): ?>
        <section class="card login">
            <h2>Create Admin Password</h2>
            <p class="muted">This locks the setup panel before you add API keys.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="bootstrap">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
                <label for="confirm_password">Confirm Password</label>
                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="12">
                <div class="actions">
                    <button type="submit">Create Password</button>
                </div>
            </form>
        </section>
    <?php elseif (!$loggedIn): ?>
        <section class="card login">
            <h2>Sign In</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="login">
                <label for="password">Admin Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <div class="actions">
                    <button type="submit">Sign In</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="app-layout">
            <aside class="sidebar-card">
                <div class="sidebar-head">
                    <div>
                        <h2>Control Centre</h2>
                        <p class="muted">Private group operations</p>
                    </div>
                    <span class="badge <?= (int) ($stateCounts['outbox_failed'] ?? 0) > 0 ? 'danger' : '' ?>"><?= (int) ($stateCounts['outbox_failed'] ?? 0) ?> fail</span>
                </div>
                <nav class="nav nav-tree" aria-label="Admin sections">
                    <details class="nav-group" <?= in_array($activeView, ['dashboard', 'health', 'logs'], true) ? 'open' : '' ?>>
                        <summary>Overview</summary>
                        <?= admin_nav_link($activeView, 'dashboard', 'Dashboard', (int) ($stateCounts['matches'] ?? 0)) ?>
                        <?= admin_nav_link($activeView, 'health', 'Health', count($healthChecks)) ?>
                        <?= admin_nav_link($activeView, 'logs', 'Logs', 2) ?>
                    </details>
                    <details class="nav-group" <?= in_array($activeView, ['publishing', 'activity', 'media'], true) ? 'open' : '' ?>>
                        <summary>Publishing</summary>
                        <?= admin_nav_link($activeView, 'publishing', 'Studio', count($cardJobs)) ?>
                        <?= admin_nav_link($activeView, 'activity', 'Activity', (int) (($stateCounts['outbox_pending'] ?? 0) + ($stateCounts['outbox_failed'] ?? 0))) ?>
                        <?= admin_nav_link($activeView, 'media', 'Images', count($latestImages)) ?>
                    </details>
                    <details class="nav-group" <?= in_array($activeView, ['routing', 'settings'], true) ? 'open' : '' ?>>
                        <summary>Routing</summary>
                        <?= admin_nav_link($activeView, 'routing', 'Profiles', count($config['coverage']['enabled_sports'] ?? [])) ?>
                        <?= admin_nav_link($activeView, 'settings', 'Routes And Rules', count($routeMatrix)) ?>
                    </details>
                    <details class="nav-group" <?= in_array($activeView, ['data', 'system'], true) ? 'open' : '' ?>>
                        <summary>Data Sources</summary>
                        <?= admin_nav_link($activeView, 'data', 'Coverage And TV', count($coverageLeagues)) ?>
                        <?= admin_nav_link($activeView, 'system', 'Operations', (int) ($stateCounts['cache'] ?? 0)) ?>
                        <?= admin_nav_link($activeView, 'settings', 'Settings', '>') ?>
                    </details>
                </nav>

                <div class="sidebar-widgets">
                    <section class="side-widget">
                        <div class="widget-title">System</div>
                        <div class="widget-row"><span>Telegram</span><b><?= !empty($env['TELEGRAM_BOT_TOKEN']) ? 'Ready' : 'Missing' ?></b></div>
                        <div class="widget-row"><span>TheSportsDB</span><b><?= !empty($env['THESPORTSDB_API_KEY']) ? 'Ready' : 'Missing' ?></b></div>
                        <div class="widget-row"><span>Renderer</span><b><?= htmlspecialchars((string) ($config['images']['render_engine'] ?? 'auto')) ?></b></div>
                    </section>
                    <section class="side-widget">
                        <div class="widget-title">Queue</div>
                        <div class="widget-meter"><span style="width: <?= min(100, max(4, ((int) ($stateCounts['cards_pending'] ?? 0)) * 12)) ?>%"></span></div>
                        <div class="widget-row"><span>Pending cards</span><b><?= (int) ($stateCounts['cards_pending'] ?? 0) ?></b></div>
                        <div class="widget-row"><span>Outbox pending</span><b><?= (int) ($stateCounts['outbox_pending'] ?? 0) ?></b></div>
                        <div class="widget-row"><span>Follows</span><b><?= (int) ($customerFollows['counts']['total'] ?? 0) ?></b></div>
                    </section>
                    <section class="side-widget">
                        <div class="widget-title">Runtime</div>
                        <div class="widget-row"><span>Cards</span><b><?= !empty($config['cards']['bursts_enabled']) ? 'Burst' : 'Daily' ?></b></div>
                        <div class="widget-row"><span>Timezone</span><b><?= htmlspecialchars((string) $config['app']['timezone']) ?></b></div>
                        <div class="widget-row"><span>Last API</span><b><?= isset($rateLimitInfo['last_request_ago_ms']) ? (int) $rateLimitInfo['last_request_ago_ms'] . 'ms' : 'None' ?></b></div>
                    </section>
                </div>

                <form class="mini-actions" method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button name="action" value="dry_run" type="submit">Dry Check</button>
                    <button class="secondary" name="action" value="generate_card_preview" type="submit">Preview Card</button>
                    <button class="secondary" name="action" value="send_cards_now" type="submit">Send Cards Now</button>
                    <button class="warning" name="action" value="retry_failed_cards" type="submit">Retry Failed Cards</button>
                </form>
            </aside>

            <section class="main-pane grid">
            <div class="page-title">
                <div>
                    <span><?= htmlspecialchars((string) $activeViewMeta['section']) ?></span>
                    <h2><?= htmlspecialchars((string) $activeViewMeta['title']) ?></h2>
                    <p class="muted"><?= htmlspecialchars((string) $activeViewMeta['description']) ?></p>
                </div>
                <span class="badge"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $activeView))) ?></span>
            </div>
            <?php if ($activeView === 'dashboard'): ?>
            <div class="card ops-hero" id="dashboard">
                <div class="ops-grid">
                    <div>
                        <h2>Operations Dashboard</h2>
                        <div class="metric-grid" style="margin-top:14px">
                            <div class="metric"><span>Pending Cards</span><strong><?= (int) ($stateCounts['cards_pending'] ?? 0) ?></strong></div>
                            <div class="metric"><span>Sent Card Jobs</span><strong><?= (int) ($stateCounts['cards_sent'] ?? 0) ?></strong></div>
                            <div class="metric"><span>Failed Cards</span><strong><?= (int) ($stateCounts['cards_failed'] ?? 0) ?></strong></div>
                            <div class="metric"><span>Outbox Failed</span><strong><?= (int) ($stateCounts['outbox_failed'] ?? 0) ?></strong></div>
                            <div class="metric"><span>Tracked Matches</span><strong><?= (int) $stateCounts['matches'] ?></strong></div>
                            <div class="metric"><span>Sent Alerts</span><strong><?= (int) $stateCounts['alerts'] ?></strong></div>
                            <div class="metric"><span>Decision Logs</span><strong><?= (int) ($stateCounts['decisions'] ?? 0) ?></strong></div>
                            <div class="metric"><span>TV Channels</span><strong><?= count($tvChannels) ?></strong></div>
                            <div class="metric"><span>Customer Follows</span><strong><?= (int) ($customerFollows['counts']['total'] ?? 0) ?></strong></div>
                        </div>
                    </div>
                    <div>
                        <h2>Next Card Queue</h2>
                        <div class="queue-list">
                            <?php if ($cardJobs === []): ?>
                                <p class="muted">No card jobs queued yet. Run a dry check or send cards now.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($cardJobs, 0, 5) as $job): ?>
                                    <?php
                                        $status = (string) ($job['status'] ?? '');
                                        $badgeClass = $status === 'failed' ? 'danger' : ($status === 'pending' ? 'warn' : '');
                                    ?>
                                    <div class="queue-item">
                                        <div>
                                            <b><?= htmlspecialchars((string) $job['card_type']) ?></b>
                                            <p class="muted"><?= htmlspecialchars((string) ($job['sport'] ?: $job['route_key'])) ?> · <?= (int) $job['page_count'] ?> page(s)</p>
                                        </div>
                                        <span class="badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($status) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="topline">
                    <span class="pill">State DB <strong><?= is_file($config['paths']['state_db']) ? 'Ready' : 'Pending' ?></strong></span>
                    <span class="pill">Notifications <strong><?= !empty($config['telegram']['disable_notification']) ? 'Quiet' : 'Normal' ?></strong></span>
                    <span class="pill">Last API Call <strong><?= isset($rateLimitInfo['last_request_ago_ms']) ? (int) $rateLimitInfo['last_request_ago_ms'] . 'ms ago' : 'None' ?></strong></span>
                    <span class="pill">Routes <strong><?= admin_env_value($env, 'BOT_TELEGRAM_ROUTES_JSON') !== '' ? 'Configured' : 'Default only' ?></strong></span>
                </div>
            </div>

            <?php elseif ($activeView === 'publishing'): ?>
            <div class="card span-6" id="card-studio">
                <h2>Card Studio</h2>
                <p class="muted">Preview, send, and retry the new burst cards without waiting for cron.</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="field-grid">
                        <div>
                            <label for="preview_card_type">Preview Card Type</label>
                            <select id="preview_card_type" name="preview_card_type">
                                <option value="">First due card</option>
                                <option value="FIXTURES_BURST">Fixtures burst</option>
                                <option value="KICKOFF_SOON">Kick-off soon</option>
                                <option value="LIVE_NOW">Live now</option>
                                <option value="TV_GUIDE">TV guide</option>
                                <option value="TV_NOW">TV now</option>
                                <option value="RESULTS_ROUNDUP">Results roundup</option>
                                <option value="MORNING_PLANNER">Morning planner</option>
                                <option value="TOMORROW_LOOKAHEAD">Tomorrow lookahead</option>
                                <option value="WEEKEND_PLANNER">Weekend planner</option>
                            </select>
                        </div>
                    </div>
                    <div class="actions">
                        <button name="action" value="generate_card_preview" type="submit">Generate Preview</button>
                        <button class="secondary" name="action" value="send_cards_now" type="submit">Send Due Cards</button>
                        <button class="warning" name="action" value="retry_failed_cards" type="submit">Retry Failed</button>
                    </div>
                </form>
                <?php if ($cardDispatches !== []): ?>
                    <table class="table" style="margin-top:14px">
                        <thead><tr><th>Job</th><th>Chat</th><th>Page</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($cardDispatches, 0, 6) as $dispatch): ?>
                            <tr>
                                <td><?= htmlspecialchars(substr((string) $dispatch['job_key'], 0, 28)) ?></td>
                                <td><?= htmlspecialchars(admin_mask((string) $dispatch['chat_id'])) ?></td>
                                <td><?= (int) $dispatch['page_no'] ?></td>
                                <td><?= htmlspecialchars((string) $dispatch['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6" id="scheduler">
                <h2>Scheduler</h2>
                <div class="status-list">
                    <div>Mode: <b><?= !empty($config['cards']['bursts_enabled']) ? 'Smart event bursts' : 'Legacy daily cards' ?></b></div>
                    <div>Card types: <b><?= htmlspecialchars(implode(', ', $config['cards']['types_enabled'] ?? [])) ?></b></div>
                    <div>Content packs: <b><?= htmlspecialchars(implode(', ', $config['content']['packs_enabled'] ?? [])) ?></b></div>
                    <div>Cooldown: <b><?= (int) ($config['cards']['burst_cooldown_minutes'] ?? 60) ?> min</b></div>
                    <div>Public card cap: <b><?= (int) ($config['cards']['max_items_per_type'] ?? 4) ?> per type</b></div>
                    <div>Max pages/run: <b><?= (int) ($config['cards']['max_pages_per_run'] ?? 12) ?></b></div>
                    <div>Max jobs/run: <b><?= (int) ($config['cards']['max_sends_per_run'] ?? 12) ?></b></div>
                </div>
                <div class="actions">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <button name="action" value="dry_run" type="submit">Show Due Cards</button>
                    </form>
                </div>
            </div>

            <div class="card span-6" id="customer-guide">
                <h2>Customer Guide</h2>
                <div class="status-list">
                    <div>Guide: <b><?= !empty($config['customer']['guide_enabled']) ? htmlspecialchars(fb_customer_guide_time($config)) : 'Disabled' ?></b></div>
                    <div>Lookahead: <b><?= (int) ($config['customer']['guide_lookahead_hours'] ?? 24) ?> hours</b></div>
                    <div>Follow buttons: <b><?= !empty($config['customer']['follow_buttons_enabled']) ? 'On' : 'Off' ?></b></div>
                    <div>Users following: <b><?= (int) ($customerFollows['counts']['users'] ?? 0) ?></b></div>
                    <div>Teams: <b><?= (int) ($customerFollows['counts']['teams'] ?? 0) ?></b></div>
                    <div>Players: <b><?= (int) ($customerFollows['counts']['players'] ?? 0) ?></b></div>
                    <div>Feeds: <b><?= (int) ($customerFollows['counts']['feeds'] ?? 0) ?></b></div>
                </div>
                <form class="actions" method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button name="action" value="send_customer_guide_test" type="submit">Send Guide Test</button>
                    <a class="button secondary" href="<?= htmlspecialchars(admin_view_url('settings')) ?>#customer-guide-settings">Guide Settings</a>
                </form>
                <?php if (($customerFollows['recent'] ?? []) === []): ?>
                    <p class="muted">No customer follows yet. Guide messages can include buttons such as Follow Arsenal or Follow England.</p>
                <?php else: ?>
                    <table class="table" style="margin-top:14px">
                        <thead><tr><th>Subject</th><th>Kind</th><th>User</th><th>Target</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($customerFollows['recent'], 0, 8) as $follow): ?>
                            <?php
                                $target = (string) ($follow['chat_id'] ?? '');
                                if (!empty($follow['message_thread_id'])) {
                                    $target .= ':' . (int) $follow['message_thread_id'];
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $follow['subject']) ?></td>
                                <td><?= htmlspecialchars((string) $follow['kind']) ?></td>
                                <td><?= htmlspecialchars((string) ($follow['username'] ?: $follow['telegram_user_id'])) ?></td>
                                <td><?= htmlspecialchars(admin_mask($target)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php elseif ($activeView === 'settings'): ?>
            <div class="card page-wide" id="settings">
                <h2>Control Panel</h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="save_settings">

                    <div class="form-section">
                        <div class="section-title"><h3>Connections</h3></div>
                        <div class="field-grid">
                            <div class="field-full">
                                <label for="TELEGRAM_BOT_TOKEN">Telegram Bot Token</label>
                                <input id="TELEGRAM_BOT_TOKEN" name="TELEGRAM_BOT_TOKEN" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_BOT_TOKEN')) ?>" autocomplete="off">
                            </div>
                            <div>
                                <label for="TELEGRAM_CHAT_ID">Primary Chat ID</label>
                                <input id="TELEGRAM_CHAT_ID" name="TELEGRAM_CHAT_ID" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_CHAT_ID')) ?>" autocomplete="off">
                            </div>
                            <div>
                                <label for="TELEGRAM_MESSAGE_THREAD_ID">Default Topic ID</label>
                                <input id="TELEGRAM_MESSAGE_THREAD_ID" name="TELEGRAM_MESSAGE_THREAD_ID" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_MESSAGE_THREAD_ID')) ?>" autocomplete="off" inputmode="numeric">
                            </div>
                            <div>
                                <label for="TELEGRAM_ERROR_CHAT_ID">Error Chat ID</label>
                                <input id="TELEGRAM_ERROR_CHAT_ID" name="TELEGRAM_ERROR_CHAT_ID" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_ERROR_CHAT_ID')) ?>" autocomplete="off">
                            </div>
                            <div class="field-full">
                                <label for="TELEGRAM_EXTRA_CHAT_IDS">Extra Chat IDs</label>
                                <input id="TELEGRAM_EXTRA_CHAT_IDS" name="TELEGRAM_EXTRA_CHAT_IDS" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_EXTRA_CHAT_IDS')) ?>" autocomplete="off">
                            </div>
                            <div class="field-full">
                                <label for="THESPORTSDB_API_KEY">TheSportsDB API Key</label>
                                <input id="THESPORTSDB_API_KEY" name="THESPORTSDB_API_KEY" value="<?= htmlspecialchars(admin_env_value($env, 'THESPORTSDB_API_KEY')) ?>" autocomplete="off">
                            </div>
                            <div>
                                <label for="BOT_TIMEZONE">Timezone</label>
                                <input id="BOT_TIMEZONE" name="BOT_TIMEZONE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TIMEZONE', 'Europe/London')) ?>">
                            </div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle">
                                <input name="BOT_TELEGRAM_DISABLE_NOTIFICATION" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TELEGRAM_DISABLE_NOTIFICATION', false) ? 'checked' : '' ?>>
                                <b>Quiet Telegram delivery</b>
                            </label>
                            <label class="toggle">
                                <input name="TELEGRAM_UPDATES_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'TELEGRAM_UPDATES_ENABLED', true) ? 'checked' : '' ?>>
                                <b>Process follow buttons<span>Cron polls Telegram callbacks for team/player follows.</span></b>
                            </label>
                        </div>
                    </div>

                    <div class="form-section" id="routes">
                        <div class="section-title"><h3>Telegram Routes</h3><span class="muted">Per sport</span></div>
                        <div class="route-builder">
                            <?php foreach ($telegramRouteRows as $idx => $routeRow): ?>
                                <?php
                                    $rowSport = (string) ($routeRow['sport'] ?? '');
                                    $sportOptions = $telegramRouteSports;

                                    if ($rowSport !== '' && !in_array($rowSport, $sportOptions, true)) {
                                        $sportOptions[] = $rowSport;
                                        natcasesort($sportOptions);
                                    }
                                ?>
                                <div class="route-builder-row">
                                    <div>
                                        <label for="BOT_TELEGRAM_ROUTE_SPORT_<?= (int) $idx ?>">Sport</label>
                                        <select id="BOT_TELEGRAM_ROUTE_SPORT_<?= (int) $idx ?>" name="BOT_TELEGRAM_ROUTE_SPORT[]">
                                            <option value="">Select sport</option>
                                            <?php foreach ($sportOptions as $sportOption): ?>
                                                <option value="<?= htmlspecialchars((string) $sportOption) ?>" <?= $rowSport === (string) $sportOption ? 'selected' : '' ?>><?= htmlspecialchars((string) $sportOption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="BOT_TELEGRAM_ROUTE_CHAT_ID_<?= (int) $idx ?>">Chat ID</label>
                                        <input id="BOT_TELEGRAM_ROUTE_CHAT_ID_<?= (int) $idx ?>" name="BOT_TELEGRAM_ROUTE_CHAT_ID[]" value="<?= htmlspecialchars((string) ($routeRow['chat_id'] ?? '')) ?>" autocomplete="off">
                                    </div>
                                    <div>
                                        <label for="BOT_TELEGRAM_ROUTE_TOPIC_ID_<?= (int) $idx ?>">Topic ID</label>
                                        <input id="BOT_TELEGRAM_ROUTE_TOPIC_ID_<?= (int) $idx ?>" name="BOT_TELEGRAM_ROUTE_TOPIC_ID[]" value="<?= htmlspecialchars((string) ($routeRow['topic_id'] ?? '')) ?>" autocomplete="off" inputmode="numeric">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="field-grid">
                            <div class="field-full">
                                <label class="toggle" style="margin-top:14px">
                                    <input name="BOT_TELEGRAM_ROUTES_USE_ADVANCED" type="checkbox" value="1">
                                    <b>Use advanced routes JSON<span>Skips the sport/topic fields on this save.</span></b>
                                </label>
                                <label for="BOT_TELEGRAM_ROUTES_JSON">Advanced Routes JSON</label>
                                <textarea id="BOT_TELEGRAM_ROUTES_JSON" name="BOT_TELEGRAM_ROUTES_JSON" placeholder='{"Rugby":[{"chat_id":"-100123","thread_id":12}],"Soccer":["-100456"]}'><?= htmlspecialchars(admin_env_value($env, 'BOT_TELEGRAM_ROUTES_JSON')) ?></textarea>
                            </div>
                        </div>
                        <?php if ($routeMatrix !== []): ?>
                            <table class="table" style="margin-top:14px">
                                <thead><tr><th>Sport</th><th>Route</th><th>Targets</th><th>Topics</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($routeMatrix, 0, 20) as $route): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $route['sport']) ?></td>
                                        <td><?= htmlspecialchars((string) $route['route']) ?></td>
                                        <td><?= htmlspecialchars(implode(', ', array_map('admin_mask', $route['chats']))) ?></td>
                                        <td><?= (int) ($route['topics'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <div class="actions" style="margin-top:14px">
                            <button class="secondary" name="action" value="process_telegram_updates" type="submit">Sync Topic IDs</button>
                        </div>
                        <?php if ($telegramTopics !== []): ?>
                            <table class="table" style="margin-top:14px">
                                <thead><tr><th>Topic Name</th><th>Full Target</th><th>Topic ID</th><th>Source</th><th>Link</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($telegramTopics, 0, 24) as $topic): ?>
                                    <?php
                                        $topicChatId = (string) ($topic['chat_id'] ?? '');
                                        $topicId = (int) ($topic['message_thread_id'] ?? 0);
                                        $topicUrl = fb_telegram_topic_url($topicChatId, $topicId);
                                        $topicName = trim((string) ($topic['name'] ?? ''));
                                        $topicFallback = 'Topic ' . $topicId;
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="BOT_TELEGRAM_TOPIC_CHAT_ID[]" value="<?= htmlspecialchars($topicChatId) ?>">
                                            <input type="hidden" name="BOT_TELEGRAM_TOPIC_ID[]" value="<?= $topicId ?>">
                                            <input name="BOT_TELEGRAM_TOPIC_NAME[]" value="<?= htmlspecialchars($topicName) ?>" placeholder="<?= htmlspecialchars($topicFallback) ?>">
                                            <?php if ($topicName === ''): ?>
                                                <small class="muted">Name unknown; label it here or send /topic Name inside the topic.</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($topicChatId . ':' . $topicId) ?></code></td>
                                        <td><?= $topicId ?></td>
                                        <td><?= htmlspecialchars((string) ($topic['source'] ?? 'update')) ?></td>
                                        <td><?= $topicUrl !== '' ? '<a href="' . htmlspecialchars($topicUrl) . '" target="_blank" rel="noreferrer">Open</a>' : '<span class="muted">Unavailable</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="muted" style="margin-top:10px">The usable Telegram topic target is <code>chat_id:topic_id</code>. Topic IDs are not hashed; only unknown names fall back to "Topic 5".</p>
                        <?php else: ?>
                            <p class="muted" style="margin-top:12px">No Telegram topics discovered yet. Send a message or /menu inside each group topic, then sync updates.</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><h3>Leagues</h3><span class="muted"><?= count($allowedLeagueIds) ?> enabled</span></div>
                        <div class="league-grid">
                            <?php foreach ($availableLeagues as $id => $league): ?>
                                <label class="league-option">
                                    <input name="BOT_ALLOWED_LEAGUE_IDS[]" type="checkbox" value="<?= htmlspecialchars((string) $id) ?>" <?= in_array((string) $id, $allowedLeagueIds, true) ? 'checked' : '' ?>>
                                    <b><?= htmlspecialchars((string) $league['name']) ?><span>ID <?= htmlspecialchars((string) $id) ?></span></b>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-section" id="coverage">
                        <div class="section-title"><h3>Coverage</h3><span class="muted"><?= count($coverageLeagues) ?> discovered leagues</span></div>
                        <div class="field-grid three">
                            <div>
                                <label for="BOT_COVERAGE_PRESET">Coverage Preset</label>
                                <input id="BOT_COVERAGE_PRESET" name="BOT_COVERAGE_PRESET" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_COVERAGE_PRESET', 'uk_sports')) ?>">
                            </div>
                            <div>
                                <label for="BOT_COVERAGE_COUNTRIES">Coverage Countries</label>
                                <input id="BOT_COVERAGE_COUNTRIES" name="BOT_COVERAGE_COUNTRIES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_COVERAGE_COUNTRIES', implode(',', $config['coverage']['countries'] ?? []))) ?>">
                            </div>
                            <div>
                                <label for="BOT_MAX_SCHEDULE_LEAGUES">Max Schedule Leagues</label>
                                <input id="BOT_MAX_SCHEDULE_LEAGUES" name="BOT_MAX_SCHEDULE_LEAGUES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_SCHEDULE_LEAGUES', (string) ($config['coverage']['max_schedule_leagues'] ?? 80))) ?>" inputmode="numeric">
                            </div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_AUTO_ENABLE_DISCOVERED_LEAGUES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_AUTO_ENABLE_DISCOVERED_LEAGUES', true) ? 'checked' : '' ?>><b>Auto-enable discovered leagues<span>Uses enabled sports and coverage countries.</span></b></label>
                        </div>
                        <label>Enabled Sports</label>
                        <div class="league-grid">
                            <?php foreach ($availableSports as $sport): ?>
                                <?php $sportKey = fb_sport_key((string) $sport); ?>
                                <label class="league-option">
                                    <input name="BOT_ENABLED_SPORTS[]" type="checkbox" value="<?= htmlspecialchars((string) $sport) ?>" <?= isset($enabledSportKeys[$sportKey]) ? 'checked' : '' ?>>
                                    <b><?= htmlspecialchars((string) $sport) ?></b>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($coverageLeagues !== []): ?>
                            <label>Enabled Coverage Leagues</label>
                            <div class="league-grid">
                                <?php foreach ($coverageLeagues as $league): ?>
                                    <?php
                                        $leagueId = (string) $league['league_id'];
                                        $checked = in_array($leagueId, array_map('strval', $configuredCoverageLeagueIds), true) || ((int) ($league['enabled'] ?? 0) === 1 && $configuredCoverageLeagueIds === []);
                                    ?>
                                    <label class="league-option">
                                        <input name="BOT_ENABLED_LEAGUE_IDS_SELECTED[]" type="checkbox" value="<?= htmlspecialchars($leagueId) ?>" <?= $checked ? 'checked' : '' ?>>
                                        <b><?= htmlspecialchars((string) $league['league_name']) ?><span><?= htmlspecialchars((string) $league['sport']) ?> - <?= htmlspecialchars((string) $league['country']) ?> - ID <?= htmlspecialchars($leagueId) ?><?= !empty($league['live_available']) ? ' - live' : '' ?></span></b>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="field-grid">
                            <div class="field-full">
                                <label for="BOT_ENABLED_LEAGUE_IDS">Manual Extra League IDs</label>
                                <textarea id="BOT_ENABLED_LEAGUE_IDS" name="BOT_ENABLED_LEAGUE_IDS" placeholder="4328&#10;4387"><?= htmlspecialchars(implode("\n", $manualCoverageLeagueIds)) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section" id="alerts">
                        <div class="section-title"><h3>Alert Rules</h3></div>
                        <div class="field-grid three">
                            <div>
                                <label for="BOT_KICKOFF_PROGRESS_MAX">Kick-off Window Minute</label>
                                <input id="BOT_KICKOFF_PROGRESS_MAX" name="BOT_KICKOFF_PROGRESS_MAX" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_KICKOFF_PROGRESS_MAX', '3')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_PREVIEW_HOURS_AHEAD">Preview Hours Ahead</label>
                                <input id="BOT_PREVIEW_HOURS_AHEAD" name="BOT_PREVIEW_HOURS_AHEAD" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_PREVIEW_HOURS_AHEAD', '4')) ?>" inputmode="numeric">
                            </div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_SEND_RED_CARDS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_RED_CARDS', true) ? 'checked' : '' ?>><b>Red cards</b></label>
                            <label class="toggle"><input name="BOT_SEND_YELLOW_CARDS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_YELLOW_CARDS', true) ? 'checked' : '' ?>><b>Yellow cards</b></label>
                            <label class="toggle"><input name="BOT_SEND_SUBSTITUTIONS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_SUBSTITUTIONS', true) ? 'checked' : '' ?>><b>Substitutions</b></label>
                            <label class="toggle"><input name="BOT_SEND_MATCH_STARTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_MATCH_STARTS', false) ? 'checked' : '' ?>><b>Match starts<span>Can be noisy across multi-sport coverage.</span></b></label>
                            <label class="toggle"><input name="BOT_SEND_SCORE_UPDATES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_SCORE_UPDATES', false) ? 'checked' : '' ?>><b>Generic score updates<span>Best kept off unless a topic is dedicated to live scores.</span></b></label>
                            <label class="toggle"><input name="BOT_SEND_PERIOD_CHANGES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_PERIOD_CHANGES', false) ? 'checked' : '' ?>><b>Period changes<span>Quarters, innings, breaks and other status ticks.</span></b></label>
                            <label class="toggle"><input name="BOT_SEND_MATCH_PREVIEWS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_MATCH_PREVIEWS', true) ? 'checked' : '' ?>><b>Match previews</b></label>
                            <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS', false) ? 'checked' : '' ?>><b>First-seen goals<span>Can post mid-match goals after fresh state.</span></b></label>
                            <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS', false) ? 'checked' : '' ?>><b>First-seen full-time</b></label>
                            <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS', false) ? 'checked' : '' ?>><b>First-seen red cards</b></label>
                            <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS', false) ? 'checked' : '' ?>><b>First-seen yellow cards</b></label>
                            <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS', false) ? 'checked' : '' ?>><b>First-seen substitutions</b></label>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><h3>Daily Match Card</h3></div>
                        <div class="field-grid three">
                            <div>
                                <label for="BOT_DAILY_CARD_TIME">Card Time</label>
                                <input id="BOT_DAILY_CARD_TIME" name="BOT_DAILY_CARD_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_DAILY_CARD_TIME', '08:00')) ?>">
                            </div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_SEND_DAILY_CARD" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_DAILY_CARD', true) ? 'checked' : '' ?>><b>Daily match card<span>Send a summary card of all matches for the day.</span></b></label>
                            <label class="toggle"><input name="BOT_DAILY_CARD_SEND_IMAGE" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_DAILY_CARD_SEND_IMAGE', true) ? 'checked' : '' ?>><b>Card as image<span>Generate an image card; otherwise text only.</span></b></label>
                        </div>
                    </div>

                    <div class="form-section" id="customer-guide-settings">
                        <div class="section-title"><h3>Customer Guide</h3><span class="muted">Teams, players, TV</span></div>
                        <div class="field-grid three">
                            <div>
                                <label for="BOT_CUSTOMER_GUIDE_TIME">Guide Time</label>
                                <input id="BOT_CUSTOMER_GUIDE_TIME" name="BOT_CUSTOMER_GUIDE_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CUSTOMER_GUIDE_TIME', '09:00')) ?>">
                            </div>
                            <div>
                                <label for="BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS">Lookahead Hours</label>
                                <input id="BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS" name="BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS', '24')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_MAX_FOLLOW_BUTTONS">Max Follow Buttons</label>
                                <input id="BOT_MAX_FOLLOW_BUTTONS" name="BOT_MAX_FOLLOW_BUTTONS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_FOLLOW_BUTTONS', '8')) ?>" inputmode="numeric">
                            </div>
                            <div class="field-full">
                                <label for="BOT_TEAM_WATCHLIST">Team Watchlist</label>
                                <textarea id="BOT_TEAM_WATCHLIST" name="BOT_TEAM_WATCHLIST" placeholder="Arsenal&#10;England&#10;Boston Celtics"><?= htmlspecialchars(admin_env_value($env, 'BOT_TEAM_WATCHLIST')) ?></textarea>
                            </div>
                            <div class="field-full">
                                <label for="BOT_PLAYER_WATCHLIST">Player Watchlist</label>
                                <textarea id="BOT_PLAYER_WATCHLIST" name="BOT_PLAYER_WATCHLIST" placeholder="Bukayo Saka&#10;Jude Bellingham"><?= htmlspecialchars(admin_env_value($env, 'BOT_PLAYER_WATCHLIST')) ?></textarea>
                            </div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_CUSTOMER_GUIDE_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_CUSTOMER_GUIDE_ENABLED', true) ? 'checked' : '' ?>><b>Daily customer guide<span>Scores, fixtures, channels and followed-team highlights.</span></b></label>
                            <label class="toggle"><input name="BOT_FOLLOW_BUTTONS_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_FOLLOW_BUTTONS_ENABLED', true) ? 'checked' : '' ?>><b>Follow buttons<span>Add inline buttons under customer guide messages.</span></b></label>
                        </div>
                    </div>

                    <div class="form-section" id="scheduler-settings">
                        <div class="section-title"><h3>Burst Card Scheduler</h3><span class="muted">Multiple cards</span></div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_CARD_BURSTS_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_CARD_BURSTS_ENABLED', true) ? 'checked' : '' ?>><b>Smart burst cards<span>Use the card queue instead of one daily digest.</span></b></label>
                        </div>
                        <div class="field-grid three">
                            <div>
                                <label for="BOT_CARD_ROUTE_MODE">Route Mode</label>
                                <input id="BOT_CARD_ROUTE_MODE" name="BOT_CARD_ROUTE_MODE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_ROUTE_MODE', 'smart')) ?>">
                            </div>
                            <div>
                                <label for="BOT_CARD_TYPES_ENABLED">Enabled Card Types</label>
                                <input id="BOT_CARD_TYPES_ENABLED" name="BOT_CARD_TYPES_ENABLED" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_TYPES_ENABLED', 'kickoff_soon,live_now,results,tv_now')) ?>">
                            </div>
                            <div class="field-full">
                                <label for="BOT_CONTENT_PACKS_ENABLED">Enabled Content Packs</label>
                                <input id="BOT_CONTENT_PACKS_ENABLED" name="BOT_CONTENT_PACKS_ENABLED" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CONTENT_PACKS_ENABLED', 'live_now,kickoff_soon,results,tv_now')) ?>">
                            </div>
                            <div>
                                <label for="BOT_CARD_BURST_COOLDOWN_MINUTES">Cooldown Minutes</label>
                                <input id="BOT_CARD_BURST_COOLDOWN_MINUTES" name="BOT_CARD_BURST_COOLDOWN_MINUTES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_COOLDOWN_MINUTES', '60')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_CARD_BURST_MIN_FIXTURES">Min Fixtures</label>
                                <input id="BOT_CARD_BURST_MIN_FIXTURES" name="BOT_CARD_BURST_MIN_FIXTURES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_MIN_FIXTURES', '3')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_CARD_BURST_MIN_LIVE">Min Live</label>
                                <input id="BOT_CARD_BURST_MIN_LIVE" name="BOT_CARD_BURST_MIN_LIVE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_MIN_LIVE', '2')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_CARD_BURST_MIN_RESULTS">Min Results</label>
                                <input id="BOT_CARD_BURST_MIN_RESULTS" name="BOT_CARD_BURST_MIN_RESULTS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_MIN_RESULTS', '3')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_CARD_MAX_ITEMS_PER_TYPE">Public Cards Per Type</label>
                                <input id="BOT_CARD_MAX_ITEMS_PER_TYPE" name="BOT_CARD_MAX_ITEMS_PER_TYPE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_MAX_ITEMS_PER_TYPE', '4')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_CARD_MAX_PAGES_PER_RUN">Max Pages Per Run</label>
                                <input id="BOT_CARD_MAX_PAGES_PER_RUN" name="BOT_CARD_MAX_PAGES_PER_RUN" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_MAX_PAGES_PER_RUN', '12')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_CARD_MAX_SENDS_PER_RUN">Max Jobs Per Run</label>
                                <input id="BOT_CARD_MAX_SENDS_PER_RUN" name="BOT_CARD_MAX_SENDS_PER_RUN" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_MAX_SENDS_PER_RUN', '12')) ?>" inputmode="numeric">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><h3>Kick-off Reminder</h3></div>
                        <div class="field-grid three">
                            <div>
                                <label for="BOT_KICKOFF_REMINDER_MINUTES">Minutes Before Kick-off</label>
                                <input id="BOT_KICKOFF_REMINDER_MINUTES" name="BOT_KICKOFF_REMINDER_MINUTES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_KICKOFF_REMINDER_MINUTES', '10')) ?>" inputmode="numeric">
                            </div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_SEND_KICKOFF_REMINDER" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_KICKOFF_REMINDER', true) ? 'checked' : '' ?>><b>Kick-off reminders<span>Send a reminder alert before each match kicks off.</span></b></label>
                        </div>
                    </div>

                    <div class="form-section" id="tv">
                        <div class="section-title"><h3>TV Listings</h3><span class="muted"><?= count($tvChannels) ?> channels</span></div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_TV_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_ENABLED', true) ? 'checked' : '' ?>><b>Enable TV listings</b></label>
                            <label class="toggle"><input name="BOT_TV_DAILY_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_DAILY_ALERTS', true) ? 'checked' : '' ?>><b>Daily TV guide</b></label>
                            <label class="toggle"><input name="BOT_TV_SEND_IMAGE" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_SEND_IMAGE', true) ? 'checked' : '' ?>><b>TV guide image<span>Uses channel logos when available.</span></b></label>
                            <label class="toggle"><input name="BOT_TV_INCLUDE_IN_PREVIEWS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_INCLUDE_IN_PREVIEWS', true) ? 'checked' : '' ?>><b>TV info on previews</b></label>
                            <label class="toggle"><input name="BOT_TV_PREVIEW_REQUIRE_TV" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_PREVIEW_REQUIRE_TV', false) ? 'checked' : '' ?>><b>Only televised previews</b></label>
                            <label class="toggle"><input name="BOT_TV_FOOTBALL_ONLY" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_FOOTBALL_ONLY', false) ? 'checked' : '' ?>><b>Football-only TV guide</b></label>
                        </div>
                        <div class="field-grid three">
                            <?php if ($tvChannelRegistry !== []): ?>
                                <div class="field-full">
                                    <label>Selected TV Channels</label>
                                    <div class="league-grid">
                                        <?php foreach ($tvChannelRegistry as $channel): ?>
                                            <?php $slug = (string) $channel['channel_slug']; ?>
                                            <label class="league-option">
                                                <input name="BOT_TV_SELECTED_CHANNELS[]" type="checkbox" value="<?= htmlspecialchars($slug) ?>" <?= in_array($slug, $configuredTvSlugs, true) ? 'checked' : '' ?>>
                                                <b><?= htmlspecialchars((string) $channel['channel_name']) ?><span><?= htmlspecialchars($slug) ?></span></b>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="field-full">
                                <label for="BOT_TV_CHANNELS">Manual Extra Channel Slugs</label>
                                <textarea id="BOT_TV_CHANNELS" name="BOT_TV_CHANNELS" placeholder="sky_sports_main_event&#10;sky_sports_premier_league"><?= htmlspecialchars(implode("\n", $manualTvSlugs)) ?></textarea>
                            </div>
                            <div class="field-full">
                                <label for="BOT_TV_SPORTS">TV Sports Filter</label>
                                <textarea id="BOT_TV_SPORTS" name="BOT_TV_SPORTS" placeholder="Soccer&#10;Darts&#10;Rugby&#10;Snooker"><?= htmlspecialchars(admin_env_value($env, 'BOT_TV_SPORTS')) ?></textarea>
                            </div>
                            <div class="field-full">
                                <label for="BOT_TV_DISCOVERY_COUNTRIES">Discovery Countries</label>
                                <input id="BOT_TV_DISCOVERY_COUNTRIES" name="BOT_TV_DISCOVERY_COUNTRIES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_DISCOVERY_COUNTRIES', 'united_kingdom,ireland')) ?>" placeholder="united_kingdom,ireland">
                            </div>
                            <div>
                                <label for="BOT_TV_DAILY_ALERT_TIME">Guide Time</label>
                                <input id="BOT_TV_DAILY_ALERT_TIME" name="BOT_TV_DAILY_ALERT_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_DAILY_ALERT_TIME', '08:00')) ?>">
                            </div>
                            <div>
                                <label for="BOT_TV_DISCOVERY_DAYS_AHEAD">Discovery Days Ahead</label>
                                <input id="BOT_TV_DISCOVERY_DAYS_AHEAD" name="BOT_TV_DISCOVERY_DAYS_AHEAD" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_DISCOVERY_DAYS_AHEAD', '7')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_TV_LOOKAHEAD_HOURS">Lookahead Hours</label>
                                <input id="BOT_TV_LOOKAHEAD_HOURS" name="BOT_TV_LOOKAHEAD_HOURS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_LOOKAHEAD_HOURS', '24')) ?>" inputmode="numeric">
                            </div>
                            <div>
                                <label for="BOT_TV_MAX_EVENTS_PER_CHANNEL">Max Events Per Channel</label>
                                <input id="BOT_TV_MAX_EVENTS_PER_CHANNEL" name="BOT_TV_MAX_EVENTS_PER_CHANNEL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_MAX_EVENTS_PER_CHANNEL', '20')) ?>" inputmode="numeric">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><h3>API And Rendering</h3></div>
                        <div class="field-grid three">
                            <div><label for="BOT_API_MIN_INTERVAL_MS">API Min Interval Ms</label><input id="BOT_API_MIN_INTERVAL_MS" name="BOT_API_MIN_INTERVAL_MS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_API_MIN_INTERVAL_MS', '350')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_LIVESCORE_CACHE_TTL">Livescore TTL</label><input id="BOT_LIVESCORE_CACHE_TTL" name="BOT_LIVESCORE_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_LIVESCORE_CACHE_TTL', '75')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_TIMELINE_CACHE_TTL">Timeline TTL</label><input id="BOT_TIMELINE_CACHE_TTL" name="BOT_TIMELINE_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TIMELINE_CACHE_TTL', '45')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_LOOKUP_CACHE_TTL">Lookup TTL</label><input id="BOT_LOOKUP_CACHE_TTL" name="BOT_LOOKUP_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_LOOKUP_CACHE_TTL', '604800')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_TV_CACHE_TTL">TV TTL</label><input id="BOT_TV_CACHE_TTL" name="BOT_TV_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_CACHE_TTL', '900')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_MAX_LIVE_MATCHES_PER_RUN">Max Live Matches</label><input id="BOT_MAX_LIVE_MATCHES_PER_RUN" name="BOT_MAX_LIVE_MATCHES_PER_RUN" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_LIVE_MATCHES_PER_RUN', '25')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_MAX_LIVE_MATCHES_PER_SPORT">Max Live Per Sport</label><input id="BOT_MAX_LIVE_MATCHES_PER_SPORT" name="BOT_MAX_LIVE_MATCHES_PER_SPORT" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_LIVE_MATCHES_PER_SPORT', '8')) ?>" inputmode="numeric"></div>
                            <div>
                                <label for="BOT_RENDER_ENGINE">Render Engine</label>
                                <select id="BOT_RENDER_ENGINE" name="BOT_RENDER_ENGINE">
                                    <?php foreach (['auto', 'puppeteer', 'gd'] as $engine): ?>
                                        <option value="<?= htmlspecialchars($engine) ?>" <?= admin_env_value($env, 'BOT_RENDER_ENGINE', 'auto') === $engine ? 'selected' : '' ?>><?= htmlspecialchars($engine) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label for="BOT_IMAGE_QUALITY">PNG Quality</label><input id="BOT_IMAGE_QUALITY" name="BOT_IMAGE_QUALITY" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_IMAGE_QUALITY', '9')) ?>" inputmode="numeric"></div>
                            <div><label for="BOT_IMAGE_CLEANUP_SECONDS">Image Cleanup Seconds</label><input id="BOT_IMAGE_CLEANUP_SECONDS" name="BOT_IMAGE_CLEANUP_SECONDS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_IMAGE_CLEANUP_SECONDS', '86400')) ?>" inputmode="numeric"></div>
                            <div class="field-full"><label for="BOT_RENDER_CHROME_PATH">Chrome Path</label><input id="BOT_RENDER_CHROME_PATH" name="BOT_RENDER_CHROME_PATH" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_RENDER_CHROME_PATH')) ?>"></div>
                            <div class="field-full"><label for="BOT_RENDER_USER_DATA_DIR">Chrome User Data Dir</label><input id="BOT_RENDER_USER_DATA_DIR" name="BOT_RENDER_USER_DATA_DIR" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_RENDER_USER_DATA_DIR', $config['paths']['cache'] . '/chrome')) ?>"></div>
                            <div class="field-full"><label for="BOT_RENDER_EXTRA_ARGS">Extra Chrome Args</label><input id="BOT_RENDER_EXTRA_ARGS" name="BOT_RENDER_EXTRA_ARGS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_RENDER_EXTRA_ARGS')) ?>" placeholder="--disable-gpu,--single-process"></div>
                        </div>
                        <div class="toggle-grid">
                            <label class="toggle"><input name="BOT_IMAGE_PRESERVE_SAMPLE_IMAGES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_IMAGE_PRESERVE_SAMPLE_IMAGES', true) ? 'checked' : '' ?>><b>Preserve sample images</b></label>
                            <label class="toggle"><input name="BOT_HEALTH_ALERTS_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_HEALTH_ALERTS_ENABLED', true) ? 'checked' : '' ?>><b>Health summaries<span>Send daily operator health summaries to the error chat.</span></b></label>
                        </div>
                        <div class="field-grid">
                            <div>
                                <label for="BOT_FONT_REGULAR">Regular Font Path</label>
                                <input id="BOT_FONT_REGULAR" name="BOT_FONT_REGULAR" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_FONT_REGULAR', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')) ?>">
                            </div>
                            <div>
                                <label for="BOT_FONT_BOLD">Bold Font Path</label>
                                <input id="BOT_FONT_BOLD" name="BOT_FONT_BOLD" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_FONT_BOLD', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf')) ?>">
                            </div>
                            <div>
                                <label for="BOT_HEALTH_ALERT_TIME">Health Alert Time</label>
                                <input id="BOT_HEALTH_ALERT_TIME" name="BOT_HEALTH_ALERT_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_HEALTH_ALERT_TIME', '07:30')) ?>">
                            </div>
                            <div class="field-full" id="profile-editor">
                                <label for="BOT_SPORT_PROFILES_JSON">Sport Profile Overrides JSON</label>
                                <textarea id="BOT_SPORT_PROFILES_JSON" name="BOT_SPORT_PROFILES_JSON" placeholder='{"Basketball":{"start_label":"Tip-off","period_label":"Quarter"}}'><?= htmlspecialchars(admin_env_value($env, 'BOT_SPORT_PROFILES_JSON')) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><h3>Admin Access</h3></div>
                        <div class="field-grid">
                            <div><label for="new_password">New Password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password"></div>
                            <div><label for="confirm_password">Confirm Password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password"></div>
                        </div>
                    </div>

                    <div class="actions sticky-actions">
                        <button type="submit">Save Settings</button>
                    </div>
                </form>
            </div>

            <?php elseif ($activeView === 'system'): ?>
            <aside class="card page-wide" id="ops">
                <h2>Operations</h2>
                <div class="status-list">
                    <div>Telegram token: <b><?= htmlspecialchars(admin_mask($env['TELEGRAM_BOT_TOKEN'] ?? '')) ?></b></div>
                    <div>Primary chat: <b><?= htmlspecialchars(admin_mask($env['TELEGRAM_CHAT_ID'] ?? '')) ?></b></div>
                    <div>Default topic: <b><?= admin_env_value($env, 'TELEGRAM_MESSAGE_THREAD_ID') !== '' ? htmlspecialchars(admin_env_value($env, 'TELEGRAM_MESSAGE_THREAD_ID')) : 'None' ?></b></div>
                    <div>Error chat: <b><?= htmlspecialchars(admin_mask($env['TELEGRAM_ERROR_CHAT_ID'] ?? 'same as primary')) ?></b></div>
                    <div>Extra groups: <b><?= admin_env_value($env, 'TELEGRAM_EXTRA_CHAT_IDS') !== '' ? htmlspecialchars(admin_env_value($env, 'TELEGRAM_EXTRA_CHAT_IDS')) : 'None' ?></b></div>
                    <div>Sport routes: <b><?= admin_env_value($env, 'BOT_TELEGRAM_ROUTES_JSON') !== '' ? 'Configured' : 'Default only' ?></b></div>
                    <div>Enabled sports: <b><?= htmlspecialchars(implode(', ', array_slice($config['coverage']['enabled_sports'] ?? [], 0, 6))) ?><?= count($config['coverage']['enabled_sports'] ?? []) > 6 ? '...' : '' ?></b></div>
                    <div>TV listings: <b><?= !empty($config['tv']['enabled']) ? 'Enabled' : 'Disabled' ?></b></div>
                    <div>Customer guide: <b><?= !empty($config['customer']['guide_enabled']) ? htmlspecialchars(fb_customer_guide_time($config)) : 'Disabled' ?></b></div>
                    <div>Follow buttons: <b><?= !empty($config['customer']['follow_buttons_enabled']) ? 'Enabled' : 'Disabled' ?></b></div>
                    <div>Button polling: <b><?= !empty($config['telegram']['updates_enabled']) ? 'Enabled' : 'Disabled' ?></b></div>
                    <div>TV guide time: <b><?= htmlspecialchars(fb_tv_daily_alert_time($config)) ?></b></div>
                    <div>TV output: <b><?= !empty($config['tv']['send_image']) ? 'Image' : 'Text' ?></b></div>
                    <div>Daily card: <b><?= !empty($config['alerts']['send_daily_card']) ? htmlspecialchars($config['alerts']['daily_card_time']) : 'Disabled' ?></b></div>
                    <div>Kick-off reminder: <b><?= !empty($config['alerts']['send_kickoff_reminder']) ? htmlspecialchars($config['alerts']['kickoff_reminder_minutes'] . ' min before') : 'Disabled' ?></b></div>
                    <div>TV sports: <b><?= ($config['tv']['sports'] ?? []) !== [] ? htmlspecialchars(implode(', ', $config['tv']['sports'])) : 'All sports' ?></b></div>
                    <div>Last API call: <b><?= isset($rateLimitInfo['last_request_ago_ms']) ? (int) $rateLimitInfo['last_request_ago_ms'] . 'ms ago' : 'No recorded request' ?></b></div>
                </div>
                <form class="actions" method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button name="action" value="test_telegram" type="submit">Send Test</button>
                    <button class="secondary" name="action" value="generate_samples" type="submit">Generate Samples</button>
                    <button class="secondary" name="action" value="generate_last_match" type="submit">Last Match Graphic</button>
                    <button class="secondary" name="action" value="dry_run" type="submit">Dry Check</button>
                    <button class="secondary" name="action" value="discover_coverage" type="submit">Discover Coverage</button>
                    <button class="secondary" name="action" value="discover_tv_channels" type="submit">Discover TV Channels</button>
                    <button class="secondary" name="action" value="test_telegram_routes" type="submit">Route Test</button>
                    <button class="secondary" name="action" value="send_tv_schedule_test" type="submit">TV Guide Test</button>
                    <button class="secondary" name="action" value="send_customer_guide_test" type="submit">Customer Guide Test</button>
                    <button class="secondary" name="action" value="send_daily_card_test" type="submit">Daily Card Test</button>
                    <button class="secondary" name="action" value="send_kickoff_reminder_test" type="submit">Kickoff Reminder Test</button>
                    <button class="warning" name="action" value="clear_api_cache" type="submit">Clear Cache</button>
                    <button class="danger" name="action" value="reset_state" type="submit" onclick="return confirm('Reset match state and sent alert history?')">Reset State</button>
                </form>

                <div class="form-section">
                    <h3>Write Via Bot</h3>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="send_manual_message">
                        <label for="manual_message">Message</label>
                        <textarea id="manual_message" name="manual_message" maxlength="4096" required></textarea>
                        <div class="actions">
                            <button type="submit">Send Message</button>
                        </div>
                    </form>
                </div>
            </aside>

            <?php elseif ($activeView === 'health'): ?>
            <div class="card page-wide" id="health">
                <h2>Health</h2>
                <div class="status-list">
                    <?php foreach ($healthChecks as $check): ?>
                        <div>
                            <b><?= htmlspecialchars((string) $check['label']) ?></b>
                            <span class="badge <?= !empty($check['ok']) ? '' : 'danger' ?>"><?= htmlspecialchars((string) $check['status']) ?></span>
                            <span class="muted"><?= htmlspecialchars((string) $check['detail']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form class="actions" method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <button name="action" value="run_render_health" type="submit">Run Render Check</button>
                    <button class="secondary" name="action" value="send_health_summary" type="submit">Send Health Summary</button>
                </form>
                <?php if ($renderHealthChecks !== []): ?>
                    <table class="table" style="margin-top:14px">
                        <thead><tr><th>Engine</th><th>Status</th><th>Duration</th><th>Message</th></tr></thead>
                        <tbody>
                        <?php foreach ($renderHealthChecks as $check): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $check['engine']) ?></td>
                                <td><?= htmlspecialchars((string) $check['status']) ?></td>
                                <td><?= (int) $check['duration_ms'] ?>ms</td>
                                <td><?= htmlspecialchars((string) $check['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php elseif ($activeView === 'routing'): ?>
            <div class="card page-wide" id="profiles">
                <h2>Sport Profiles</h2>
                <table class="table">
                    <thead><tr><th>Sport</th><th>Score</th><th>Start</th><th>Period</th><th>Final</th></tr></thead>
                    <tbody>
                    <?php foreach ($config['coverage']['enabled_sports'] ?? [] as $sport): ?>
                        <?php $profile = fb_sport_profile($config, (string) $sport); ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($profile['label'] ?? $sport)) ?></td>
                            <td><?= htmlspecialchars((string) ($profile['score_label'] ?? 'Score')) ?></td>
                            <td><?= htmlspecialchars((string) ($profile['start_label'] ?? 'Started')) ?></td>
                            <td><?= htmlspecialchars((string) ($profile['period_label'] ?? 'Status')) ?></td>
                            <td><?= htmlspecialchars((string) ($profile['final_label'] ?? 'Final')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php elseif ($activeView === 'activity'): ?>
            <div class="card span-6" id="outbox">
                <h2>Telegram Outbox</h2>
                <?php if ($outboxItems === []): ?>
                    <p class="muted">No outbox deliveries recorded yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Method</th><th>Target</th><th>Status</th><th>Attempts</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($outboxItems as $item): ?>
                            <?php
                                $target = (string) ($item['chat_id'] ?? '');
                                if (!empty($item['message_thread_id'])) {
                                    $target .= ':' . (int) $item['message_thread_id'];
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $item['method']) ?></td>
                                <td><?= htmlspecialchars(admin_mask($target)) ?></td>
                                <td><?= htmlspecialchars((string) $item['status']) ?></td>
                                <td><?= (int) $item['attempts'] ?></td>
                                <td><?= htmlspecialchars((string) $item['updated_at']) ?></td>
                            </tr>
                            <?php if (!empty($item['last_error'])): ?>
                                <tr><td colspan="5" class="muted"><?= htmlspecialchars((string) $item['last_error']) ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6">
                <h2>Alert Decisions</h2>
                <?php if ($alertDecisions === []): ?>
                    <p class="muted">No alert decisions recorded yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Decision</th><th>Type</th><th>Sport</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php foreach ($alertDecisions as $decision): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $decision['decision']) ?></td>
                                <td><?= htmlspecialchars((string) $decision['alert_type']) ?></td>
                                <td><?= htmlspecialchars((string) $decision['sport']) ?></td>
                                <td><?= htmlspecialchars((string) $decision['reason']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6" id="activity">
                <h2>Card Activity</h2>
                <?php if ($cardJobs === []): ?>
                    <p class="muted">No card jobs yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Type</th><th>Route</th><th>Status</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($cardJobs, 0, 8) as $job): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $job['card_type']) ?></td>
                                <td><?= htmlspecialchars((string) ($job['sport'] ?: $job['route_key'])) ?></td>
                                <td><?= htmlspecialchars((string) $job['status']) ?> <?= !empty($job['failed_dispatches']) ? '(' . (int) $job['failed_dispatches'] . ' failed)' : '' ?></td>
                                <td><?= htmlspecialchars((string) $job['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6">
                <h2>Recent Alerts</h2>
                <?php if ($recentAlerts === []): ?>
                    <p class="muted">No alerts sent yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Type</th><th>Sport</th><th>Event</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentAlerts as $alert): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $alert['alert_type']) ?></td>
                                <td><?= htmlspecialchars((string) ($alert['sport'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) $alert['event_id']) ?></td>
                                <td><?= htmlspecialchars((string) $alert['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6">
                <h2>Recent Match State</h2>
                <?php if ($recentMatches === []): ?>
                    <p class="muted">No stored match state yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Event</th><th>Sport</th><th>Status</th><th>Score</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentMatches as $match): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $match['event_id']) ?></td>
                                <td><?= htmlspecialchars((string) ($match['sport'] ?? '')) ?></td>
                                <td><?= htmlspecialchars(trim((string) $match['status'] . ' ' . (string) $match['progress'])) ?></td>
                                <td><?= (int) $match['home_score'] ?>-<?= (int) $match['away_score'] ?></td>
                                <td><?= htmlspecialchars((string) $match['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6">
                <h2>Alert Breakdown</h2>
                <?php if ($stateCounts['alert_types'] === []): ?>
                    <p class="muted">No alerts sent yet.</p>
                <?php else: ?>
                    <div class="status-list">
                        <?php foreach ($stateCounts['alert_types'] as $type => $count): ?>
                            <div><b><?= htmlspecialchars((string) $type) ?></b> <span class="muted">x<?= (int) $count ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php elseif ($activeView === 'data'): ?>
            <div class="card span-6">
                <h2>Cache And Rate Limit</h2>
                <div class="status-list">
                    <div>Min interval: <b><?= (int) ($rateLimitInfo['min_interval_ms'] ?? $config['thesportsdb']['min_request_interval_ms']) ?>ms</b></div>
                    <div>Livescore TTL: <b><?= (int) ($rateLimitInfo['livescore_cache_ttl'] ?? 0) ?>s</b></div>
                    <div>Timeline TTL: <b><?= (int) ($rateLimitInfo['timeline_cache_ttl'] ?? 0) ?>s</b></div>
                    <div>Lookup TTL: <b><?= (int) ($rateLimitInfo['lookup_cache_ttl'] ?? 0) ?>s</b></div>
                    <div>TV TTL: <b><?= (int) ($rateLimitInfo['tv_cache_ttl'] ?? 0) ?>s</b></div>
                </div>
                <?php if ($cacheEntries !== []): ?>
                    <table class="table" style="margin-top:14px">
                        <thead><tr><th>Cache Key</th><th>Status</th><th>Expires</th></tr></thead>
                        <tbody>
                        <?php foreach ($cacheEntries as $entry): ?>
                            <tr>
                                <td><?= htmlspecialchars(substr((string) $entry['cache_key'], 0, 22)) ?></td>
                                <td><?= (int) $entry['status_code'] ?></td>
                                <td><?= date('H:i:s', (int) $entry['expires_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card span-6">
                <h2>Football Leagues</h2>
                <div class="status-list">
                    <?php foreach ($config['leagues']['allowed'] as $id => $league): ?>
                        <div><b><?= htmlspecialchars((string) $league['name']) ?></b> <span class="muted">ID <?= htmlspecialchars((string) $id) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card span-6">
                <h2>Enabled TV Channels</h2>
                <?php if ($tvChannels === []): ?>
                    <p class="muted">No TV channels configured.</p>
                <?php else: ?>
                    <div class="status-list">
                        <?php foreach ($tvChannels as $channel): ?>
                            <div><b><?= htmlspecialchars((string) $channel['label']) ?></b> <span class="muted"><?= htmlspecialchars((string) $channel['slug']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Coverage Registry</h2>
                <?php if ($coverageSports === [] && $coverageLeagues === []): ?>
                    <p class="muted">No coverage registry yet. Run Discover Coverage from Operations.</p>
                <?php else: ?>
                    <div class="status-list">
                        <?php foreach (array_slice($coverageSports, 0, 18) as $sport): ?>
                            <div><b><?= htmlspecialchars((string) $sport['sport_name']) ?></b> <span class="muted"><?= !empty($sport['enabled']) ? 'enabled' : 'off' ?><?= !empty($sport['live_available']) ? ' - live seen' : '' ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($coverageLeagues !== []): ?>
                        <table class="table" style="margin-top:14px">
                            <thead><tr><th>League</th><th>Sport</th><th>Country</th><th>State</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($coverageLeagues, 0, 60) as $league): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $league['league_name']) ?></td>
                                    <td><?= htmlspecialchars((string) $league['sport']) ?></td>
                                    <td><?= htmlspecialchars((string) $league['country']) ?></td>
                                    <td><?= !empty($league['enabled']) ? 'Enabled' : 'Off' ?><?= !empty($league['live_available']) ? ' / Live' : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Discovered TV Channel Registry</h2>
                <?php if ($tvChannelRegistry === []): ?>
                    <p class="muted">No discovered channels yet. Run Discover TV Channels from Operations.</p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Channel</th><th>Slug</th><th>Sports</th><th>Updated</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($tvChannelRegistry, 0, 40) as $channel): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $channel['channel_name']) ?></td>
                                <td><?= htmlspecialchars((string) $channel['channel_slug']) ?></td>
                                <td><?= htmlspecialchars(implode(', ', array_slice($channel['sports'], 0, 4))) ?></td>
                                <td><?= htmlspecialchars((string) $channel['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php elseif ($activeView === 'media'): ?>
            <div class="card">
                <h2>Latest Images</h2>
                <?php if ($latestImages === []): ?>
                    <p class="muted">No generated images yet.</p>
                <?php else: ?>
                    <div class="images">
                        <?php foreach ($latestImages as $image): ?>
                            <?php $url = admin_public_image_url($image); ?>
                            <?php if ($url): ?>
                                <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noreferrer">
                                    <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars(basename($image)) ?>">
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php elseif ($activeView === 'logs'): ?>
            <div class="card span-6" id="logs">
                <h2>Bot Log</h2>
                <pre><?= htmlspecialchars($botLog !== '' ? $botLog : 'No bot log yet.') ?></pre>
            </div>

            <div class="card span-6">
                <h2>Cron Log</h2>
                <pre><?= htmlspecialchars($cronLog !== '' ? $cronLog : 'No cron log yet.') ?></pre>
            </div>
            <?php endif; ?>
            </section>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
