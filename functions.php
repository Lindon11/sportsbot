<?php

declare(strict_types=1);

function fb_config(bool $reload = false): array
{
    static $config = null;

    if ($config === null || $reload) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function fb_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function fb_log(string $level, string $message, array $context = []): void
{
    $config = fb_config();
    $line = sprintf(
        "[%s] %s %s%s\n",
        fb_now(),
        strtoupper($level),
        $message,
        $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    $logFile = $config['app']['log_file'];
    $dir = dirname($logFile);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function fb_ensure_directories(array $config): void
{
    foreach ($config['paths'] as $key => $path) {
        if (str_ends_with($key, '_db') || str_ends_with($key, '_lock')) {
            $path = dirname($path);
        }

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

function fb_require_extensions(array $extensions): void
{
    $missing = [];

    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing required PHP extensions: ' . implode(', ', $missing));
    }
}

function fb_require_env(array $config, bool $needTelegram = true): void
{
    $missing = [];

    if (!$config['thesportsdb']['api_key']) {
        $missing[] = 'THESPORTSDB_API_KEY';
    }

    if ($needTelegram && !$config['telegram']['bot_token']) {
        $missing[] = 'TELEGRAM_BOT_TOKEN';
    }

    if ($needTelegram && !$config['telegram']['chat_id']) {
        $missing[] = 'TELEGRAM_CHAT_ID';
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing environment variables: ' . implode(', ', $missing));
    }
}

function fb_db_columns(SQLite3 $db, string $table): array
{
    $columns = [];
    $result = $db->query('PRAGMA table_info(' . $table . ')');

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[(string) $row['name']] = true;
        }
    }

    return $columns;
}

function fb_db_ensure_column(SQLite3 $db, string $table, string $column, string $definition): void
{
    $columns = fb_db_columns($db, $table);

    if (isset($columns[$column])) {
        return;
    }

    $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function fb_open_db(array $config): SQLite3
{
    fb_require_extensions(['sqlite3']);

    $db = new SQLite3($config['paths']['state_db']);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS event_state (
            event_id TEXT PRIMARY KEY,
            sport TEXT NOT NULL DEFAULT "Soccer",
            league_id TEXT NOT NULL,
            status TEXT,
            progress INTEGER,
            home_score INTEGER,
            away_score INTEGER,
            raw_hash TEXT,
            first_seen_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS sent_alerts (
            alert_key TEXT PRIMARY KEY,
            event_id TEXT NOT NULL,
            sport TEXT,
            alert_type TEXT NOT NULL,
            meta_json TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS api_cache (
            cache_key TEXT PRIMARY KEY,
            body TEXT NOT NULL,
            status_code INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS tv_channels (
            channel_slug TEXT PRIMARY KEY,
            channel_id TEXT,
            channel_name TEXT NOT NULL,
            country TEXT,
            logo TEXT,
            sports_json TEXT,
            first_seen_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS coverage_sports (
            sport_key TEXT PRIMARY KEY,
            sport_name TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 0,
            live_available INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS coverage_leagues (
            league_id TEXT PRIMARY KEY,
            league_name TEXT NOT NULL,
            sport TEXT NOT NULL,
            country TEXT,
            badge TEXT,
            logo TEXT,
            enabled INTEGER NOT NULL DEFAULT 0,
            live_available INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS card_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_key TEXT UNIQUE NOT NULL,
            card_type TEXT NOT NULL,
            sport TEXT,
            route_key TEXT NOT NULL,
            window_start TEXT,
            window_end TEXT,
            status TEXT NOT NULL DEFAULT "pending",
            payload_json TEXT NOT NULL,
            page_count INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_error TEXT
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS card_dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_key TEXT NOT NULL,
            chat_id TEXT NOT NULL,
            page_no INTEGER NOT NULL DEFAULT 1,
            telegram_message_id TEXT,
            image_path TEXT,
            status TEXT NOT NULL,
            sent_at TEXT,
            last_error TEXT,
            UNIQUE(job_key, chat_id, page_no)
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_key TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS alert_decisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alert_key TEXT,
            event_id TEXT,
            sport TEXT,
            alert_type TEXT,
            decision TEXT NOT NULL,
            reason TEXT,
            meta_json TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS telegram_outbox (
            outbox_key TEXT PRIMARY KEY,
            alert_key TEXT,
            method TEXT NOT NULL,
            chat_id TEXT NOT NULL,
            message_thread_id INTEGER,
            text TEXT,
            image_path TEXT,
            caption TEXT,
            payload_json TEXT,
            status TEXT NOT NULL DEFAULT "pending",
            attempts INTEGER NOT NULL DEFAULT 0,
            telegram_message_id TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            sent_at TEXT,
            last_error TEXT
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS telegram_update_state (
            state_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS telegram_follow_buttons (
            token TEXT PRIMARY KEY,
            kind TEXT NOT NULL,
            sport TEXT,
            subject TEXT NOT NULL,
            payload_json TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS telegram_topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            message_thread_id INTEGER NOT NULL,
            name TEXT,
            icon_color INTEGER,
            icon_custom_emoji_id TEXT,
            source TEXT NOT NULL DEFAULT "update",
            first_seen_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(chat_id, message_thread_id)
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS customer_follows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_user_id TEXT NOT NULL,
            username TEXT,
            chat_id TEXT NOT NULL,
            message_thread_id INTEGER,
            kind TEXT NOT NULL,
            sport TEXT,
            subject TEXT NOT NULL,
            subject_key TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(telegram_user_id, chat_id, kind, subject_key)
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS render_health_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            engine TEXT NOT NULL,
            status TEXT NOT NULL,
            message TEXT,
            image_path TEXT,
            duration_ms INTEGER,
            created_at TEXT NOT NULL
        )'
    );
    fb_db_ensure_column($db, 'event_state', 'sport', 'TEXT NOT NULL DEFAULT "Soccer"');
    fb_db_ensure_column($db, 'sent_alerts', 'sport', 'TEXT');
    fb_db_ensure_column($db, 'card_dispatches', 'page_no', 'INTEGER NOT NULL DEFAULT 1');
    fb_db_ensure_column($db, 'telegram_outbox', 'message_thread_id', 'INTEGER');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_event_state_sport ON event_state (sport)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sent_alerts_sport ON sent_alerts (sport)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_coverage_leagues_sport ON coverage_leagues (sport, enabled)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_card_jobs_status ON card_jobs (status, updated_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_card_jobs_route ON card_jobs (route_key, sport, card_type)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_card_dispatches_job ON card_dispatches (job_key, status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_alert_decisions_created ON alert_decisions (created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_alert_decisions_key ON alert_decisions (alert_key, decision)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_telegram_outbox_status ON telegram_outbox (status, updated_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_telegram_outbox_alert ON telegram_outbox (alert_key, chat_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_telegram_topics_chat ON telegram_topics (chat_id, updated_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_follows_subject ON customer_follows (kind, subject_key, chat_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_follows_user ON customer_follows (telegram_user_id, chat_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_render_health_checks_created ON render_health_checks (created_at)');
    fb_execute(
        $db,
        'INSERT OR IGNORE INTO schema_migrations (migration_key, applied_at) VALUES (:migration_key, :applied_at)',
        [
            ':migration_key' => 'v2_foundation',
            ':applied_at' => fb_now(),
        ]
    );
    fb_execute(
        $db,
        'INSERT OR IGNORE INTO schema_migrations (migration_key, applied_at) VALUES (:migration_key, :applied_at)',
        [
            ':migration_key' => 'v2_customer_guide_topics',
            ':applied_at' => fb_now(),
        ]
    );

    return $db;
}

function fb_default_sport_profiles(): array
{
    $base = [
        'label' => 'Sport',
        'score_label' => 'Score',
        'start_label' => 'Started',
        'update_label' => 'Score update',
        'period_label' => 'Status',
        'final_label' => 'Full-time',
        'fixture_label' => 'Match',
        'score_separator' => '-',
        'fallback_detail' => 'Live update from TheSportsDB',
        'status_labels' => [
            'NS' => 'Not started',
            'LIVE' => 'Live',
            'HT' => 'Half-time',
            'FT' => 'Final',
            'AOT' => 'After overtime',
            'AP' => 'After penalties',
        ],
    ];

    $profiles = [
        'soccer' => [
            'label' => 'Soccer',
            'score_label' => 'Goals',
            'start_label' => 'Kick-off',
            'update_label' => 'Goal update',
            'period_label' => 'Phase',
            'final_label' => 'Full-time',
            'fixture_label' => 'Match',
            'fallback_detail' => 'Scorer unavailable',
        ],
        'rugby' => [
            'label' => 'Rugby',
            'score_label' => 'Points',
            'start_label' => 'Kick-off',
            'update_label' => 'Score update',
            'period_label' => 'Phase',
            'final_label' => 'Full-time',
            'fixture_label' => 'Match',
            'fallback_detail' => 'Scoring detail unavailable',
        ],
        'cricket' => [
            'label' => 'Cricket',
            'score_label' => 'Runs',
            'start_label' => 'Play started',
            'update_label' => 'Score update',
            'period_label' => 'Innings',
            'final_label' => 'Result',
            'fixture_label' => 'Fixture',
            'fallback_detail' => 'Scorecard detail unavailable',
        ],
        'tennis' => [
            'label' => 'Tennis',
            'score_label' => 'Score',
            'start_label' => 'Match started',
            'update_label' => 'Score update',
            'period_label' => 'Set',
            'final_label' => 'Result',
            'fixture_label' => 'Match',
            'fallback_detail' => 'Point-by-point detail unavailable',
        ],
        'darts' => [
            'label' => 'Darts',
            'score_label' => 'Legs/Sets',
            'start_label' => 'Match started',
            'update_label' => 'Score update',
            'period_label' => 'Stage',
            'final_label' => 'Result',
            'fixture_label' => 'Match',
            'fallback_detail' => 'Leg detail unavailable',
        ],
        'snooker' => [
            'label' => 'Snooker',
            'score_label' => 'Frames',
            'start_label' => 'Match started',
            'update_label' => 'Frame update',
            'period_label' => 'Session',
            'final_label' => 'Result',
            'fixture_label' => 'Match',
            'fallback_detail' => 'Frame detail unavailable',
        ],
        'golf' => [
            'label' => 'Golf',
            'score_label' => 'Score',
            'start_label' => 'Round started',
            'update_label' => 'Leaderboard update',
            'period_label' => 'Round',
            'final_label' => 'Round complete',
            'fixture_label' => 'Event',
        ],
        'motorsport' => [
            'label' => 'Motorsport',
            'score_label' => 'Position',
            'start_label' => 'Session started',
            'update_label' => 'Timing update',
            'period_label' => 'Session',
            'final_label' => 'Classified',
            'fixture_label' => 'Race',
        ],
        'formula_1' => [
            'label' => 'Formula 1',
            'score_label' => 'Position',
            'start_label' => 'Session started',
            'update_label' => 'Timing update',
            'period_label' => 'Session',
            'final_label' => 'Classified',
            'fixture_label' => 'Grand Prix',
        ],
        'boxing' => [
            'label' => 'Boxing',
            'score_label' => 'Score',
            'start_label' => 'Fight started',
            'update_label' => 'Fight update',
            'period_label' => 'Round',
            'final_label' => 'Result',
            'fixture_label' => 'Bout',
        ],
        'mma' => [
            'label' => 'MMA',
            'score_label' => 'Score',
            'start_label' => 'Fight started',
            'update_label' => 'Fight update',
            'period_label' => 'Round',
            'final_label' => 'Result',
            'fixture_label' => 'Bout',
        ],
        'american_football' => [
            'label' => 'American Football',
            'score_label' => 'Points',
            'start_label' => 'Kick-off',
            'update_label' => 'Score update',
            'period_label' => 'Quarter',
            'final_label' => 'Final',
            'fixture_label' => 'Game',
        ],
        'basketball' => [
            'label' => 'Basketball',
            'score_label' => 'Points',
            'start_label' => 'Tip-off',
            'update_label' => 'Score update',
            'period_label' => 'Quarter',
            'final_label' => 'Final',
            'fixture_label' => 'Game',
        ],
        'baseball' => [
            'label' => 'Baseball',
            'score_label' => 'Runs',
            'start_label' => 'First pitch',
            'update_label' => 'Score update',
            'period_label' => 'Inning',
            'final_label' => 'Final',
            'fixture_label' => 'Game',
        ],
        'ice_hockey' => [
            'label' => 'Ice Hockey',
            'score_label' => 'Goals',
            'start_label' => 'Puck drop',
            'update_label' => 'Goal update',
            'period_label' => 'Period',
            'final_label' => 'Final',
            'fixture_label' => 'Game',
        ],
    ];

    foreach ($profiles as $key => $profile) {
        $profiles[$key] = array_replace_recursive($base, $profile);
    }

    return $profiles;
}

function fb_sport_profiles(array $config): array
{
    $profiles = fb_default_sport_profiles();
    $json = trim((string) ($config['sports']['profiles_json'] ?? ''));

    if ($json !== '') {
        $decoded = json_decode($json, true);

        if (is_array($decoded)) {
            foreach ($decoded as $sport => $profile) {
                if (!is_array($profile)) {
                    continue;
                }

                $key = fb_sport_key((string) $sport);
                $profiles[$key] = array_replace_recursive($profiles[$key] ?? $profiles['soccer'], $profile);
            }
        }
    }

    return $profiles;
}

function fb_sport_profile(array $config, ?string $sport): array
{
    $profiles = fb_sport_profiles($config);
    $canonical = fb_canonical_sport((string) ($sport ?? ''), (string) ($sport ?? 'Sport'));
    $key = fb_sport_key($canonical);

    if (isset($profiles[$key])) {
        return $profiles[$key];
    }

    $fallback = $profiles['soccer'] ?? [];
    $fallback['label'] = $canonical !== '' ? $canonical : ($fallback['label'] ?? 'Sport');

    return $fallback;
}

function fb_sport_status_label(array $config, ?string $sport, ?string $status): string
{
    $status = trim((string) $status);

    if ($status === '') {
        return '';
    }

    $profile = fb_sport_profile($config, $sport);
    $labels = $profile['status_labels'] ?? [];
    $upper = strtoupper($status);

    return (string) ($labels[$status] ?? $labels[$upper] ?? $status);
}

function fb_content_pack_enabled(array $config, string $pack): bool
{
    $enabled = array_map('fb_card_type_slug', $config['content']['packs_enabled'] ?? []);

    if ($enabled === []) {
        return false;
    }

    return in_array(fb_card_type_slug($pack), $enabled, true);
}

function fb_record_alert_decision(SQLite3 $db, string $decision, string $reason, array $alert = [], array $meta = []): void
{
    $match = is_array($alert['match'] ?? null) ? $alert['match'] : [];
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    fb_execute(
        $db,
        'INSERT INTO alert_decisions (
            alert_key, event_id, sport, alert_type, decision, reason, meta_json, created_at
        ) VALUES (
            :alert_key, :event_id, :sport, :alert_type, :decision, :reason, :meta_json, :created_at
        )',
        [
            ':alert_key' => (string) ($alert['key'] ?? ''),
            ':event_id' => (string) ($match['event_id'] ?? ($alert['event_id'] ?? '')),
            ':sport' => (string) ($match['sport'] ?? ($alert['sport'] ?? '')),
            ':alert_type' => (string) ($alert['type'] ?? ($alert['alert_type'] ?? '')),
            ':decision' => $decision,
            ':reason' => $reason,
            ':meta_json' => $metaJson ?: '{}',
            ':created_at' => fb_now(),
        ]
    );
}

function fb_outbox_key(string $scope, string $method, string $chatId, ?int $messageThreadId = null): string
{
    return hash('sha256', $scope . '|' . $method . '|' . $chatId . '|' . (string) ($messageThreadId ?? 0));
}

function fb_outbox_sent(SQLite3 $db, string $outboxKey): bool
{
    return fb_fetch_one($db, 'SELECT outbox_key FROM telegram_outbox WHERE outbox_key = :key AND status = "sent"', [
        ':key' => $outboxKey,
    ]) !== null;
}

function fb_outbox_start(SQLite3 $db, string $outboxKey, ?string $alertKey, string $method, string $chatId, ?int $messageThreadId, ?string $text, ?string $imagePath, ?string $caption, array $payload = []): void
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    fb_execute(
        $db,
        'INSERT INTO telegram_outbox (
            outbox_key, alert_key, method, chat_id, message_thread_id, text, image_path, caption, payload_json, status, attempts, created_at, updated_at, last_error
        ) VALUES (
            :outbox_key, :alert_key, :method, :chat_id, :message_thread_id, :text, :image_path, :caption, :payload_json, "sending", 1, :created_at, :updated_at, NULL
        ) ON CONFLICT(outbox_key) DO UPDATE SET
            status = CASE WHEN telegram_outbox.status = "sent" THEN telegram_outbox.status ELSE "sending" END,
            attempts = CASE WHEN telegram_outbox.status = "sent" THEN telegram_outbox.attempts ELSE telegram_outbox.attempts + 1 END,
            message_thread_id = excluded.message_thread_id,
            text = excluded.text,
            image_path = excluded.image_path,
            caption = excluded.caption,
            payload_json = excluded.payload_json,
            updated_at = excluded.updated_at,
            last_error = CASE WHEN telegram_outbox.status = "sent" THEN telegram_outbox.last_error ELSE NULL END',
        [
            ':outbox_key' => $outboxKey,
            ':alert_key' => $alertKey,
            ':method' => $method,
            ':chat_id' => $chatId,
            ':message_thread_id' => $messageThreadId,
            ':text' => $text,
            ':image_path' => $imagePath,
            ':caption' => $caption,
            ':payload_json' => $payloadJson ?: '{}',
            ':created_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_outbox_finish(SQLite3 $db, string $outboxKey, string $status, ?string $messageId = null, ?string $error = null): void
{
    fb_execute(
        $db,
        'UPDATE telegram_outbox
         SET status = :status,
             telegram_message_id = COALESCE(:message_id, telegram_message_id),
             sent_at = CASE WHEN :status = "sent" THEN :sent_at ELSE sent_at END,
             last_error = :last_error,
             updated_at = :updated_at
         WHERE outbox_key = :outbox_key',
        [
            ':status' => $status,
            ':message_id' => $messageId,
            ':sent_at' => fb_now(),
            ':last_error' => $error,
            ':updated_at' => fb_now(),
            ':outbox_key' => $outboxKey,
        ]
    );
}

function fb_telegram_send_photo_to_outbox(array $config, SQLite3 $db, string $scope, string $chatId, string $imagePath, string $caption = '', ?string $alertKey = null, ?int $messageThreadId = null, array $options = []): array
{
    $outboxKey = fb_outbox_key($scope, 'sendPhoto', $chatId, $messageThreadId);

    if (fb_outbox_sent($db, $outboxKey)) {
        return ['ok' => true, 'skipped' => true, 'outbox_key' => $outboxKey];
    }

    fb_outbox_start($db, $outboxKey, $alertKey, 'sendPhoto', $chatId, $messageThreadId, null, $imagePath, $caption, [
        'scope' => $scope,
        'file' => basename($imagePath),
        'options' => $options,
    ]);

    try {
        $result = fb_telegram_send_photo_to($config, $imagePath, $caption, $chatId, $messageThreadId, $options);
        $messageId = (string) ($result['result']['message_id'] ?? '');
        fb_outbox_finish($db, $outboxKey, 'sent', $messageId !== '' ? $messageId : null, null);

        return $result + ['outbox_key' => $outboxKey];
    } catch (Throwable $error) {
        fb_outbox_finish($db, $outboxKey, 'failed', null, $error->getMessage());

        return ['ok' => false, 'error' => $error->getMessage(), 'outbox_key' => $outboxKey];
    }
}

function fb_telegram_send_message_to_outbox(array $config, SQLite3 $db, string $scope, string $chatId, string $message, ?string $alertKey = null, ?int $messageThreadId = null, array $options = []): array
{
    $outboxKey = fb_outbox_key($scope, 'sendMessage', $chatId, $messageThreadId);

    if (fb_outbox_sent($db, $outboxKey)) {
        return ['ok' => true, 'skipped' => true, 'outbox_key' => $outboxKey];
    }

    fb_outbox_start($db, $outboxKey, $alertKey, 'sendMessage', $chatId, $messageThreadId, $message, null, null, [
        'scope' => $scope,
        'options' => $options,
    ]);

    try {
        $result = fb_telegram_send_message($config, $message, $chatId, $messageThreadId, $options);
        $messageId = (string) ($result['result']['message_id'] ?? '');
        fb_outbox_finish($db, $outboxKey, 'sent', $messageId !== '' ? $messageId : null, null);

        return $result + ['outbox_key' => $outboxKey];
    } catch (Throwable $error) {
        fb_outbox_finish($db, $outboxKey, 'failed', null, $error->getMessage());

        return ['ok' => false, 'error' => $error->getMessage(), 'outbox_key' => $outboxKey];
    }
}

function fb_telegram_outbox_all_ok(array $results): bool
{
    foreach ($results as $result) {
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

function fb_telegram_send_photo_route_outbox(array $config, SQLite3 $db, string $scope, string $imagePath, string $caption = '', ?string $sport = null, ?string $alertKey = null, array $options = []): array
{
    $results = [];

    foreach (fb_telegram_route_targets($config, $sport) as $target) {
        $key = fb_telegram_target_key($target);
        $results[$key] = fb_telegram_send_photo_to_outbox($config, $db, $scope, (string) $target['chat_id'], $imagePath, $caption, $alertKey, $target['message_thread_id'] ?? null, $options);
    }

    return $results;
}

function fb_telegram_send_photo_all_groups_outbox(array $config, SQLite3 $db, string $scope, string $imagePath, string $caption = '', ?string $alertKey = null, array $options = []): array
{
    $results = [];

    foreach (fb_telegram_default_targets($config) as $target) {
        $key = fb_telegram_target_key($target);
        $results[$key] = fb_telegram_send_photo_to_outbox($config, $db, $scope, (string) $target['chat_id'], $imagePath, $caption, $alertKey, $target['message_thread_id'] ?? null, $options);
    }

    return $results;
}

function fb_telegram_send_message_route_outbox(array $config, SQLite3 $db, string $scope, string $message, ?string $sport = null, ?string $alertKey = null, array $options = []): array
{
    $results = [];

    foreach (fb_telegram_route_targets($config, $sport) as $target) {
        $key = fb_telegram_target_key($target);
        $results[$key] = fb_telegram_send_message_to_outbox($config, $db, $scope, (string) $target['chat_id'], $message, $alertKey, $target['message_thread_id'] ?? null, $options);
    }

    return $results;
}

function fb_telegram_send_message_all_groups_outbox(array $config, SQLite3 $db, string $scope, string $message, ?string $alertKey = null, array $options = []): array
{
    $results = [];

    foreach (fb_telegram_default_targets($config) as $target) {
        $key = fb_telegram_target_key($target);
        $results[$key] = fb_telegram_send_message_to_outbox($config, $db, $scope, (string) $target['chat_id'], $message, $alertKey, $target['message_thread_id'] ?? null, $options);
    }

    return $results;
}

function fb_send_alert_photo_route_and_record(array $config, SQLite3 $db, array $alert, string $imagePath, string $caption): bool
{
    $alertKey = (string) ($alert['key'] ?? hash('sha256', $caption));
    $results = fb_telegram_send_photo_route_outbox(
        $config,
        $db,
        'alert:' . $alertKey,
        $imagePath,
        $caption,
        $alert['match']['sport'] ?? null,
        $alertKey
    );

    if (fb_telegram_outbox_all_ok($results)) {
        fb_mark_alert_sent($db, $alert);
        fb_record_alert_decision($db, 'sent', 'Delivered to all configured route chats.', $alert, ['chat_count' => count($results)]);
        return true;
    }

    fb_record_alert_decision($db, 'failed', 'One or more route chats failed; successful chats are stored in the outbox.', $alert, ['results' => $results]);
    return false;
}

function fb_send_simple_alert_photo_all_and_record(array $config, SQLite3 $db, array $alert, string $imagePath, string $caption): bool
{
    $alertKey = (string) ($alert['key'] ?? hash('sha256', $caption));
    $results = fb_telegram_send_photo_all_groups_outbox($config, $db, 'simple:' . $alertKey, $imagePath, $caption, $alertKey);

    if (fb_telegram_outbox_all_ok($results)) {
        fb_mark_simple_alert_sent($db, $alertKey, (string) ($alert['type'] ?? 'ALERT'), $alert['meta'] ?? []);
        fb_record_alert_decision($db, 'sent', 'Delivered to all default chats.', $alert, ['chat_count' => count($results)]);
        return true;
    }

    fb_record_alert_decision($db, 'failed', 'One or more default chats failed; successful chats are stored in the outbox.', $alert, ['results' => $results]);
    return false;
}

function fb_send_simple_alert_photo_route_and_record(array $config, SQLite3 $db, array $alert, string $imagePath, string $caption, ?string $sport = null): bool
{
    $alertKey = (string) ($alert['key'] ?? hash('sha256', $caption));
    $results = fb_telegram_send_photo_route_outbox($config, $db, 'simple:' . $alertKey, $imagePath, $caption, $sport, $alertKey);

    if (fb_telegram_outbox_all_ok($results)) {
        fb_mark_simple_alert_sent($db, $alertKey, (string) ($alert['type'] ?? 'ALERT'), $alert['meta'] ?? []);
        fb_record_alert_decision($db, 'sent', 'Delivered to all configured route chats.', $alert, ['chat_count' => count($results)]);
        return true;
    }

    fb_record_alert_decision($db, 'failed', 'One or more route chats failed; successful chats are stored in the outbox.', $alert, ['results' => $results]);
    return false;
}

function fb_send_simple_alert_message_all_and_record(array $config, SQLite3 $db, array $alert, string $message, array $options = []): bool
{
    $alertKey = (string) ($alert['key'] ?? hash('sha256', $message));
    $results = fb_telegram_send_message_all_groups_outbox($config, $db, 'simple:' . $alertKey, $message, $alertKey, $options);

    if (fb_telegram_outbox_all_ok($results)) {
        fb_mark_simple_alert_sent($db, $alertKey, (string) ($alert['type'] ?? 'ALERT'), $alert['meta'] ?? []);
        fb_record_alert_decision($db, 'sent', 'Delivered to all default chats.', $alert, ['chat_count' => count($results)]);
        return true;
    }

    fb_record_alert_decision($db, 'failed', 'One or more default chats failed; successful chats are stored in the outbox.', $alert, ['results' => $results]);
    return false;
}

function fb_send_simple_alert_message_route_and_record(array $config, SQLite3 $db, array $alert, string $message, ?string $sport = null, array $options = []): bool
{
    $alertKey = (string) ($alert['key'] ?? hash('sha256', $message));
    $results = fb_telegram_send_message_route_outbox($config, $db, 'simple:' . $alertKey, $message, $sport, $alertKey, $options);

    if (fb_telegram_outbox_all_ok($results)) {
        fb_mark_simple_alert_sent($db, $alertKey, (string) ($alert['type'] ?? 'ALERT'), $alert['meta'] ?? []);
        fb_record_alert_decision($db, 'sent', 'Delivered to all configured route chats.', $alert, ['chat_count' => count($results)]);
        return true;
    }

    fb_record_alert_decision($db, 'failed', 'One or more route chats failed; successful chats are stored in the outbox.', $alert, ['results' => $results]);
    return false;
}

function fb_follow_subject_key(string $kind, string $subject): string
{
    return fb_sport_key($kind) . ':' . fb_sport_key($subject);
}

function fb_follow_button_token(string $kind, string $sport, string $subject): string
{
    return substr(hash('sha256', strtolower(trim($kind . '|' . $sport . '|' . $subject))), 0, 18);
}

function fb_register_follow_button(SQLite3 $db, string $kind, string $sport, string $subject, array $payload = []): ?string
{
    $kind = strtolower(trim($kind));
    $sport = trim($sport);
    $subject = trim($subject);

    if (!in_array($kind, ['team', 'player', 'feed'], true) || $subject === '') {
        return null;
    }

    $token = fb_follow_button_token($kind, $sport, $subject);
    $kindCode = match ($kind) {
        'team' => 't',
        'player' => 'p',
        'feed' => 'f',
        default => '',
    };

    fb_execute(
        $db,
        'INSERT INTO telegram_follow_buttons (
            token, kind, sport, subject, payload_json, created_at
        ) VALUES (
            :token, :kind, :sport, :subject, :payload_json, :created_at
        ) ON CONFLICT(token) DO UPDATE SET
            kind = excluded.kind,
            sport = excluded.sport,
            subject = excluded.subject,
            payload_json = excluded.payload_json',
        [
            ':token' => $token,
            ':kind' => $kind,
            ':sport' => $sport,
            ':subject' => $subject,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ':created_at' => fb_now(),
        ]
    );

    return $kindCode !== '' ? 'fbf|' . $kindCode . '|' . $token : null;
}

function fb_follow_button_payload(SQLite3 $db, string $callbackData): ?array
{
    $parts = explode('|', trim($callbackData));

    if (count($parts) !== 3 || $parts[0] !== 'fbf') {
        return null;
    }

    $kind = match ($parts[1]) {
        't' => 'team',
        'p' => 'player',
        'f' => 'feed',
        default => '',
    };
    $token = $parts[2];

    if ($kind === '' || $token === '') {
        return null;
    }

    $row = fb_fetch_one($db, 'SELECT * FROM telegram_follow_buttons WHERE token = :token AND kind = :kind', [
        ':token' => $token,
        ':kind' => $kind,
    ]);

    if ($row === null) {
        return null;
    }

    $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);

    return [
        'kind' => (string) $row['kind'],
        'sport' => (string) ($row['sport'] ?? ''),
        'subject' => (string) $row['subject'],
        'payload' => is_array($payload) ? $payload : [],
    ];
}

function fb_save_customer_follow(SQLite3 $db, string $chatId, ?int $messageThreadId, array $user, array $follow): void
{
    $telegramUserId = trim((string) ($user['id'] ?? ''));
    $kind = strtolower(trim((string) ($follow['kind'] ?? '')));
    $subject = trim((string) ($follow['subject'] ?? ''));

    if ($telegramUserId === '' || !in_array($kind, ['team', 'player', 'feed'], true) || $chatId === '' || $subject === '') {
        return;
    }

    $username = trim((string) ($user['username'] ?? $user['first_name'] ?? ''));
    $sport = trim((string) ($follow['sport'] ?? ''));
    $subjectKey = fb_follow_subject_key($kind, $subject);

    fb_execute(
        $db,
        'INSERT INTO customer_follows (
            telegram_user_id, username, chat_id, message_thread_id, kind, sport, subject, subject_key, created_at, updated_at
        ) VALUES (
            :telegram_user_id, :username, :chat_id, :message_thread_id, :kind, :sport, :subject, :subject_key, :created_at, :updated_at
        ) ON CONFLICT(telegram_user_id, chat_id, kind, subject_key) DO UPDATE SET
            username = excluded.username,
            message_thread_id = excluded.message_thread_id,
            sport = excluded.sport,
            subject = excluded.subject,
            updated_at = excluded.updated_at',
        [
            ':telegram_user_id' => $telegramUserId,
            ':username' => $username,
            ':chat_id' => $chatId,
            ':message_thread_id' => $messageThreadId,
            ':kind' => $kind,
            ':sport' => $sport,
            ':subject' => $subject,
            ':subject_key' => $subjectKey,
            ':created_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_customer_follow_counts(SQLite3 $db): array
{
    $counts = [
        'total' => 0,
        'teams' => 0,
        'players' => 0,
        'users' => 0,
    ];

    $counts['total'] = (int) ($db->querySingle('SELECT COUNT(*) FROM customer_follows') ?: 0);
    $counts['teams'] = (int) ($db->querySingle('SELECT COUNT(*) FROM customer_follows WHERE kind = "team"') ?: 0);
    $counts['players'] = (int) ($db->querySingle('SELECT COUNT(*) FROM customer_follows WHERE kind = "player"') ?: 0);
    $counts['feeds'] = (int) ($db->querySingle('SELECT COUNT(*) FROM customer_follows WHERE kind = "feed"') ?: 0);
    $counts['users'] = (int) ($db->querySingle('SELECT COUNT(DISTINCT telegram_user_id) FROM customer_follows') ?: 0);

    return $counts;
}

function fb_recent_customer_follows(SQLite3 $db, int $limit = 12): array
{
    $stmt = $db->prepare(
        'SELECT telegram_user_id, username, chat_id, message_thread_id, kind, sport, subject, updated_at
         FROM customer_follows
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

function fb_customer_follow_terms(SQLite3 $db, string $kind): array
{
    $kind = strtolower(trim($kind));

    if (!in_array($kind, ['team', 'player', 'feed'], true)) {
        return [];
    }

    $stmt = $db->prepare(
        'SELECT subject
         FROM customer_follows
         WHERE kind = :kind
         GROUP BY subject_key
         ORDER BY MAX(updated_at) DESC
         LIMIT 40'
    );
    $stmt->bindValue(':kind', $kind, SQLITE3_TEXT);
    $result = $stmt->execute();
    $terms = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $subject = trim((string) ($row['subject'] ?? ''));
            if ($subject !== '') {
                $terms[] = $subject;
            }
        }
    }

    return array_values(array_unique($terms));
}

function fb_customer_guide_time(array $config): string
{
    $time = trim((string) ($config['customer']['guide_time'] ?? '09:00'));

    return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time) === 1 ? $time : '09:00';
}

function fb_watchlist_terms(array $config, string $key): array
{
    $values = $config['customer'][$key] ?? [];

    return array_values(array_filter(array_unique(array_map(
        static fn (mixed $value): string => trim((string) $value),
        is_array($values) ? $values : []
    )), static fn (string $value): bool => $value !== ''));
}

function fb_match_watchlist_tags(array $match, array $terms): array
{
    $tags = [];
    $haystack = strtolower(trim(($match['home_team'] ?? '') . ' ' . ($match['away_team'] ?? '') . ' ' . ($match['event_name'] ?? '')));

    foreach ($terms as $term) {
        $needle = strtolower(trim((string) $term));

        if ($needle !== '' && str_contains($haystack, $needle)) {
            $tags[] = (string) $term;
        }
    }

    return array_values(array_unique($tags));
}

function fb_customer_guide_match_line(array $match, array $tvChannels = []): string
{
    $sport = trim((string) ($match['sport'] ?? 'Sport'));
    $league = trim((string) ($match['league_name'] ?? ''));
    $home = trim((string) ($match['home_team'] ?? 'Home'));
    $away = trim((string) ($match['away_team'] ?? 'Away'));
    $time = trim((string) ($match['event_time'] ?? ''));
    $status = trim((string) ($match['status'] ?? ''));
    $homeScore = (int) ($match['home_score'] ?? 0);
    $awayScore = (int) ($match['away_score'] ?? 0);
    $hasScore = $status !== '' || $homeScore !== 0 || $awayScore !== 0;
    $fixture = $hasScore ? $home . ' ' . $homeScore . '-' . $awayScore . ' ' . $away : $home . ' vs ' . $away;
    $prefix = $time !== '' ? substr($time, 0, 5) . ' ' : '';
    $suffix = $status !== '' ? ' [' . $status . ']' : '';
    $tv = $tvChannels !== [] ? ' TV: ' . implode(', ', array_slice($tvChannels, 0, 3)) : '';

    return trim($prefix . $sport . ': ' . $fixture . ($league !== '' ? ' (' . $league . ')' : '') . $suffix . $tv);
}

function fb_customer_guide_tv_line(array $event): string
{
    $time = trim((string) ($event['time_label'] ?? ''));
    $sport = trim((string) ($event['sport'] ?? 'Sport'));
    $league = trim((string) ($event['league'] ?? ''));
    $channel = trim((string) ($event['channel'] ?? $event['configured_channel_label'] ?? 'TV'));
    $home = trim((string) ($event['home_team'] ?? ''));
    $away = trim((string) ($event['away_team'] ?? ''));
    $eventName = trim((string) ($event['event'] ?? ''));
    $name = $home !== '' && $away !== '' ? $home . ' vs ' . $away : $eventName;

    return trim(($time !== '' ? $time . ' ' : '') . $sport . ': ' . $name . ($league !== '' ? ' (' . $league . ')' : '') . ' - ' . $channel);
}

function fb_save_telegram_topic(SQLite3 $db, string $chatId, mixed $messageThreadId, ?string $name = null, array $meta = [], string $source = 'update'): bool
{
    $chatId = trim($chatId);
    $threadId = is_numeric((string) $messageThreadId) ? (int) $messageThreadId : 0;

    if ($chatId === '' || $threadId <= 0) {
        return false;
    }

    $name = trim((string) $name);
    $source = trim($source) !== '' ? trim($source) : 'update';
    fb_execute(
        $db,
        'INSERT INTO telegram_topics (
            chat_id, message_thread_id, name, icon_color, icon_custom_emoji_id, source, first_seen_at, updated_at
        ) VALUES (
            :chat_id, :message_thread_id, :name, :icon_color, :icon_custom_emoji_id, :source, :first_seen_at, :updated_at
        ) ON CONFLICT(chat_id, message_thread_id) DO UPDATE SET
            name = CASE WHEN excluded.name != "" THEN excluded.name ELSE telegram_topics.name END,
            icon_color = CASE WHEN excluded.icon_color IS NOT NULL THEN excluded.icon_color ELSE telegram_topics.icon_color END,
            icon_custom_emoji_id = CASE WHEN excluded.icon_custom_emoji_id != "" THEN excluded.icon_custom_emoji_id ELSE telegram_topics.icon_custom_emoji_id END,
            source = excluded.source,
            updated_at = excluded.updated_at',
        [
            ':chat_id' => $chatId,
            ':message_thread_id' => $threadId,
            ':name' => $name,
            ':icon_color' => isset($meta['icon_color']) && is_numeric((string) $meta['icon_color']) ? (int) $meta['icon_color'] : null,
            ':icon_custom_emoji_id' => trim((string) ($meta['icon_custom_emoji_id'] ?? '')),
            ':source' => $source,
            ':first_seen_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );

    return true;
}

function fb_list_telegram_topics(SQLite3 $db, ?string $chatId = null, int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    $params = [':limit' => $limit];
    $where = '';

    if ($chatId !== null && trim($chatId) !== '') {
        $where = 'WHERE chat_id = :chat_id';
        $params[':chat_id'] = trim($chatId);
    }

    $stmt = $db->prepare(
        'SELECT chat_id, message_thread_id, name, icon_color, icon_custom_emoji_id, source, first_seen_at, updated_at
         FROM telegram_topics
         ' . $where . '
         ORDER BY updated_at DESC, message_thread_id ASC
         LIMIT :limit'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, is_int($value) ? $value : (string) $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $rows = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function fb_telegram_topic_url(string $chatId, mixed $messageThreadId): string
{
    $threadId = is_numeric((string) $messageThreadId) ? (int) $messageThreadId : 0;

    if ($threadId <= 0) {
        return '';
    }

    if (preg_match('/^-100(\d+)$/', trim($chatId), $matches) !== 1) {
        return '';
    }

    return 'https://t.me/c/' . $matches[1] . '/' . $threadId;
}

function fb_customer_guide_topic_buttons(array $config): array
{
    $buttons = [];
    $routes = $config['telegram']['routes'] ?? [];

    if (!is_array($routes)) {
        return [];
    }

    foreach ($routes as $sport => $routeValue) {
        if (fb_sport_key((string) $sport) === 'default') {
            continue;
        }

        foreach (fb_telegram_targets_from_value($routeValue) as $target) {
            $url = fb_telegram_topic_url((string) ($target['chat_id'] ?? ''), $target['message_thread_id'] ?? null);

            if ($url === '') {
                continue;
            }

            $label = 'Open ' . fb_canonical_sport((string) $sport, (string) $sport);
            $buttons[] = [
                'text' => strlen($label) > 32 ? substr($label, 0, 29) . '...' : $label,
                'url' => $url,
            ];
            break;
        }

        if (count($buttons) >= 4) {
            break;
        }
    }

    return $buttons;
}

function fb_customer_guide_feed_buttons(SQLite3 $db): array
{
    $feeds = [
        'Live scores',
        'Fixtures',
        'TV guide',
        'Standings',
    ];
    $buttons = [];

    foreach ($feeds as $feed) {
        $callbackData = fb_register_follow_button($db, 'feed', '', $feed, ['source' => 'customer_guide']);

        if ($callbackData === null) {
            continue;
        }

        $buttons[] = [
            'text' => 'Subscribe ' . $feed,
            'callback_data' => $callbackData,
        ];
    }

    return $buttons;
}

function fb_bot_menu_text(): string
{
    return implode("\n", [
        '<b>Sports Hub</b>',
        'Choose what you want to view or subscribe to. Use the buttons below to explore live scores, fixtures, TV listings and your followed teams.',
    ]);
}

function fb_bot_menu_callback_button(string $label, string $action): array
{
    return [
        'text' => $label,
        'callback_data' => 'fbm|' . preg_replace('/[^a-z0-9_]/', '', strtolower($action)),
    ];
}

function fb_bot_menu_topic_buttons(array $config, SQLite3 $db, ?string $chatId = null, int $limit = 4): array
{
    $primaryChatId = trim((string) ($config['telegram']['chat_id'] ?? ''));
    $wantedChatId = trim((string) ($chatId ?? ''));
    $topics = [];

    if ($wantedChatId !== '') {
        $topics = fb_list_telegram_topics($db, $wantedChatId, $limit);
    }

    if ($topics === [] && $primaryChatId !== '' && $primaryChatId !== $wantedChatId) {
        $topics = fb_list_telegram_topics($db, $primaryChatId, $limit);
    }

    $buttons = [];

    foreach ($topics as $topic) {
        $url = fb_telegram_topic_url((string) ($topic['chat_id'] ?? ''), $topic['message_thread_id'] ?? null);

        if ($url === '') {
            continue;
        }

        $label = trim((string) ($topic['name'] ?? ''));
        if ($label === '') {
            $label = 'Topic ' . (int) ($topic['message_thread_id'] ?? 0);
        }

        $buttons[] = [
            'text' => strlen($label) > 30 ? substr($label, 0, 27) . '...' : $label,
            'url' => $url,
        ];

        if (count($buttons) >= $limit) {
            break;
        }
    }

    return $buttons;
}

function fb_bot_menu_reply_markup(array $config, SQLite3 $db, ?string $chatId = null): array
{
    $rows = [
        [
            fb_bot_menu_callback_button('Live — All', 'live_all'),
            fb_bot_menu_callback_button('Live — Football', 'live_football'),
        ],
        [
            fb_bot_menu_callback_button('Fixtures — All', 'fixtures_all'),
            fb_bot_menu_callback_button('Fixtures — Football', 'fixtures_football'),
        ],
        [
            fb_bot_menu_callback_button('Fixtures — Basketball', 'fixtures_basketball'),
            fb_bot_menu_callback_button('TV — Now', 'tv_now'),
        ],
        [
            fb_bot_menu_callback_button('TV — Today', 'tv_today'),
            fb_bot_menu_callback_button('Tables — Football', 'tables_football'),
        ],
        [
            fb_bot_menu_callback_button('My Teams', 'my_teams'),
            fb_bot_menu_callback_button('Premium', 'premium'),
        ],
    ];

    $topicRow = [];
    foreach (fb_bot_menu_topic_buttons($config, $db, $chatId) as $button) {
        $topicRow[] = $button;
        if (count($topicRow) === 2) {
            $rows[] = $topicRow;
            $topicRow = [];
        }
    }

    if ($topicRow !== []) {
        $rows[] = $topicRow;
    }

    return ['inline_keyboard' => $rows];
}

function fb_bot_submenu_reply_markup(array $config, SQLite3 $db, ?string $chatId = null, string $backAction = 'home'): array
{
    $rows = [
        [
            fb_bot_menu_callback_button('⬅️ Back', $backAction),
        ],
    ];

    // include follow buttons for submenu where applicable
    $followRows = fb_customer_guide_reply_markup($config, $db, [])['inline_keyboard'] ?? [];

    foreach ($followRows as $r) {
        $rows[] = $r;
    }

    return ['inline_keyboard' => $rows];
}

function fb_text_to_html(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
    return str_replace("\n", '<br>', $escaped);
}

function fb_bot_menu_action_label(string $action): string
{
    return match ($action) {
        'home' => 'Sports Hub',
        'live' => 'Live scores',
        'live_all' => 'Live — all sports',
        'live_football' => 'Live — football',
        'fixtures' => 'Fixtures today',
        'fixtures_all' => 'Fixtures — all sports',
        'fixtures_football' => 'Fixtures — football',
        'fixtures_basketball' => 'Fixtures — basketball',
        'tv' => 'TV guide',
        'tv_now' => 'TV — now',
        'tv_today' => 'TV — today',
        'tables' => 'League tables',
        'tables_football' => 'League tables — football',
        'scorers' => 'Top scorers',
        'favourites' => 'My favourites',
        'my_teams' => 'My teams',
        'premium' => 'Premium',
        default => 'Bot menu',
    };
}

function fb_format_bot_live_scores_message(array $config, SQLite3 $db): string
{
    try {
        $rows = fb_filter_allowed_matches($config, fb_fetch_live_scores($config, $db), $db);
    } catch (Throwable $error) {
        return 'Live scores are unavailable right now: ' . $error->getMessage();
    }

    $matches = array_map('fb_normalize_match', array_slice($rows, 0, 10));
    $lines = ['Live scores'];

    if ($matches === []) {
        $lines[] = 'No tracked matches are live right now.';
        return implode("\n", $lines);
    }

    foreach ($matches as $match) {
        $status = trim((string) ($match['progress'] ?? '')) !== '' ? (string) $match['progress'] . "'" : trim((string) ($match['status'] ?? 'Live'));
        $lines[] = sprintf(
            '%s: %s %d-%d %s %s',
            $match['sport'] ?? 'Sport',
            $match['home_team'] ?? 'Home',
            (int) ($match['home_score'] ?? 0),
            (int) ($match['away_score'] ?? 0),
            $match['away_team'] ?? 'Away',
            $status !== '' ? '(' . $status . ')' : ''
        );
    }

    return implode("\n", $lines);
}

function fb_format_bot_fixtures_message(array $config, SQLite3 $db): string
{
    try {
        $matches = fb_prepare_card_matches($config, $db, fb_fetch_upcoming_matches($config, $db, 24), true);
    } catch (Throwable $error) {
        return 'Fixtures are unavailable right now: ' . $error->getMessage();
    }

    $lines = ['Fixtures today'];

    if ($matches === []) {
        $lines[] = 'No tracked fixtures found in the next 24 hours.';
        return implode("\n", $lines);
    }

    foreach (array_slice($matches, 0, 12) as $match) {
        $lines[] = fb_customer_guide_match_line($match, is_array($match['tv_channels'] ?? null) ? $match['tv_channels'] : []);
    }

    if (count($matches) > 12) {
        $lines[] = '+' . (count($matches) - 12) . ' more in the next 24 hours.';
    }

    return implode("\n", $lines);
}

function fb_format_bot_tv_message(array $config, SQLite3 $db): string
{
    try {
        $events = fb_tv_events_in_window($config, fb_fetch_tv_events($config, $db), (int) ($config['tv']['lookahead_hours'] ?? 24));
    } catch (Throwable $error) {
        return 'TV guide is unavailable right now: ' . $error->getMessage();
    }

    $lines = ['TV guide'];

    if ($events === []) {
        $lines[] = 'No configured TV listings found in the current window.';
        return implode("\n", $lines);
    }

    foreach (array_slice($events, 0, 12) as $event) {
        $lines[] = fb_customer_guide_tv_line($event);
    }

    if (count($events) > 12) {
        $lines[] = '+' . (count($events) - 12) . ' more TV listings.';
    }

    return implode("\n", $lines);
}

function fb_format_bot_favourites_message(SQLite3 $db): string
{
    $teams = fb_customer_follow_terms($db, 'team');
    $players = fb_customer_follow_terms($db, 'player');
    $feeds = fb_customer_follow_terms($db, 'feed');
    $lines = ['My favourites'];

    $lines[] = $feeds !== [] ? 'Feeds: ' . implode(', ', array_slice($feeds, 0, 8)) : 'Feeds: none saved yet.';
    $lines[] = $teams !== [] ? 'Teams: ' . implode(', ', array_slice($teams, 0, 8)) : 'Teams: none saved yet.';
    $lines[] = $players !== [] ? 'Players: ' . implode(', ', array_slice($players, 0, 8)) : 'Players: none saved yet.';

    return implode("\n", $lines);
}

function fb_format_bot_action_message(array $config, SQLite3 $db, string $action): string
{
    return match ($action) {
        'live' => fb_format_bot_live_scores_message($config, $db),
        'fixtures' => fb_format_bot_fixtures_message($config, $db),
        'tv' => fb_format_bot_tv_message($config, $db),
        'favourites' => fb_format_bot_favourites_message($db),
        'tables' => "League tables\nUse this as a text/table topic, not a match card. The bot menu is ready for it; table data can be wired to the provider endpoint you want to use.",
        'scorers' => "Top scorers\nUse this as a text/table topic, not a match card. The bot menu is ready for it; scorer data can be wired to the provider endpoint you want to use.",
        default => fb_bot_menu_text(),
    };
}

function fb_format_bot_submenu_message(array $config, SQLite3 $db, string $action): string
{
    // map action to plain text then convert to HTML-aware output
    switch ($action) {
        case 'live':
        case 'live_all':
            $title = '<b>Live — All sports</b>';
            $body = fb_format_bot_live_scores_message($config, $db);
            return $title . '<br>' . fb_text_to_html($body);

        case 'live_football':
            $title = '<b>Live — Football</b>';
            // Mock: reuse live scores output with a note about filtering to football
            $body = fb_format_bot_live_scores_message($config, $db) . "\n\n(Filtered to Football — sample data)";
            return $title . '<br>' . fb_text_to_html($body);

        case 'fixtures':
        case 'fixtures_all':
            $title = '<b>Fixtures — All sports</b>';
            $body = fb_format_bot_fixtures_message($config, $db);
            return $title . '<br>' . fb_text_to_html($body);

        case 'fixtures_football':
            $title = '<b>Fixtures — Football</b>';
            $body = fb_format_bot_fixtures_message($config, $db) . "\n\n(Filtered to Football — sample)";
            return $title . '<br>' . fb_text_to_html($body);

        case 'fixtures_basketball':
            $title = '<b>Fixtures — Basketball</b>';
            $body = "No tracked basketball fixtures in the next 24 hours.\nSample upcoming:\n- Hawks vs Tigers — 19:30\n- City Ballers vs Downtown — 21:00";
            return $title . '<br>' . fb_text_to_html($body);

        case 'tv':
        case 'tv_now':
            $title = '<b>TV — Now</b>';
            $body = fb_format_bot_tv_message($config, $db);
            return $title . '<br>' . fb_text_to_html($body);

        case 'tv_today':
            $title = '<b>TV — Today</b>';
            $body = fb_format_bot_tv_message($config, $db);
            return $title . '<br>' . fb_text_to_html($body);

        case 'tables':
        case 'tables_football':
            $title = '<b>League tables — Football</b>';
            $body = "Sample table:\n1. Red FC  38 pts\n2. Blue United 36 pts\n3. Town FC  34 pts\n\n(Real table data can be wired to your provider later.)";
            return $title . '<br>' . fb_text_to_html($body);

        case 'scorers':
            $title = '<b>Top scorers</b>';
            $body = "1. A. Striker — 18\n2. B. Forward — 15\n3. C. Ace — 14\n\n(Scorers are sample/mock data.)";
            return $title . '<br>' . fb_text_to_html($body);

        case 'favourites':
        case 'my_teams':
            $title = '<b>My Teams & Favourites</b>';
            $body = fb_format_bot_favourites_message($db);
            return $title . '<br>' . fb_text_to_html($body);

        case 'premium':
            $title = '<b>Premium</b>';
            $body = "Premium features are coming soon.\nYou can follow teams and feeds today; premium content and payments are not configured yet.";
            return $title . '<br>' . fb_text_to_html($body);

        default:
            // fallback to the legacy formatter and convert
            $title = '<b>Sports Hub</b>';
            $body = fb_format_bot_action_message($config, $db, $action);
            return $title . '<br>' . fb_text_to_html($body);
    }
}

function fb_customer_guide_reply_markup(array $config, SQLite3 $db, array $matches): array
{
    if (empty($config['customer']['follow_buttons_enabled'])) {
        return [];
    }

    $maxButtons = max(0, (int) ($config['customer']['max_follow_buttons'] ?? 8));

    if ($maxButtons === 0) {
        return [];
    }

    $candidates = [];

    foreach (fb_watchlist_terms($config, 'team_watchlist') as $team) {
        $candidates[fb_follow_subject_key('team', $team)] = ['kind' => 'team', 'sport' => '', 'subject' => $team];
    }

    foreach ($matches as $match) {
        $sport = trim((string) ($match['sport'] ?? ''));
        foreach (['home_team', 'away_team'] as $field) {
            $subject = trim((string) ($match[$field] ?? ''));

            if ($subject === '' || in_array(strtolower($subject), ['home', 'away'], true)) {
                continue;
            }

            $candidates[fb_follow_subject_key('team', $subject)] = [
                'kind' => 'team',
                'sport' => $sport,
                'subject' => $subject,
            ];
        }
    }

    foreach (fb_watchlist_terms($config, 'player_watchlist') as $player) {
        $candidates[fb_follow_subject_key('player', $player)] = ['kind' => 'player', 'sport' => '', 'subject' => $player];
    }

    $rows = [];
    $row = [];
    $buttonCount = 0;
    $pushButton = static function (array $button) use (&$rows, &$row, &$buttonCount, $maxButtons): void {
        if ($buttonCount >= $maxButtons) {
            return;
        }

        $row[] = $button;
        $buttonCount++;

        if (count($row) === 2) {
            $rows[] = $row;
            $row = [];
        }
    };

    foreach (fb_customer_guide_topic_buttons($config) as $button) {
        $pushButton($button);
    }

    foreach (fb_customer_guide_feed_buttons($db) as $button) {
        $pushButton($button);
    }

    foreach (array_slice(array_values($candidates), 0, $maxButtons) as $candidate) {
        if ($buttonCount >= $maxButtons) {
            break;
        }

        $callbackData = fb_register_follow_button(
            $db,
            (string) $candidate['kind'],
            (string) $candidate['sport'],
            (string) $candidate['subject'],
            ['source' => 'customer_guide']
        );

        if ($callbackData === null) {
            continue;
        }

        $label = ($candidate['kind'] === 'player' ? 'Follow player ' : 'Follow ') . (string) $candidate['subject'];
        $pushButton([
            'text' => strlen($label) > 32 ? substr($label, 0, 29) . '...' : $label,
            'callback_data' => $callbackData,
        ]);
    }

    if ($row !== []) {
        $rows[] = $row;
    }

    return $rows !== [] ? ['inline_keyboard' => $rows] : [];
}

function fb_format_customer_guide_message(array $config, SQLite3 $db): array
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $hoursAhead = max(1, (int) ($config['customer']['guide_lookahead_hours'] ?? 24));
    $teamWatchlist = array_values(array_unique(array_merge(
        fb_watchlist_terms($config, 'team_watchlist'),
        fb_customer_follow_terms($db, 'team')
    )));
    $playerWatchlist = array_values(array_unique(array_merge(
        fb_watchlist_terms($config, 'player_watchlist'),
        fb_customer_follow_terms($db, 'player')
    )));
    $liveMatches = [];
    $fixtures = [];
    $tvEvents = [];
    $notes = [];

    try {
        $liveMatches = array_map(
            'fb_normalize_match',
            fb_filter_allowed_matches($config, fb_fetch_live_scores($config, $db), $db)
        );
    } catch (Throwable $error) {
        $notes[] = 'Live scores unavailable: ' . $error->getMessage();
    }

    try {
        $fixtures = array_map('fb_normalize_match', fb_fetch_upcoming_matches($config, $db, $hoursAhead));
    } catch (Throwable $error) {
        $notes[] = 'Fixtures unavailable: ' . $error->getMessage();
    }

    $eventIds = array_values(array_filter(array_map(static fn (array $match): string => (string) ($match['event_id'] ?? ''), $fixtures)));
    $tvByEventId = [];

    try {
        $tvByEventId = fb_tv_channels_for_event_ids($config, $db, $eventIds);
        $tvEvents = fb_tv_events_in_window($config, fb_fetch_tv_events($config, $db), $hoursAhead);
    } catch (Throwable $error) {
        $notes[] = 'TV guide unavailable: ' . $error->getMessage();
    }

    $lines = [
        'Sports Guide - ' . $now->format('D j M'),
        'Next ' . $hoursAhead . ' hours across scores, fixtures and TV.',
    ];

    $lines[] = '';
    $lines[] = 'Live scores';
    if ($liveMatches === []) {
        $lines[] = 'No tracked live scores right now.';
    } else {
        foreach (array_slice($liveMatches, 0, 10) as $match) {
            $lines[] = fb_customer_guide_match_line($match);
        }
    }

    $lines[] = '';
    $lines[] = 'Fixtures';
    if ($fixtures === []) {
        $lines[] = 'No tracked fixtures in this window.';
    } else {
        foreach (array_slice($fixtures, 0, 12) as $match) {
            $lines[] = fb_customer_guide_match_line($match, $tvByEventId[$match['event_id']] ?? []);
        }
    }

    $lines[] = '';
    $lines[] = 'TV and channels';
    if ($tvEvents === []) {
        $lines[] = 'No configured TV listings in this window.';
    } else {
        foreach (array_slice($tvEvents, 0, 12) as $event) {
            $lines[] = fb_customer_guide_tv_line($event);
        }
    }

    $followedMatches = [];
    foreach (array_merge($liveMatches, $fixtures) as $match) {
        $tags = fb_match_watchlist_tags($match, $teamWatchlist);

        if ($tags !== []) {
            $followedMatches[] = implode(', ', $tags) . ': ' . fb_customer_guide_match_line($match, $tvByEventId[$match['event_id']] ?? []);
        }
    }

    $lines[] = '';
    $lines[] = 'Followed teams';
    if ($followedMatches === []) {
        $lines[] = $teamWatchlist === [] ? 'No team watchlist configured yet.' : 'No followed-team fixtures found in this window.';
    } else {
        foreach (array_slice($followedMatches, 0, 8) as $line) {
            $lines[] = $line;
        }
    }

    if ($playerWatchlist !== []) {
        $lines[] = '';
        $lines[] = 'Followed players';
        $lines[] = implode(', ', array_slice($playerWatchlist, 0, 12));
        $lines[] = 'Player alerts use available event/timeline data only.';
    }

    if ($notes !== []) {
        $lines[] = '';
        $lines[] = 'Data notes';
        foreach (array_slice($notes, 0, 3) as $note) {
            $lines[] = $note;
        }
    }

    $message = trim(implode("\n", $lines));

    if (strlen($message) > 3900) {
        $message = substr($message, 0, 3900) . "\nMore items hidden in this guide.";
    }

    $buttonMatches = array_merge(array_slice($liveMatches, 0, 6), array_slice($fixtures, 0, 8));

    return [
        'text' => $message,
        'reply_markup' => fb_customer_guide_reply_markup($config, $db, $buttonMatches),
        'meta' => [
            'live_count' => count($liveMatches),
            'fixture_count' => count($fixtures),
            'tv_count' => count($tvEvents),
            'team_watchlist_count' => count($teamWatchlist),
            'player_watchlist_count' => count($playerWatchlist),
            'lookahead_hours' => $hoursAhead,
        ],
    ];
}

function fb_detect_customer_guide_alerts(array $config, SQLite3 $db): array
{
    if (empty($config['customer']['guide_enabled'])) {
        return [];
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $scheduled = new DateTimeImmutable($now->format('Y-m-d') . ' ' . fb_customer_guide_time($config), $tz);

    if ($now < $scheduled) {
        return [];
    }

    $key = 'customer_guide:' . $now->format('Y-m-d');

    if (fb_was_alert_sent($db, $key)) {
        return [];
    }

    $guide = fb_format_customer_guide_message($config, $db);

    return [[
        'key' => $key,
        'type' => 'CUSTOMER_GUIDE',
        'text' => $guide['text'],
        'reply_markup' => $guide['reply_markup'],
        'meta' => $guide['meta'],
    ]];
}

function fb_health_status(bool $ok, string $label, string $detail = ''): array
{
    return [
        'ok' => $ok,
        'status' => $ok ? 'ok' : 'fail',
        'label' => $label,
        'detail' => $detail,
    ];
}

function fb_system_health(array $config, ?SQLite3 $db = null): array
{
    $checks = [];
    $checks[] = fb_health_status(!empty($config['telegram']['bot_token']), 'Telegram token', !empty($config['telegram']['bot_token']) ? 'Configured' : 'Missing');
    $checks[] = fb_health_status(!empty($config['telegram']['chat_id']), 'Primary chat', !empty($config['telegram']['chat_id']) ? 'Configured' : 'Missing');
    $checks[] = fb_health_status(!empty($config['thesportsdb']['api_key']), 'TheSportsDB key', !empty($config['thesportsdb']['api_key']) ? 'Configured' : 'Missing');

    foreach (['curl', 'json', 'sqlite3', 'gd'] as $extension) {
        $checks[] = fb_health_status(extension_loaded($extension), 'PHP extension ' . $extension, extension_loaded($extension) ? 'Loaded' : 'Missing');
    }

    foreach (['cache', 'image_cache', 'generated', 'logs'] as $pathKey) {
        $path = (string) ($config['paths'][$pathKey] ?? '');
        $checks[] = fb_health_status($path !== '' && is_dir($path) && is_writable($path), 'Writable ' . $pathKey, $path);
    }

    $nodeBin = function_exists('fb_node_binary') ? fb_node_binary() : trim((string) exec('command -v node 2>/dev/null'));
    $checks[] = fb_health_status($nodeBin !== '', 'Node renderer', $nodeBin !== '' ? $nodeBin : 'Unavailable; GD fallback required');

    $renderEngine = (string) ($config['images']['render_engine'] ?? 'auto');
    $checks[] = fb_health_status(in_array($renderEngine, ['auto', 'puppeteer', 'gd'], true), 'Render engine', $renderEngine);

    $runLock = (string) ($config['paths']['run_lock'] ?? '');
    $lockAge = is_file($runLock) ? time() - (int) filemtime($runLock) : null;
    $checks[] = fb_health_status($lockAge === null || $lockAge < 600, 'Cron freshness', $lockAge === null ? 'No lock file yet' : $lockAge . ' seconds since lock touched');

    $host = parse_url((string) ($config['thesportsdb']['base_url'] ?? ''), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $resolved = gethostbyname($host);
        $checks[] = fb_health_status($resolved !== $host, 'TheSportsDB DNS', $resolved !== $host ? $resolved : 'Could not resolve ' . $host);
    }

    $telegramHost = parse_url((string) ($config['telegram']['api_base'] ?? ''), PHP_URL_HOST);
    if (is_string($telegramHost) && $telegramHost !== '') {
        $resolved = gethostbyname($telegramHost);
        $checks[] = fb_health_status($resolved !== $telegramHost, 'Telegram DNS', $resolved !== $telegramHost ? $resolved : 'Could not resolve ' . $telegramHost);
    }

    if ($db instanceof SQLite3) {
        $pending = (int) ($db->querySingle('SELECT COUNT(*) FROM telegram_outbox WHERE status IN ("pending", "sending", "failed")') ?: 0);
        $checks[] = fb_health_status($pending < 25, 'Outbox backlog', $pending . ' pending/failed item(s)');
    }

    return $checks;
}

function fb_run_render_health_check(array $config, SQLite3 $db): array
{
    $started = microtime(true);
    $engine = (string) ($config['images']['render_engine'] ?? 'auto');
    $status = 'fail';
    $message = '';
    $imagePath = null;

    try {
        if (!function_exists('fb_sample_alerts') || !function_exists('fb_generate_alert_image')) {
            throw new RuntimeException('Image renderer is not loaded.');
        }

        $samples = fb_sample_alerts();
        $sample = $samples[0] ?? null;

        if (!is_array($sample)) {
            throw new RuntimeException('No sample alert is available.');
        }

        $imagePath = fb_generate_alert_image($config, $sample);

        if (!is_file($imagePath) || filesize($imagePath) <= 0) {
            throw new RuntimeException('Renderer produced no image output.');
        }

        $status = 'ok';
        $message = basename($imagePath);
    } catch (Throwable $error) {
        $message = $error->getMessage();
    }

    $durationMs = (int) round((microtime(true) - $started) * 1000);
    fb_execute(
        $db,
        'INSERT INTO render_health_checks (engine, status, message, image_path, duration_ms, created_at)
         VALUES (:engine, :status, :message, :image_path, :duration_ms, :created_at)',
        [
            ':engine' => $engine,
            ':status' => $status,
            ':message' => $message,
            ':image_path' => $imagePath,
            ':duration_ms' => $durationMs,
            ':created_at' => fb_now(),
        ]
    );

    return [
        'engine' => $engine,
        'status' => $status,
        'message' => $message,
        'image_path' => $imagePath,
        'duration_ms' => $durationMs,
    ];
}

function fb_maybe_send_daily_health_summary(array $config, SQLite3 $db): void
{
    if (empty($config['health']['alerts_enabled'])) {
        return;
    }

    $errorChatId = trim((string) ($config['alerts']['error_alert_chat_id'] ?? ''));
    if ($errorChatId === '' || empty($config['telegram']['bot_token'])) {
        return;
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $time = trim((string) ($config['health']['alert_time'] ?? '07:30'));
    if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $time = '07:30';
    }
    [$hour, $minute] = array_map('intval', explode(':', $time, 2));
    $dueAt = $now->setTime(max(0, min(23, $hour)), max(0, min(59, $minute)));

    if ($now->getTimestamp() < $dueAt->getTimestamp()) {
        return;
    }

    $alertKey = 'health:' . $now->format('Y-m-d');
    if (fb_was_alert_sent($db, $alertKey)) {
        return;
    }

    $checks = fb_system_health($config, $db);
    $failed = array_values(array_filter($checks, static fn(array $check): bool => empty($check['ok'])));
    $lines = [
        'Bot health summary',
        count($failed) === 0 ? 'All checks passed.' : count($failed) . ' check(s) need attention.',
        '',
    ];

    foreach ($checks as $check) {
        $lines[] = sprintf('%s: %s (%s)', $check['label'], $check['status'], $check['detail']);
    }

    $alert = [
        'key' => $alertKey,
        'type' => 'HEALTH_SUMMARY',
        'event_id' => $alertKey,
        'sport' => 'System',
        'meta' => ['failed' => count($failed)],
    ];
    $result = fb_telegram_send_message_to_outbox($config, $db, 'health:' . $now->format('Ymd'), $errorChatId, implode("\n", $lines), $alertKey);

    if (($result['ok'] ?? false) === true) {
        fb_mark_simple_alert_sent($db, $alertKey, 'HEALTH_SUMMARY', ['failed' => count($failed)]);
        fb_record_alert_decision($db, 'sent', 'Daily health summary delivered to the error chat.', $alert);
    } else {
        fb_record_alert_decision($db, 'failed', 'Daily health summary could not be delivered.', $alert, ['result' => $result]);
    }
}

function fb_fetch_one(SQLite3 $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return $row === false ? null : $row;
}

function fb_execute(SQLite3 $db, string $sql, array $params = []): void
{
    $stmt = $db->prepare($sql);

    foreach ($params as $key => $value) {
        if ($value === null) {
            $stmt->bindValue($key, null, SQLITE3_NULL);
        } elseif (is_int($value)) {
            $stmt->bindValue($key, $value, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue($key, (string) $value, SQLITE3_TEXT);
        }
    }

    $stmt->execute();
}

function fb_api_cache_get(SQLite3 $db, string $cacheKey): ?array
{
    $row = fb_fetch_one(
        $db,
        'SELECT body, status_code, expires_at FROM api_cache WHERE cache_key = :cache_key',
        [':cache_key' => $cacheKey]
    );

    if (!$row || (int) $row['expires_at'] < time()) {
        return null;
    }

    return [
        'body' => $row['body'],
        'status_code' => (int) $row['status_code'],
    ];
}

function fb_api_cache_set(SQLite3 $db, string $cacheKey, string $body, int $statusCode, int $ttl): void
{
    if ($ttl <= 0) {
        return;
    }

    fb_execute(
        $db,
        'INSERT INTO api_cache (cache_key, body, status_code, expires_at, updated_at)
         VALUES (:cache_key, :body, :status_code, :expires_at, :updated_at)
         ON CONFLICT(cache_key) DO UPDATE SET
            body = excluded.body,
            status_code = excluded.status_code,
            expires_at = excluded.expires_at,
            updated_at = excluded.updated_at',
        [
            ':cache_key' => $cacheKey,
            ':body' => $body,
            ':status_code' => $statusCode,
            ':expires_at' => time() + $ttl,
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_rate_limit(array $config): void
{
    $lockPath = $config['paths']['api_cache_lock'];
    $minInterval = (int) $config['thesportsdb']['min_request_interval_ms'];
    $handle = fopen($lockPath, 'c+');

    if (!$handle) {
        return;
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $last = trim((string) stream_get_contents($handle));
    $lastFloat = is_numeric($last) ? (float) $last : 0.0;
    $elapsedMs = (microtime(true) - $lastFloat) * 1000;

    if ($lastFloat > 0 && $elapsedMs < $minInterval) {
        usleep((int) (($minInterval - $elapsedMs) * 1000));
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, sprintf('%.6F', microtime(true)));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function fb_http_json(string $url, array $headers, int $timeout, int $connectTimeout): array
{
    fb_require_extensions(['curl', 'json']);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $body = substr((string) $response, $headerSize);
    curl_close($curl);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(sprintf('HTTP %d returned from %s: %s', $statusCode, $url, substr($body, 0, 300)));
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON returned from ' . $url);
    }

    return [
        'status_code' => $statusCode,
        'body' => $body,
        'json' => $decoded,
    ];
}

function fb_thesportsdb_get(array $config, SQLite3 $db, string $path, int $ttl = 0): array
{
    $path = '/' . ltrim($path, '/');
    $cacheKey = 'tsdb:' . sha1($path);
    $cached = $ttl > 0 ? fb_api_cache_get($db, $cacheKey) : null;

    if ($cached !== null) {
        $json = json_decode($cached['body'], true);
        return is_array($json) ? $json : [];
    }

    fb_rate_limit($config);

    $url = rtrim($config['thesportsdb']['base_url'], '/') . $path;
    $response = fb_http_json($url, [
        'X-API-KEY: ' . $config['thesportsdb']['api_key'],
        'Accept: application/json',
    ], (int) $config['thesportsdb']['timeout'], (int) $config['thesportsdb']['connect_timeout']);

    fb_api_cache_set($db, $cacheKey, $response['body'], $response['status_code'], $ttl);

    return $response['json'];
}

function fb_normalize_text(?string $value): string
{
    $value = strtolower(trim((string) $value));
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return $value;
}

function fb_allowed_league(array $config, array $match): ?array
{
    $leagueId = (string) ($match['idLeague'] ?? '');

    if (isset($config['leagues']['allowed'][$leagueId])) {
        return [
            'id' => $leagueId,
            'name' => $config['leagues']['allowed'][$leagueId]['name'],
        ];
    }

    $leagueName = fb_normalize_text($match['strLeague'] ?? null);

    foreach ($config['leagues']['allowed'] as $id => $league) {
        foreach ($league['aliases'] as $alias) {
            if ($leagueName === fb_normalize_text($alias)) {
                return [
                    'id' => (string) $id,
                    'name' => $league['name'],
                ];
            }
        }
    }

    return null;
}

function fb_sport_key(string $sport): string
{
    return fb_normalize_text($sport);
}

function fb_canonical_sport(?string $sport, string $fallback = 'Soccer'): string
{
    $raw = trim((string) $sport);
    $key = fb_sport_key($raw);

    if ($key === '') {
        return $fallback;
    }

    return match ($key) {
        'football' => 'Soccer',
        'rugby union', 'rugby league', 'rugby' => 'Rugby',
        'formula one', 'f1' => 'Formula 1',
        'mixed martial arts', 'ultimate fighting championship', 'ufc' => 'MMA',
        default => $raw,
    };
}

function fb_enabled_sport_keys(array $config): array
{
    $sports = $config['coverage']['enabled_sports'] ?? $config['sports']['available'] ?? ['Soccer'];
    $keys = [];

    foreach ($sports as $sport) {
        $canonical = fb_canonical_sport((string) $sport, (string) $sport);
        $keys[fb_sport_key((string) $sport)] = true;
        $keys[fb_sport_key($canonical)] = true;

        if (fb_sport_key($canonical) === 'rugby') {
            $keys['rugby union'] = true;
            $keys['rugby league'] = true;
        }
    }

    return $keys;
}

function fb_sport_enabled(array $config, string $sport): bool
{
    $key = fb_sport_key($sport);
    $canonicalKey = fb_sport_key(fb_canonical_sport($sport, $sport));
    $enabled = fb_enabled_sport_keys($config);

    return isset($enabled[$key]) || isset($enabled[$canonicalKey]);
}

function fb_match_sport(array $event): string
{
    $sport = trim((string) ($event['_allowedSport'] ?? $event['strSport'] ?? ''));

    if ($sport !== '') {
        return fb_canonical_sport($sport, $sport);
    }

    return 'Soccer';
}

function fb_coverage_league_filter_ids(array $config): array
{
    return array_values(array_filter(array_map('strval', $config['coverage']['enabled_league_ids'] ?? [])));
}

function fb_coverage_legacy_soccer_ids(array $config): array
{
    $ids = $config['coverage']['legacy_soccer_league_ids'] ?? array_keys($config['leagues']['allowed'] ?? []);

    return array_values(array_filter(array_map('strval', $ids)));
}

function fb_coverage_match_allowed(array $config, array $event, ?SQLite3 $db = null): ?array
{
    $leagueId = (string) ($event['idLeague'] ?? $event['_allowedLeagueId'] ?? '');
    $sport = fb_match_sport($event);

    if ($sport === 'Soccer' && $leagueId !== '' && isset($config['leagues']['allowed'][$leagueId])) {
        $event['_allowedSport'] = 'Soccer';
        $legacyLeague = fb_allowed_league($config, $event);
        $sport = 'Soccer';
    } else {
        $legacyLeague = null;
    }

    if (!fb_sport_enabled($config, $sport)) {
        return null;
    }

    $explicitLeagueIds = fb_coverage_league_filter_ids($config);

    if ($explicitLeagueIds !== [] && $leagueId !== '' && !in_array($leagueId, $explicitLeagueIds, true)) {
        if (!($sport === 'Soccer' && in_array($leagueId, fb_coverage_legacy_soccer_ids($config), true))) {
            return null;
        }
    }

    if ($explicitLeagueIds === [] && $sport === 'Soccer') {
        $legacy = $legacyLeague ?? fb_allowed_league($config, $event);

        if ($legacy === null) {
            return null;
        }

        return [
            'id' => $legacy['id'],
            'name' => $legacy['name'],
            'sport' => 'Soccer',
        ];
    }

    if ($explicitLeagueIds === [] && $sport !== 'Soccer') {
        $country = (string) ($event['strCountry'] ?? '');

        if ($country !== '' && !fb_coverage_country_enabled($config, $country)) {
            return null;
        }
    }

    $leagueName = trim((string) ($event['_allowedLeagueName'] ?? $event['strLeague'] ?? ''));

    return [
        'id' => $leagueId,
        'name' => $leagueName !== '' ? $leagueName : $sport,
        'sport' => $sport,
    ];
}

function fb_extract_list(array $payload, array $keys): array
{
    foreach ($keys as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

function fb_fetch_live_scores(array $config, SQLite3 $db): array
{
    try {
        $payload = fb_thesportsdb_get(
            $config,
            $db,
            '/livescore/all',
            (int) $config['thesportsdb']['livescore_cache_ttl']
        );

        return fb_extract_list($payload, ['livescore', 'events', 'matches']);
    } catch (Throwable $error) {
        fb_log('warning', 'Could not fetch all-sports livescore; falling back to soccer', [
            'error' => $error->getMessage(),
        ]);
    }

    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/livescore/soccer',
        (int) $config['thesportsdb']['livescore_cache_ttl']
    );

    $rows = fb_extract_list($payload, ['livescore', 'events', 'matches']);

    foreach ($rows as &$row) {
        if (is_array($row) && empty($row['strSport'])) {
            $row['strSport'] = 'Soccer';
        }
    }
    unset($row);

    return $rows;
}

function fb_filter_allowed_matches(array $config, array $matches, ?SQLite3 $db = null): array
{
    $allowed = [];
    $perSportCounts = [];
    $maxPerSport = max(1, (int) ($config['thesportsdb']['max_live_matches_per_sport'] ?? $config['thesportsdb']['max_live_matches_per_run'] ?? 25));

    foreach ($matches as $match) {
        if (!is_array($match)) {
            continue;
        }

        $league = fb_coverage_match_allowed($config, $match, $db);

        if ($league === null) {
            continue;
        }

        $match['_allowedLeagueId'] = $league['id'];
        $match['_allowedLeagueName'] = $league['name'];
        $match['_allowedSport'] = $league['sport'];
        $sportKey = fb_sport_key((string) $league['sport']);

        if (($perSportCounts[$sportKey] ?? 0) >= $maxPerSport) {
            continue;
        }

        $perSportCounts[$sportKey] = ($perSportCounts[$sportKey] ?? 0) + 1;
        $allowed[] = $match;
    }

    return array_slice($allowed, 0, (int) $config['thesportsdb']['max_live_matches_per_run']);
}

function fb_int_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric((string) $value) ? (int) $value : null;
}

function fb_progress_or_null(mixed $value): ?int
{
    $int = fb_int_or_null($value);

    if ($int !== null) {
        return $int;
    }

    $text = trim((string) $value);

    if ($text !== '' && preg_match('/\d+/', $text, $matches) === 1) {
        return (int) $matches[0];
    }

    return null;
}

function fb_normalize_status(array $match): string
{
    $status = strtoupper(trim((string) ($match['strStatus'] ?? '')));
    $progress = strtoupper(trim((string) ($match['strProgress'] ?? $match['intProgress'] ?? '')));

    if ($status !== '') {
        return $status;
    }

    if ($progress === '') {
        return '';
    }

    if (str_contains($progress, 'FINAL') || str_contains($progress, 'FULL')) {
        return 'FT';
    }

    if (str_contains($progress, 'HALF')) {
        return 'HT';
    }

    if (preg_match('/\b(1H|2H|Q[1-4]|[1-4]Q|OT|LIVE|IN PLAY)\b/', $progress) === 1 || preg_match('/^\d+/', $progress) === 1) {
        return 'LIVE';
    }

    return $progress;
}

function fb_event_datetime(array $event, DateTimeZone $tz): ?DateTimeImmutable
{
    $timestamp = trim((string) ($event['strTimestamp'] ?? $event['strEventTimestamp'] ?? ''));

    if ($timestamp !== '') {
        try {
            return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->setTimezone($tz);
        } catch (Throwable) {
            // Fall through to explicit date/time fields.
        }
    }

    $dateStr = trim((string) ($event['dateEvent'] ?? ''));
    $timeStr = trim((string) ($event['strTime'] ?? $event['strEventTime'] ?? '00:00:00'));

    if ($dateStr === '') {
        return null;
    }

    try {
        return new DateTimeImmutable(trim($dateStr . ' ' . $timeStr), $tz);
    } catch (Throwable) {
        return null;
    }
}

function fb_with_local_event_time(array $event, DateTimeImmutable $eventTime): array
{
    $event['_eventLocalTime'] = $eventTime->format('H:i');
    $event['_eventLocalDate'] = $eventTime->format('Y-m-d');
    $event['_eventStartsAt'] = $eventTime->format(DateTimeInterface::ATOM);

    return $event;
}

function fb_normalize_match(array $match): array
{
    $homeScore = fb_int_or_null($match['intHomeScore'] ?? null) ?? 0;
    $awayScore = fb_int_or_null($match['intAwayScore'] ?? null) ?? 0;
    $progress = fb_progress_or_null($match['strProgress'] ?? $match['intProgress'] ?? null);
    $sport = fb_match_sport($match);
    $eventName = trim((string) ($match['strEvent'] ?? $match['strFilename'] ?? ''));
    $homeTeam = trim((string) ($match['strHomeTeam'] ?? ''));
    $awayTeam = trim((string) ($match['strAwayTeam'] ?? ''));

    if ($homeTeam === '' && $awayTeam === '' && $eventName !== '') {
        $parts = preg_split('/\s+v(?:s|\.)?\s+/i', $eventName, 2) ?: [];
        if (count($parts) === 2) {
            $homeTeam = trim($parts[0]);
            $awayTeam = trim($parts[1]);
        }
    }

    if ($homeTeam === '') {
        $homeTeam = $eventName !== '' ? $eventName : 'Home';
    }

    if ($awayTeam === '') {
        $awayTeam = $eventName !== '' && $homeTeam !== $eventName ? $eventName : 'Away';
    }

    return [
        'event_id' => (string) ($match['idEvent'] ?? $match['idLiveScore'] ?? sha1(json_encode($match))),
        'sport' => $sport,
        'league_id' => (string) ($match['_allowedLeagueId'] ?? $match['idLeague'] ?? ''),
        'league_name' => (string) ($match['_allowedLeagueName'] ?? $match['strLeague'] ?? $sport),
        'event_name' => $eventName !== '' ? $eventName : trim($homeTeam . ' vs ' . $awayTeam),
        'home_team_id' => (string) ($match['idHomeTeam'] ?? ''),
        'away_team_id' => (string) ($match['idAwayTeam'] ?? ''),
        'home_team' => $homeTeam,
        'away_team' => $awayTeam,
        'home_badge' => (string) ($match['strHomeTeamBadge'] ?? ''),
        'away_badge' => (string) ($match['strAwayTeamBadge'] ?? ''),
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'status' => fb_normalize_status($match),
        'progress' => $progress,
        'event_time' => (string) ($match['_eventLocalTime'] ?? $match['strEventTime'] ?? $match['strTime'] ?? ''),
        'date_event' => (string) ($match['_eventLocalDate'] ?? $match['dateEvent'] ?? ''),
        'starts_at' => (string) ($match['_eventStartsAt'] ?? $match['strTimestamp'] ?? ''),
        'venue' => (string) ($match['strVenue'] ?? $match['strStadium'] ?? $match['strLocation'] ?? ''),
        'updated' => (string) ($match['updated'] ?? ''),
        'raw_hash' => sha1(json_encode($match)),
    ];
}

function fb_get_event_state(SQLite3 $db, string $eventId): ?array
{
    return fb_fetch_one(
        $db,
        'SELECT * FROM event_state WHERE event_id = :event_id',
        [':event_id' => $eventId]
    );
}

function fb_save_event_state(SQLite3 $db, array $match): void
{
    fb_execute(
        $db,
        'INSERT INTO event_state (
            event_id, sport, league_id, status, progress, home_score, away_score, raw_hash, first_seen_at, updated_at
        ) VALUES (
            :event_id, :sport, :league_id, :status, :progress, :home_score, :away_score, :raw_hash, :first_seen_at, :updated_at
        ) ON CONFLICT(event_id) DO UPDATE SET
            sport = excluded.sport,
            league_id = excluded.league_id,
            status = excluded.status,
            progress = excluded.progress,
            home_score = excluded.home_score,
            away_score = excluded.away_score,
            raw_hash = excluded.raw_hash,
            updated_at = excluded.updated_at',
        [
            ':event_id' => $match['event_id'],
            ':sport' => $match['sport'] ?? 'Soccer',
            ':league_id' => $match['league_id'],
            ':status' => $match['status'],
            ':progress' => $match['progress'],
            ':home_score' => $match['home_score'],
            ':away_score' => $match['away_score'],
            ':raw_hash' => $match['raw_hash'],
            ':first_seen_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_was_alert_sent(SQLite3 $db, string $alertKey): bool
{
    return fb_fetch_one($db, 'SELECT alert_key FROM sent_alerts WHERE alert_key = :alert_key', [
        ':alert_key' => $alertKey,
    ]) !== null;
}

function fb_mark_alert_sent(SQLite3 $db, array $alert): void
{
    fb_execute(
        $db,
        'INSERT OR IGNORE INTO sent_alerts (alert_key, event_id, sport, alert_type, meta_json, created_at)
         VALUES (:alert_key, :event_id, :sport, :alert_type, :meta_json, :created_at)',
        [
            ':alert_key' => $alert['key'],
            ':event_id' => $alert['match']['event_id'],
            ':sport' => $alert['match']['sport'] ?? ($alert['meta']['sport'] ?? ''),
            ':alert_type' => $alert['type'],
            ':meta_json' => json_encode($alert['meta'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => fb_now(),
        ]
    );
}

function fb_mark_simple_alert_sent(SQLite3 $db, string $alertKey, string $alertType, array $meta = []): void
{
    fb_execute(
        $db,
        'INSERT OR IGNORE INTO sent_alerts (alert_key, event_id, sport, alert_type, meta_json, created_at)
         VALUES (:alert_key, :event_id, :sport, :alert_type, :meta_json, :created_at)',
        [
            ':alert_key' => $alertKey,
            ':event_id' => $alertKey,
            ':sport' => (string) ($meta['sport'] ?? ''),
            ':alert_type' => $alertType,
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => fb_now(),
        ]
    );
}

function fb_is_live_status(?string $status): bool
{
    $status = strtoupper(trim((string) $status));

    return in_array($status, ['LIVE', '1H', '2H', 'ET', 'P', 'PEN', 'AET', 'IN PLAY', 'Q1', 'Q2', 'Q3', 'Q4', '1Q', '2Q', '3Q', '4Q', 'OT', 'SO'], true)
        || preg_match('/^\d+/', $status) === 1;
}

function fb_is_half_time_status(?string $status): bool
{
    return in_array(strtoupper(trim((string) $status)), ['HT', 'HALFTIME', 'HALF TIME'], true);
}

function fb_is_full_time_status(?string $status): bool
{
    return in_array(strtoupper(trim((string) $status)), ['FT', 'FULLTIME', 'FULL TIME', 'FINAL', 'FINAL/OT', 'AET', 'PEN'], true);
}

function fb_fetch_event_timeline(array $config, SQLite3 $db, string $eventId): array
{
    if ($eventId === '') {
        return [];
    }

    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/lookup/event_timeline/' . rawurlencode($eventId),
        (int) $config['thesportsdb']['timeline_cache_ttl']
    );

    $timeline = fb_extract_list($payload, ['lookup', 'timeline', 'events']);

    usort($timeline, static function (array $a, array $b): int {
        return (int) ($a['intTime'] ?? 0) <=> (int) ($b['intTime'] ?? 0);
    });

    return $timeline;
}

function fb_fetch_league_artwork(array $config, SQLite3 $db, string $leagueId): array
{
    if ($leagueId === '') {
        return [];
    }

    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/lookup/league/' . rawurlencode($leagueId),
        (int) $config['thesportsdb']['lookup_cache_ttl']
    );

    $rows = fb_extract_list($payload, ['lookup', 'leagues']);
    $league = $rows[0] ?? [];

    return is_array($league) ? [
        'badge' => (string) ($league['strBadge'] ?? ''),
        'logo' => (string) ($league['strLogo'] ?? ''),
    ] : [];
}

function fb_fetch_player_artwork(array $config, SQLite3 $db, ?string $playerId): array
{
    if (!$playerId) {
        return [];
    }

    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/lookup/player/' . rawurlencode($playerId),
        (int) $config['thesportsdb']['lookup_cache_ttl']
    );

    $rows = fb_extract_list($payload, ['lookup', 'players']);
    $player = $rows[0] ?? [];

    return is_array($player) ? [
        'thumb' => (string) ($player['strThumb'] ?? $player['strCutout'] ?? ''),
        'render' => (string) ($player['strRender'] ?? ''),
    ] : [];
}

function fb_fetch_team_artwork(array $config, SQLite3 $db, ?string $teamId): array
{
    if (!$teamId) {
        return [];
    }

    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/lookup/team/' . rawurlencode($teamId),
        (int) $config['thesportsdb']['lookup_cache_ttl']
    );

    $rows = fb_extract_list($payload, ['lookup', 'teams']);
    $team = $rows[0] ?? [];

    return is_array($team) ? [
        'badge' => (string) ($team['strBadge'] ?? ''),
        'logo' => (string) ($team['strLogo'] ?? ''),
        'team' => (string) ($team['strTeam'] ?? ''),
    ] : [];
}

function fb_fetch_all_sports(array $config, SQLite3 $db): array
{
    $payload = fb_thesportsdb_get($config, $db, '/all/sports', (int) $config['thesportsdb']['lookup_cache_ttl']);

    return fb_extract_list($payload, ['sports', 'all']);
}

function fb_fetch_all_leagues(array $config, SQLite3 $db): array
{
    $payload = fb_thesportsdb_get($config, $db, '/all/leagues', (int) $config['thesportsdb']['lookup_cache_ttl']);

    return fb_extract_list($payload, ['leagues', 'all']);
}

function fb_coverage_country_enabled(array $config, string $country): bool
{
    $countryKey = fb_tv_channel_slug($country);
    $enabled = array_map('fb_tv_channel_slug', $config['coverage']['countries'] ?? []);

    if ($countryKey === '') {
        return true;
    }

    return in_array($countryKey, $enabled, true);
}

function fb_save_coverage_sport(SQLite3 $db, string $sportName, bool $enabled, bool $liveAvailable = false): void
{
    $sportName = fb_canonical_sport($sportName, $sportName);
    $sportKey = fb_sport_key($sportName);

    if ($sportKey === '') {
        return;
    }

    fb_execute(
        $db,
        'INSERT INTO coverage_sports (sport_key, sport_name, enabled, live_available, last_seen_at, updated_at)
         VALUES (:sport_key, :sport_name, :enabled, :live_available, :last_seen_at, :updated_at)
         ON CONFLICT(sport_key) DO UPDATE SET
            sport_name = excluded.sport_name,
            enabled = excluded.enabled,
            live_available = CASE WHEN excluded.live_available = 1 THEN 1 ELSE coverage_sports.live_available END,
            last_seen_at = excluded.last_seen_at,
            updated_at = excluded.updated_at',
        [
            ':sport_key' => $sportKey,
            ':sport_name' => $sportName,
            ':enabled' => $enabled ? 1 : 0,
            ':live_available' => $liveAvailable ? 1 : 0,
            ':last_seen_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_save_coverage_league(SQLite3 $db, array $league): void
{
    $leagueId = trim((string) ($league['league_id'] ?? $league['idLeague'] ?? ''));
    $leagueName = trim((string) ($league['league_name'] ?? $league['strLeague'] ?? ''));

    if ($leagueId === '' || $leagueName === '') {
        return;
    }

    fb_execute(
        $db,
        'INSERT INTO coverage_leagues (
            league_id, league_name, sport, country, badge, logo, enabled, live_available, last_seen_at, updated_at
        ) VALUES (
            :league_id, :league_name, :sport, :country, :badge, :logo, :enabled, :live_available, :last_seen_at, :updated_at
        ) ON CONFLICT(league_id) DO UPDATE SET
            league_name = excluded.league_name,
            sport = excluded.sport,
            country = excluded.country,
            badge = CASE WHEN excluded.badge != "" THEN excluded.badge ELSE coverage_leagues.badge END,
            logo = CASE WHEN excluded.logo != "" THEN excluded.logo ELSE coverage_leagues.logo END,
            enabled = excluded.enabled,
            live_available = CASE WHEN excluded.live_available = 1 THEN 1 ELSE coverage_leagues.live_available END,
            last_seen_at = excluded.last_seen_at,
            updated_at = excluded.updated_at',
        [
            ':league_id' => $leagueId,
            ':league_name' => $leagueName,
            ':sport' => fb_canonical_sport((string) ($league['sport'] ?? $league['strSport'] ?? 'Soccer')),
            ':country' => (string) ($league['country'] ?? $league['strCountry'] ?? ''),
            ':badge' => (string) ($league['badge'] ?? $league['strBadge'] ?? ''),
            ':logo' => (string) ($league['logo'] ?? $league['strLogo'] ?? ''),
            ':enabled' => !empty($league['enabled']) ? 1 : 0,
            ':live_available' => !empty($league['live_available']) ? 1 : 0,
            ':last_seen_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_discover_coverage(array $config, SQLite3 $db): array
{
    $errors = [];
    $sportsSeen = 0;
    $leaguesSeen = 0;
    $enabledLeagues = 0;
    $explicitLeagueIds = fb_coverage_league_filter_ids($config);
    $legacySoccerIds = fb_coverage_legacy_soccer_ids($config);

    foreach ($config['coverage']['enabled_sports'] ?? [] as $sport) {
        fb_save_coverage_sport($db, (string) $sport, true, in_array(fb_sport_key((string) $sport), ['soccer', 'american football', 'basketball', 'baseball', 'ice hockey'], true));
    }

    try {
        foreach (fb_fetch_all_sports($config, $db) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sport = (string) ($row['strSport'] ?? $row['sport'] ?? '');
            if ($sport === '') {
                continue;
            }

            fb_save_coverage_sport($db, $sport, fb_sport_enabled($config, $sport));
            $sportsSeen++;
        }
    } catch (Throwable $error) {
        $errors[] = '/all/sports: ' . $error->getMessage();
    }

    try {
        foreach (fb_fetch_all_leagues($config, $db) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $leagueId = (string) ($row['idLeague'] ?? '');
            $sport = fb_canonical_sport((string) ($row['strSport'] ?? ''));
            $country = (string) ($row['strCountry'] ?? '');
            $enabled = false;

            if ($explicitLeagueIds !== []) {
                $enabled = in_array($leagueId, $explicitLeagueIds, true)
                    || ($sport === 'Soccer' && in_array($leagueId, $legacySoccerIds, true));
            } else {
                $enabled = (bool) ($config['coverage']['auto_enable_discovered_leagues'] ?? true)
                    && fb_sport_enabled($config, $sport)
                    && (fb_coverage_country_enabled($config, $country) || ($sport === 'Soccer' && in_array($leagueId, $legacySoccerIds, true)));
            }

            fb_save_coverage_league($db, [
                'league_id' => $leagueId,
                'league_name' => (string) ($row['strLeague'] ?? ''),
                'sport' => $sport,
                'country' => $country,
                'badge' => (string) ($row['strBadge'] ?? ''),
                'logo' => (string) ($row['strLogo'] ?? ''),
                'enabled' => $enabled,
            ]);

            $leaguesSeen++;
            if ($enabled) {
                $enabledLeagues++;
            }
        }
    } catch (Throwable $error) {
        $errors[] = '/all/leagues: ' . $error->getMessage();
    }

    return [
        'sports' => $sportsSeen,
        'leagues' => $leaguesSeen,
        'enabled_leagues' => $enabledLeagues,
        'errors' => $errors,
    ];
}

function fb_mark_live_coverage_seen(SQLite3 $db, array $event): void
{
    $sport = fb_match_sport($event);
    $leagueId = trim((string) ($event['_allowedLeagueId'] ?? $event['idLeague'] ?? ''));
    $leagueName = trim((string) ($event['_allowedLeagueName'] ?? $event['strLeague'] ?? ''));

    fb_save_coverage_sport($db, $sport, true, true);

    if ($leagueId === '' || $leagueName === '') {
        return;
    }

    fb_execute(
        $db,
        'INSERT INTO coverage_leagues (
            league_id, league_name, sport, country, badge, logo, enabled, live_available, last_seen_at, updated_at
        ) VALUES (
            :league_id, :league_name, :sport, :country, "", "", 0, 1, :last_seen_at, :updated_at
        ) ON CONFLICT(league_id) DO UPDATE SET
            league_name = excluded.league_name,
            sport = excluded.sport,
            live_available = 1,
            last_seen_at = excluded.last_seen_at,
            updated_at = excluded.updated_at',
        [
            ':league_id' => $leagueId,
            ':league_name' => $leagueName,
            ':sport' => $sport,
            ':country' => (string) ($event['strCountry'] ?? ''),
            ':last_seen_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_list_coverage_sports(SQLite3 $db): array
{
    $rows = [];
    $result = $db->query(
        'SELECT sport_key, sport_name, enabled, live_available, updated_at
         FROM coverage_sports
         ORDER BY enabled DESC, sport_name COLLATE NOCASE'
    );

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['enabled'] = (int) ($row['enabled'] ?? 0);
            $row['live_available'] = (int) ($row['live_available'] ?? 0);
            $rows[] = $row;
        }
    }

    return $rows;
}

function fb_list_coverage_leagues(SQLite3 $db, int $limit = 300): array
{
    $rows = [];
    $stmt = $db->prepare(
        'SELECT league_id, league_name, sport, country, badge, logo, enabled, live_available, updated_at
         FROM coverage_leagues
         ORDER BY enabled DESC, sport COLLATE NOCASE, country COLLATE NOCASE, league_name COLLATE NOCASE
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['enabled'] = (int) ($row['enabled'] ?? 0);
            $row['live_available'] = (int) ($row['live_available'] ?? 0);
            $rows[] = $row;
        }
    }

    return $rows;
}

function fb_coverage_schedule_leagues(array $config, SQLite3 $db): array
{
    $rows = [];
    $seen = [];

    foreach ($config['leagues']['allowed'] ?? [] as $leagueId => $league) {
        $rows[] = [
            'league_id' => (string) $leagueId,
            'league_name' => (string) ($league['name'] ?? 'Soccer'),
            'sport' => 'Soccer',
        ];
        $seen[(string) $leagueId] = true;
    }

    $explicitIds = fb_coverage_league_filter_ids($config);

    if ($explicitIds !== []) {
        foreach ($explicitIds as $leagueId) {
            if (isset($seen[$leagueId])) {
                continue;
            }

            $registry = fb_fetch_one($db, 'SELECT league_id, league_name, sport FROM coverage_leagues WHERE league_id = :league_id', [
                ':league_id' => $leagueId,
            ]);
            $rows[] = [
                'league_id' => $leagueId,
                'league_name' => (string) ($registry['league_name'] ?? ('League ' . $leagueId)),
                'sport' => (string) ($registry['sport'] ?? 'Soccer'),
            ];
            $seen[$leagueId] = true;
        }
    } else {
        $limit = max(1, (int) ($config['coverage']['max_schedule_leagues'] ?? 80));
        $stmt = $db->prepare(
            'SELECT league_id, league_name, sport
             FROM coverage_leagues
             WHERE enabled = 1
             ORDER BY sport COLLATE NOCASE, country COLLATE NOCASE, league_name COLLATE NOCASE
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $leagueId = (string) $row['league_id'];

                if (isset($seen[$leagueId])) {
                    continue;
                }

                $rows[] = $row;
                $seen[$leagueId] = true;
            }
        }
    }

    return array_values($rows);
}

function fb_enrich_match_team_badges(array $config, SQLite3 $db, array $match): array
{
    if (($match['home_badge'] ?? '') === '' && ($match['home_team_id'] ?? '') !== '') {
        $artwork = fb_fetch_team_artwork($config, $db, (string) $match['home_team_id']);
        $match['home_badge'] = $artwork['badge'] ?: ($artwork['logo'] ?? '');
    }

    if (($match['away_badge'] ?? '') === '' && ($match['away_team_id'] ?? '') !== '') {
        $artwork = fb_fetch_team_artwork($config, $db, (string) $match['away_team_id']);
        $match['away_badge'] = $artwork['badge'] ?: ($artwork['logo'] ?? '');
    }

    return $match;
}

function fb_fetch_previous_league_events(array $config, SQLite3 $db, string $leagueId): array
{
    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/schedule/previous/league/' . rawurlencode($leagueId),
        300
    );

    return fb_extract_list($payload, ['schedule', 'events', 'previous']);
}

function fb_event_sort_timestamp(array $event): int
{
    $timestamp = (string) ($event['strTimestamp'] ?? '');

    if ($timestamp !== '') {
        $time = strtotime($timestamp);

        if ($time !== false) {
            return $time;
        }
    }

    $date = (string) ($event['dateEvent'] ?? '');
    $time = (string) ($event['strTime'] ?? $event['strEventTime'] ?? '00:00:00');
    $parsed = strtotime(trim($date . ' ' . $time));

    return $parsed === false ? 0 : $parsed;
}

function fb_fetch_last_allowed_english_match(array $config, SQLite3 $db): ?array
{
    $candidates = [];

    foreach (array_keys($config['leagues']['allowed']) as $leagueId) {
        try {
            foreach (fb_fetch_previous_league_events($config, $db, (string) $leagueId) as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $league = fb_allowed_league($config, $event);

                if ($league === null) {
                    $event['idLeague'] = (string) $leagueId;
                    $event['_allowedLeagueId'] = (string) $leagueId;
                    $event['_allowedLeagueName'] = $config['leagues']['allowed'][(string) $leagueId]['name'];
                } else {
                    $event['_allowedLeagueId'] = $league['id'];
                    $event['_allowedLeagueName'] = $league['name'];
                }

                $candidates[] = $event;
            }
        } catch (Throwable $error) {
            fb_log('warning', 'Could not fetch previous league events', [
                'league_id' => $leagueId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    if ($candidates === []) {
        return null;
    }

    usort($candidates, static fn (array $a, array $b): int => fb_event_sort_timestamp($b) <=> fb_event_sort_timestamp($a));

    return fb_normalize_match($candidates[0]);
}

function fb_generate_last_english_match_test_image(array $config): array
{
    fb_ensure_directories($config);
    fb_require_env($config, false);
    $db = fb_open_db($config);
    $match = fb_fetch_last_allowed_english_match($config, $db);

    if ($match === null) {
        throw new RuntimeException('No previous English match was returned by TheSportsDB.');
    }

    $timeline = [];

    try {
        $timeline = fb_fetch_event_timeline($config, $db, $match['event_id']);
    } catch (Throwable $error) {
        fb_log('warning', 'Could not fetch last match timeline', [
            'event_id' => $match['event_id'],
            'error' => $error->getMessage(),
        ]);
    }

    $match = fb_enrich_match_team_badges($config, $db, $match);
    $leagueArtwork = fb_fetch_league_artwork($config, $db, $match['league_id']);
    $match['league_logo'] = $leagueArtwork['logo'] ?: ($leagueArtwork['badge'] ?? '');
    $match['status'] = fb_is_full_time_status($match['status']) ? $match['status'] : 'FT';
    $match['progress'] = $match['progress'] ?? 90;

    $alert = fb_build_base_alert('FULL_TIME', 'test-last-english-match:' . $match['event_id'], $match, [
        'minute' => $match['progress'],
        'status' => 'Full-time',
        'scorers' => fb_goal_scorer_summary($match, $timeline),
    ]);

    $path = fb_generate_alert_image($config, $alert);
    $testPath = $config['paths']['generated'] . '/last_english_match_' . basename($path);
    rename($path, $testPath);

    return [
        'match' => $match,
        'image' => $testPath,
    ];
}

function fb_is_goal_timeline(array $timeline): bool
{
    $type = fb_normalize_text($timeline['strTimeline'] ?? '');
    $detail = fb_normalize_text($timeline['strTimelineDetail'] ?? '');

    return str_contains($type, 'goal') || str_contains($detail, 'goal');
}

function fb_is_red_card_timeline(array $timeline): bool
{
    $type = fb_normalize_text($timeline['strTimeline'] ?? '');
    $detail = fb_normalize_text($timeline['strTimelineDetail'] ?? '');

    return (str_contains($type, 'red') && str_contains($type, 'card'))
        || (str_contains($detail, 'red') && str_contains($detail, 'card'));
}

function fb_is_yellow_card_timeline(array $timeline): bool
{
    $type = fb_normalize_text($timeline['strTimeline'] ?? '');
    $detail = fb_normalize_text($timeline['strTimelineDetail'] ?? '');

    return (str_contains($type, 'yellow') && str_contains($type, 'card'))
        || (str_contains($detail, 'yellow') && str_contains($detail, 'card'));
}

function fb_is_substitution_timeline(array $timeline): bool
{
    $type = fb_normalize_text($timeline['strTimeline'] ?? '');
    $detail = fb_normalize_text($timeline['strTimelineDetail'] ?? '');

    return str_contains($type, 'subst') || str_contains($detail, 'subst');
}

function fb_timeline_alert_key(string $eventId, array $item, string $fallbackType): string
{
    return strtolower($fallbackType) . ':' . $eventId . ':' . sha1(json_encode(fb_timeline_signature($item, $fallbackType), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function fb_timeline_alert_type(string $type): string
{
    return match (strtolower($type)) {
        'redcard', 'red_card' => 'RED_CARD',
        'yellowcard', 'yellow_card' => 'YELLOW_CARD',
        'substitution', 'subst' => 'SUBSTITUTION',
        default => strtoupper($type),
    };
}

function fb_timeline_signature(array $item, string $type): array
{
    return [
        'type' => strtolower($type),
        'minute' => (string) ($item['intTime'] ?? ''),
        'team' => fb_normalize_text($item['strTeam'] ?? $item['idTeam'] ?? $item['strHome'] ?? ''),
        'player' => fb_normalize_text($item['strPlayer'] ?? ''),
        'assist' => fb_normalize_text($item['strAssist'] ?? ''),
        'detail' => fb_normalize_text($item['strTimelineDetail'] ?? $item['strTimeline'] ?? ''),
    ];
}

function fb_sent_alert_signature(array $meta, string $type): array
{
    return [
        'type' => strtolower($type),
        'minute' => (string) ($meta['minute'] ?? ''),
        'team' => fb_normalize_text($meta['team'] ?? ''),
        'player' => fb_normalize_text($meta['player'] ?? $meta['scorer'] ?? $meta['player_on'] ?? ''),
        'assist' => fb_normalize_text($meta['assist'] ?? $meta['player_off'] ?? ''),
        'detail' => fb_normalize_text($meta['detail'] ?? ''),
    ];
}

function fb_timeline_signature_matches(array $candidate, array $sent): bool
{
    if ($candidate['type'] !== $sent['type'] || $candidate['minute'] !== $sent['minute'] || $candidate['player'] !== $sent['player']) {
        return false;
    }

    if ($candidate['team'] !== '' && $sent['team'] !== '' && $candidate['team'] !== $sent['team']) {
        return false;
    }

    if ($candidate['assist'] !== '' && $sent['assist'] !== '' && $candidate['assist'] !== $sent['assist']) {
        return false;
    }

    return true;
}

function fb_was_timeline_signature_sent(SQLite3 $db, string $eventId, array $item, string $type): bool
{
    $alertType = fb_timeline_alert_type($type);
    $candidate = fb_timeline_signature($item, $type);
    $stmt = $db->prepare(
        'SELECT meta_json
         FROM sent_alerts
         WHERE event_id = :event_id AND alert_type = :alert_type'
    );
    $stmt->bindValue(':event_id', $eventId, SQLITE3_TEXT);
    $stmt->bindValue(':alert_type', $alertType, SQLITE3_TEXT);
    $result = $stmt->execute();

    if (!$result) {
        return false;
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);

        if (!is_array($meta)) {
            continue;
        }

        if (fb_timeline_signature_matches($candidate, fb_sent_alert_signature($meta, $type))) {
            return true;
        }
    }

    return false;
}

function fb_latest_unsent_timeline_events(SQLite3 $db, string $eventId, array $timeline, callable $predicate, string $type): array
{
    $events = [];

    foreach ($timeline as $item) {
        if (!is_array($item) || !$predicate($item)) {
            continue;
        }

        $key = fb_timeline_alert_key($eventId, $item, $type);

        if (!fb_was_alert_sent($db, $key) && !fb_was_timeline_signature_sent($db, $eventId, $item, $type)) {
            $events[] = [$key, $item];
        }
    }

    return $events;
}

function fb_suppress_first_seen_timeline_backlog(array $config, SQLite3 $db, array $match, ?array $previous, array $timeline): void
{
    if ($previous !== null || $timeline === []) {
        return;
    }

    $eventId = (string) ($match['event_id'] ?? '');

    if ($eventId === '') {
        return;
    }

    $rules = [
        ['flag' => 'allow_first_seen_goal_alerts', 'predicate' => 'fb_is_goal_timeline', 'fallback' => 'goal', 'alert_type' => 'GOAL'],
        ['flag' => 'allow_first_seen_red_card_alerts', 'predicate' => 'fb_is_red_card_timeline', 'fallback' => 'redcard', 'alert_type' => 'RED_CARD'],
        ['flag' => 'allow_first_seen_yellow_card_alerts', 'predicate' => 'fb_is_yellow_card_timeline', 'fallback' => 'yellowcard', 'alert_type' => 'YELLOW_CARD'],
        ['flag' => 'allow_first_seen_substitution_alerts', 'predicate' => 'fb_is_substitution_timeline', 'fallback' => 'substitution', 'alert_type' => 'SUBSTITUTION'],
    ];

    foreach ($rules as $rule) {
        if (!empty($config['alerts'][$rule['flag']])) {
            continue;
        }

        foreach ($timeline as $item) {
            if (!is_array($item) || !is_callable($rule['predicate']) || !$rule['predicate']($item)) {
                continue;
            }

            $key = fb_timeline_alert_key($eventId, $item, (string) $rule['fallback']);

            if (fb_was_alert_sent($db, $key) || fb_was_timeline_signature_sent($db, $eventId, $item, (string) $rule['fallback'])) {
                continue;
            }

            $alert = fb_build_base_alert((string) $rule['alert_type'], $key, $match, [
                'suppressed' => 'first_seen_timeline_backlog',
            ]);

            fb_mark_alert_sent($db, $alert);
            fb_record_alert_decision($db, 'skipped', 'First-seen timeline backlog suppressed.', $alert);
        }
    }
}

function fb_goal_team_side(array $match, array $timeline): ?string
{
    $teamId = (string) ($timeline['idTeam'] ?? '');

    if ($teamId !== '') {
        if ($teamId === $match['home_team_id']) {
            return 'home';
        }

        if ($teamId === $match['away_team_id']) {
            return 'away';
        }
    }

    $isHome = strtolower((string) ($timeline['strHome'] ?? ''));

    if ($isHome === 'yes') {
        return 'home';
    }

    if ($isHome === 'no') {
        return 'away';
    }

    return null;
}

function fb_build_base_alert(string $type, string $key, array $match, array $meta = []): array
{
    return [
        'type' => $type,
        'key' => $key,
        'match' => $match,
        'meta' => $meta,
    ];
}

function fb_detect_alerts(array $config, SQLite3 $db, array $match, ?array $previous, array $timeline): array
{
    $alerts = [];
    $eventId = $match['event_id'];
    $previousStatus = $previous['status'] ?? null;
    $previousHomeScore = $previous ? (int) $previous['home_score'] : null;
    $previousAwayScore = $previous ? (int) $previous['away_score'] : null;
    $currentTotal = $match['home_score'] + $match['away_score'];
    $previousTotal = $previous === null ? null : $previousHomeScore + $previousAwayScore;

    $kickoffWindow = (int) $config['alerts']['kickoff_progress_max'];
    $isEarlyFirstSeen = $previous === null
        && fb_is_live_status($match['status'])
        && (($match['progress'] ?? 0) <= $kickoffWindow)
        && $currentTotal === 0;

    $becameLive = $previous !== null
        && !fb_is_live_status($previousStatus)
        && !fb_is_half_time_status($previousStatus)
        && fb_is_live_status($match['status']);

    if (($isEarlyFirstSeen || $becameLive) && !fb_was_alert_sent($db, 'kickoff:' . $eventId)) {
        $alerts[] = fb_build_base_alert('KICK_OFF', 'kickoff:' . $eventId, $match, [
            'minute' => $match['progress'],
            'event_time' => $match['event_time'],
        ]);
    }

    $canSendGoal = $previous !== null || (bool) $config['alerts']['allow_first_seen_goal_alerts'];

    if ($canSendGoal && $previousTotal !== null && $currentTotal > $previousTotal) {
        $homeDelta = max(0, $match['home_score'] - $previousHomeScore);
        $awayDelta = max(0, $match['away_score'] - $previousAwayScore);
        $neededBySide = ['home' => $homeDelta, 'away' => $awayDelta];
        $timelineCandidates = ['home' => [], 'away' => []];

        foreach ($timeline as $timelineItem) {
            if (!is_array($timelineItem) || !fb_is_goal_timeline($timelineItem)) {
                continue;
            }

            $side = fb_goal_team_side($match, $timelineItem);

            if ($side === null || $neededBySide[$side] <= 0) {
                continue;
            }

            $key = fb_timeline_alert_key($eventId, $timelineItem, 'goal');

            if (fb_was_alert_sent($db, $key)) {
                continue;
            }

            $timelineCandidates[$side][] = [$key, $timelineItem];
        }

        foreach (['home', 'away'] as $side) {
            if ($neededBySide[$side] <= 0) {
                continue;
            }

            // If old timeline goals were never sent locally, take the newest goals matching the score delta.
            $selectedGoals = array_slice($timelineCandidates[$side], -$neededBySide[$side]);

            foreach ($selectedGoals as [$key, $timelineItem]) {
                $neededBySide[$side]--;
                $alerts[] = fb_build_base_alert('GOAL', $key, $match, [
                    'side' => $side,
                    'team' => $side === 'home' ? $match['home_team'] : $match['away_team'],
                    'scorer' => (string) ($timelineItem['strPlayer'] ?? 'Scorer unavailable'),
                    'assist' => (string) ($timelineItem['strAssist'] ?? ''),
                    'minute' => fb_int_or_null($timelineItem['intTime'] ?? null) ?? $match['progress'],
                    'player_id' => (string) ($timelineItem['idPlayer'] ?? ''),
                    'detail' => (string) ($timelineItem['strTimelineDetail'] ?? ''),
                ]);
            }
        }

        foreach ($neededBySide as $side => $remaining) {
            for ($i = 0; $i < $remaining; $i++) {
                $scoreKey = sprintf('goal:%s:%d-%d:%s:%d', $eventId, $match['home_score'], $match['away_score'], $side, $i);

                if (fb_was_alert_sent($db, $scoreKey)) {
                    continue;
                }

                $alerts[] = fb_build_base_alert('GOAL', $scoreKey, $match, [
                    'side' => $side,
                    'team' => $side === 'home' ? $match['home_team'] : $match['away_team'],
                    'scorer' => 'Scorer unavailable',
                    'assist' => '',
                    'minute' => $match['progress'],
                    'player_id' => '',
                    'detail' => 'Goal',
                ]);
            }
        }
    }

    if (fb_is_half_time_status($match['status']) && !fb_is_half_time_status($previousStatus)) {
        $key = 'halftime:' . $eventId;

        if (!fb_was_alert_sent($db, $key)) {
            $alerts[] = fb_build_base_alert('HALF_TIME', $key, $match, [
                'minute' => $match['progress'] ?? 45,
                'status' => 'Half-time',
            ]);
        }
    }

    $canSendFullTime = $previous !== null || (bool) $config['alerts']['allow_first_seen_full_time_alerts'];

    if ($canSendFullTime && fb_is_full_time_status($match['status']) && !fb_is_full_time_status($previousStatus)) {
        $key = 'fulltime:' . $eventId;

        if (!fb_was_alert_sent($db, $key)) {
            $alerts[] = fb_build_base_alert('FULL_TIME', $key, $match, [
                'minute' => $match['progress'] ?? 90,
                'status' => 'Full-time',
                'scorers' => fb_goal_scorer_summary($match, $timeline),
            ]);
        }
    }

    $canSendRedCards = $previous !== null || (bool) $config['alerts']['allow_first_seen_red_card_alerts'];

    if ((bool) $config['alerts']['send_red_cards'] && $canSendRedCards) {
        foreach (fb_latest_unsent_timeline_events($db, $eventId, $timeline, 'fb_is_red_card_timeline', 'redcard') as [$key, $card]) {
            $side = fb_goal_team_side($match, $card);
            $alerts[] = fb_build_base_alert('RED_CARD', $key, $match, [
                'side' => $side,
                'team' => $side === 'away' ? $match['away_team'] : $match['home_team'],
                'player' => (string) ($card['strPlayer'] ?? 'Player unavailable'),
                'minute' => fb_int_or_null($card['intTime'] ?? null) ?? $match['progress'],
                'player_id' => (string) ($card['idPlayer'] ?? ''),
                'detail' => (string) ($card['strTimelineDetail'] ?? 'Red card'),
            ]);
        }
    }

    $canSendYellowCards = $previous !== null || (bool) $config['alerts']['allow_first_seen_yellow_card_alerts'];

    if ((bool) $config['alerts']['send_yellow_cards'] && $canSendYellowCards) {
        foreach (fb_latest_unsent_timeline_events($db, $eventId, $timeline, 'fb_is_yellow_card_timeline', 'yellowcard') as [$key, $card]) {
            $side = fb_goal_team_side($match, $card);
            $alerts[] = fb_build_base_alert('YELLOW_CARD', $key, $match, [
                'side' => $side,
                'team' => $side === 'away' ? $match['away_team'] : $match['home_team'],
                'player' => (string) ($card['strPlayer'] ?? 'Player unavailable'),
                'minute' => fb_int_or_null($card['intTime'] ?? null) ?? $match['progress'],
                'player_id' => (string) ($card['idPlayer'] ?? ''),
                'detail' => (string) ($card['strTimelineDetail'] ?? 'Yellow card'),
            ]);
        }
    }

    $canSendSubstitutions = $previous !== null || (bool) $config['alerts']['allow_first_seen_substitution_alerts'];

    if ((bool) $config['alerts']['send_substitutions'] && $canSendSubstitutions) {
        foreach (fb_latest_unsent_timeline_events($db, $eventId, $timeline, 'fb_is_substitution_timeline', 'substitution') as [$key, $sub]) {
            $side = fb_goal_team_side($match, $sub);
            $alerts[] = fb_build_base_alert('SUBSTITUTION', $key, $match, [
                'side' => $side,
                'team' => $side === 'home' ? $match['home_team'] : $match['away_team'],
                'player_on' => (string) ($sub['strPlayer'] ?? 'Player on'),
                'player_off' => (string) ($sub['strAssist'] ?? ''),
                'minute' => fb_int_or_null($sub['intTime'] ?? null) ?? $match['progress'],
                'player_id' => (string) ($sub['idPlayer'] ?? ''),
                'detail' => (string) ($sub['strTimelineDetail'] ?? 'Substitution'),
            ]);
        }
    }

    return $alerts;
}

function fb_detect_generic_alerts(array $config, SQLite3 $db, array $match, ?array $previous): array
{
    $alerts = [];
    $eventId = $match['event_id'];
    $profile = fb_sport_profile($config, (string) ($match['sport'] ?? ''));
    $sportMeta = [
        'sport' => $match['sport'] ?? '',
        'sport_label' => (string) ($profile['label'] ?? ($match['sport'] ?? 'Sport')),
        'score_label' => (string) ($profile['score_label'] ?? 'Score'),
        'start_label' => (string) ($profile['start_label'] ?? 'Started'),
        'update_label' => (string) ($profile['update_label'] ?? 'Score update'),
        'period_label' => (string) ($profile['period_label'] ?? 'Status'),
        'final_label' => (string) ($profile['final_label'] ?? 'Final'),
        'fallback_detail' => (string) ($profile['fallback_detail'] ?? 'Live update from TheSportsDB'),
    ];
    $previousStatus = $previous['status'] ?? null;
    $previousHomeScore = $previous ? (int) $previous['home_score'] : null;
    $previousAwayScore = $previous ? (int) $previous['away_score'] : null;
    $currentTotal = $match['home_score'] + $match['away_score'];
    $previousTotal = $previous === null ? null : $previousHomeScore + $previousAwayScore;

    $becameLive = $previous !== null
        && !fb_is_live_status($previousStatus)
        && !fb_is_half_time_status($previousStatus)
        && fb_is_live_status($match['status']);
    $earlyFirstSeen = $previous === null
        && fb_is_live_status($match['status'])
        && $currentTotal === 0;

    if (!empty($config['alerts']['send_match_starts']) && ($becameLive || $earlyFirstSeen) && !fb_was_alert_sent($db, 'match_start:' . $eventId)) {
        $alerts[] = fb_build_base_alert('MATCH_START', 'match_start:' . $eventId, $match, $sportMeta + [
            'minute' => $match['progress'],
            'event_time' => $match['event_time'],
        ]);
    }

    if (!empty($config['alerts']['send_score_updates']) && $previous !== null && $previousTotal !== null && $currentTotal !== $previousTotal) {
        $scoreKey = sprintf('score:%s:%d-%d', $eventId, $match['home_score'], $match['away_score']);

        if (!fb_was_alert_sent($db, $scoreKey)) {
            $alerts[] = fb_build_base_alert('SCORE_UPDATE', $scoreKey, $match, $sportMeta + [
                'minute' => $match['progress'],
                'previous_score' => sprintf('%d-%d', $previousHomeScore, $previousAwayScore),
            ]);
        }
    }

    if (!empty($config['alerts']['send_period_changes']) && $previous !== null && trim((string) $previousStatus) !== trim((string) $match['status'])) {
        if (!fb_is_full_time_status($match['status']) && !fb_is_half_time_status($match['status'])) {
            $periodKey = 'period:' . $eventId . ':' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $match['status']);

            if (!fb_was_alert_sent($db, $periodKey)) {
                $alerts[] = fb_build_base_alert('PERIOD_CHANGE', $periodKey, $match, $sportMeta + [
                    'minute' => $match['progress'],
                    'status' => $match['status'],
                    'status_label' => fb_sport_status_label($config, (string) ($match['sport'] ?? ''), (string) $match['status']),
                    'previous_status' => $previousStatus,
                ]);
            }
        }
    }

    $canSendFullTime = $previous !== null || (bool) $config['alerts']['allow_first_seen_full_time_alerts'];

    if ($canSendFullTime && fb_is_full_time_status($match['status']) && !fb_is_full_time_status($previousStatus)) {
        $key = 'fulltime:' . $eventId;

        if (!fb_was_alert_sent($db, $key)) {
            $alerts[] = fb_build_base_alert('FULL_TIME', $key, $match, $sportMeta + [
                'minute' => $match['progress'],
                'status' => (string) ($profile['final_label'] ?? 'Final'),
            ]);
        }
    }

    return $alerts;
}

function fb_goal_scorer_summary(array $match, array $timeline): array
{
    $home = [];
    $away = [];

    foreach ($timeline as $item) {
        if (!is_array($item) || !fb_is_goal_timeline($item)) {
            continue;
        }

        $name = trim((string) ($item['strPlayer'] ?? ''));

        if ($name === '') {
            continue;
        }

        $minute = trim((string) ($item['intTime'] ?? ''));
        $entry = $minute !== '' ? sprintf("%s %s'", $name, $minute) : $name;
        $side = fb_goal_team_side($match, $item);

        if ($side === 'home') {
            $home[] = $entry;
        } elseif ($side === 'away') {
            $away[] = $entry;
        }
    }

    return [
        'home' => $home,
        'away' => $away,
    ];
}

function fb_download_asset(array $config, string $url): ?string
{
    $url = trim($url);

    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    fb_require_extensions(['curl', 'gd']);

    $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $extension = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) ? $extension : 'img';
    $path = $config['paths']['image_cache'] . '/' . sha1($url) . '.' . $extension;

    if (is_file($path) && filesize($path) > 0) {
        return $path;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'football-alert-bot/1.0',
    ]);

    $body = curl_exec($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false || $statusCode < 200 || $statusCode >= 300) {
        fb_log('warning', 'Could not download image asset', [
            'url' => $url,
            'status' => $statusCode,
            'error' => $error,
        ]);
        return null;
    }

    $probe = @imagecreatefromstring((string) $body);

    if (!$probe instanceof GdImage) {
        fb_log('warning', 'Downloaded asset is not a supported image', ['url' => $url]);
        return null;
    }

    imagedestroy($probe);
    file_put_contents($path, $body, LOCK_EX);

    return $path;
}

function fb_cleanup_generated_images(array $config): void
{
    $dir = $config['paths']['generated'];
    $maxAge = (int) $config['images']['cleanup_after_seconds'];

    if ($maxAge <= 0 || !is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.png') ?: [] as $file) {
        if ((bool) $config['images']['preserve_sample_images'] && str_starts_with(basename($file), 'sample_')) {
            continue;
        }

        if (is_file($file) && filemtime($file) !== false && filemtime($file) < time() - $maxAge) {
            unlink($file);
        }
    }
}

function fb_acquire_run_lock(array $config)
{
    $handle = fopen($config['paths']['run_lock'], 'c+');

    if (!$handle) {
        throw new RuntimeException('Unable to open run lock');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);

    return $handle;
}

function fb_tv_channel_slug(string $channel): string
{
    $channel = strtolower(trim($channel));
    $channel = str_replace('&', ' and ', $channel);
    $channel = preg_replace('/[^a-z0-9]+/', '_', $channel) ?? $channel;
    $channel = preg_replace('/_+/', '_', $channel) ?? $channel;

    return trim($channel, '_');
}

function fb_tv_channel_label(string $channel): string
{
    $channel = trim($channel);

    if ($channel === '') {
        return '';
    }

    if (preg_match('/[A-Z ]/', $channel) === 1 && !str_contains($channel, '_')) {
        return preg_replace('/\s+/', ' ', $channel) ?? $channel;
    }

    return ucwords(str_replace('_', ' ', fb_tv_channel_slug($channel)));
}

function fb_tv_channels(array $config): array
{
    if (empty($config['tv']['enabled'])) {
        return [];
    }

    $channels = [];

    foreach (($config['tv']['channels'] ?? []) as $channel) {
        $slug = fb_tv_channel_slug((string) $channel);

        if ($slug === '') {
            continue;
        }

        $channels[$slug] = [
            'slug' => $slug,
            'label' => fb_tv_channel_label((string) $channel),
        ];
    }

    return array_values($channels);
}

function fb_tv_configured_channel_slugs(array $config): array
{
    return array_values(array_filter(array_map(
        static fn (string $channel): string => fb_tv_channel_slug($channel),
        $config['tv']['channels'] ?? []
    )));
}

function fb_tv_channel_from_event(array $event): ?array
{
    $name = trim((string) ($event['strChannel'] ?? ''));
    $id = trim((string) ($event['idChannel'] ?? ''));
    $slug = fb_tv_channel_slug($name);

    if ($slug === '' && $id === '') {
        return null;
    }

    if ($slug === '') {
        $slug = 'channel_' . $id;
    }

    return [
        'slug' => $slug,
        'id' => $id,
        'name' => $name !== '' ? $name : fb_tv_channel_label($slug),
        'country' => (string) ($event['strCountry'] ?? ''),
        'logo' => (string) ($event['strLogo'] ?? ''),
        'sport' => (string) ($event['strSport'] ?? ''),
    ];
}

function fb_save_tv_channel(SQLite3 $db, array $channel): void
{
    $existing = fb_fetch_one($db, 'SELECT sports_json FROM tv_channels WHERE channel_slug = :slug', [
        ':slug' => $channel['slug'],
    ]);
    $sports = [];

    if ($existing && !empty($existing['sports_json'])) {
        $decoded = json_decode((string) $existing['sports_json'], true);
        $sports = is_array($decoded) ? $decoded : [];
    }

    if (!empty($channel['sport'])) {
        $sports[] = (string) $channel['sport'];
    }

    $sports = array_values(array_unique(array_filter($sports)));

    fb_execute(
        $db,
        'INSERT INTO tv_channels (
            channel_slug, channel_id, channel_name, country, logo, sports_json, first_seen_at, updated_at
        ) VALUES (
            :slug, :id, :name, :country, :logo, :sports_json, :first_seen_at, :updated_at
        ) ON CONFLICT(channel_slug) DO UPDATE SET
            channel_id = CASE WHEN excluded.channel_id != "" THEN excluded.channel_id ELSE tv_channels.channel_id END,
            channel_name = excluded.channel_name,
            country = CASE WHEN excluded.country != "" THEN excluded.country ELSE tv_channels.country END,
            logo = CASE WHEN excluded.logo != "" THEN excluded.logo ELSE tv_channels.logo END,
            sports_json = excluded.sports_json,
            updated_at = excluded.updated_at',
        [
            ':slug' => $channel['slug'],
            ':id' => $channel['id'] ?? '',
            ':name' => $channel['name'],
            ':country' => $channel['country'] ?? '',
            ':logo' => $channel['logo'] ?? '',
            ':sports_json' => json_encode($sports, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':first_seen_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );
}

function fb_list_tv_channel_registry(SQLite3 $db): array
{
    $result = $db->query(
        'SELECT channel_slug, channel_id, channel_name, country, logo, sports_json, updated_at
         FROM tv_channels
         ORDER BY channel_name COLLATE NOCASE'
    );
    $channels = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sports = json_decode((string) ($row['sports_json'] ?? '[]'), true);
            $row['sports'] = is_array($sports) ? $sports : [];
            $channels[] = $row;
        }
    }

    return $channels;
}

function fb_tv_event_datetime(array $event, DateTimeZone $tz): ?DateTimeImmutable
{
    $timestamp = trim((string) ($event['strTimestamp'] ?? $event['strEventTimestamp'] ?? ''));

    if ($timestamp !== '') {
        try {
            return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->setTimezone($tz);
        } catch (Throwable) {
            // Fall through to date/time parsing below.
        }
    }

    $dateStr = trim((string) ($event['dateEvent'] ?? $event['dateTV'] ?? ''));
    $timeStr = trim((string) ($event['strTime'] ?? $event['strEventTime'] ?? '00:00:00'));

    if ($dateStr === '') {
        return null;
    }

    try {
        return new DateTimeImmutable(trim($dateStr . ' ' . $timeStr), $tz);
    } catch (Throwable) {
        return null;
    }
}

function fb_normalize_tv_event(array $event, array $channel, array $config): array
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $eventTime = fb_tv_event_datetime($event, $tz);
    $channelName = trim((string) ($event['strChannel'] ?? '')) ?: $channel['label'];
    $channelSlug = fb_tv_channel_slug($channelName ?: $channel['slug']);
    $timeLabel = $eventTime instanceof DateTimeImmutable
        ? $eventTime->format('D H:i')
        : trim((string) (($event['dateEvent'] ?? '') . ' ' . ($event['strTime'] ?? '')));

    $homeTeam = trim((string) ($event['strHomeTeam'] ?? ''));
    $awayTeam = trim((string) ($event['strAwayTeam'] ?? ''));
    $eventName = trim((string) ($event['strEvent'] ?? $event['strFilename'] ?? ''));

    // If the event name is just "Home vs Away" but we have explicit team names, prefer the explicit names
    if ($homeTeam !== '' && $awayTeam !== '' && ($eventName === '' || $eventName === ($homeTeam . ' vs ' . $awayTeam) || $eventName === ($homeTeam . ' v ' . $awayTeam))) {
        $eventName = $homeTeam . ' vs ' . $awayTeam;
    } elseif ($eventName === '') {
        $eventName = $homeTeam !== '' && $awayTeam !== '' ? $homeTeam . ' vs ' . $awayTeam : 'TV event';
    }

    return [
        'id' => (string) ($event['id'] ?? $event['idTV'] ?? sha1(json_encode($event))),
        'event_id' => (string) ($event['idEvent'] ?? ''),
        'sport' => (string) ($event['strSport'] ?? ''),
        'event' => $eventName,
        'league' => (string) ($event['strLeague'] ?? ''),
        'country' => (string) ($event['strCountry'] ?? ''),
        'home_team' => $homeTeam,
        'away_team' => $awayTeam,
        'home_team_id' => (string) ($event['idHomeTeam'] ?? ''),
        'away_team_id' => (string) ($event['idAwayTeam'] ?? ''),
        'home_badge' => (string) ($event['strHomeTeamBadge'] ?? ''),
        'away_badge' => (string) ($event['strAwayTeamBadge'] ?? ''),
        'channel' => $channelName,
        'channel_slug' => $channelSlug !== '' ? $channelSlug : $channel['slug'],
        'configured_channel_slug' => $channel['slug'],
        'configured_channel_label' => $channel['label'],
        'channel_logo' => (string) ($event['strLogo'] ?? ''),
        'season' => (string) ($event['strSeason'] ?? ''),
        'date_event' => (string) ($event['dateEvent'] ?? ''),
        'time' => (string) ($event['strTime'] ?? ''),
        'starts_at' => $eventTime instanceof DateTimeImmutable ? $eventTime->format(DateTimeInterface::ATOM) : '',
        'sort_time' => $eventTime instanceof DateTimeImmutable ? $eventTime->getTimestamp() : PHP_INT_MAX,
        'time_label' => $timeLabel,
        'raw' => $event,
    ];
}

function fb_fetch_tv_events_by_channel(array $config, SQLite3 $db, array $channel): array
{
    $payload = fb_thesportsdb_get(
        $config,
        $db,
        '/filter/tv/channel/' . rawurlencode($channel['slug']),
        (int) $config['thesportsdb']['tv_cache_ttl']
    );

    $rows = fb_extract_list($payload, ['filter', 'tvevents', 'tv', 'events']);
    $events = [];
    $sportFilter = array_map('fb_normalize_text', $config['tv']['sports'] ?? []);

    if (!empty($config['tv']['football_only']) && !in_array('soccer', $sportFilter, true)) {
        $sportFilter[] = 'soccer';
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $event = fb_normalize_tv_event($row, $channel, $config);
        $eventSport = fb_normalize_text($event['sport']);

        if ($sportFilter !== [] && !in_array($eventSport, $sportFilter, true)) {
            continue;
        }

        $events[] = $event;
    }

    usort($events, static fn (array $a, array $b): int => $a['sort_time'] <=> $b['sort_time']);

    return array_slice($events, 0, max(1, (int) ($config['tv']['max_events_per_channel'] ?? 20)));
}

function fb_filter_tv_rows_by_sport(array $config, array $rows): array
{
    $sportFilter = array_map('fb_normalize_text', $config['tv']['sports'] ?? []);

    if (!empty($config['tv']['football_only']) && !in_array('soccer', $sportFilter, true)) {
        $sportFilter[] = 'soccer';
    }

    if ($sportFilter === []) {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row) use ($sportFilter): bool {
        return in_array(fb_normalize_text($row['strSport'] ?? ''), $sportFilter, true);
    }));
}

function fb_fetch_tv_rows_by_path(array $config, SQLite3 $db, string $path): array
{
    $payload = fb_thesportsdb_get(
        $config,
        $db,
        $path,
        (int) $config['thesportsdb']['tv_cache_ttl']
    );

    return fb_extract_list($payload, ['tvevents', 'schedule', 'tv', 'events', 'results']);
}

function fb_discover_tv_channels(array $config, SQLite3 $db): array
{
    foreach (fb_tv_channels($config) as $configuredChannel) {
        fb_save_tv_channel($db, [
            'slug' => $configuredChannel['slug'],
            'id' => '',
            'name' => $configuredChannel['label'],
            'country' => '',
            'logo' => '',
            'sport' => '',
        ]);
    }

    $paths = [];

    foreach ($config['tv']['discovery_countries'] ?? [] as $country) {
        $slug = fb_tv_channel_slug((string) $country);

        if ($slug !== '') {
            $paths[] = '/filter/tv/country/' . rawurlencode($slug);
        }
    }

    foreach ($config['tv']['sports'] ?? [] as $sport) {
        $slug = fb_tv_channel_slug((string) $sport);

        if ($slug !== '') {
            $paths[] = '/filter/tv/sport/' . rawurlencode($slug);
        }
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $daysAhead = max(1, (int) ($config['tv']['discovery_days_ahead'] ?? 7));

    for ($i = 0; $i < $daysAhead; $i++) {
        $date = (new DateTimeImmutable('today', $tz))->modify('+' . $i . ' days')->format('Y-m-d');
        $paths[] = '/filter/tv/day/' . rawurlencode($date);
    }

    $paths = array_values(array_unique($paths));
    $seen = [];
    $rowsScanned = 0;
    $errors = [];

    foreach ($paths as $path) {
        try {
            $rows = fb_fetch_tv_rows_by_path($config, $db, $path);
            $rows = fb_filter_tv_rows_by_sport($config, $rows);
            $rowsScanned += count($rows);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $channel = fb_tv_channel_from_event($row);

                if ($channel === null) {
                    continue;
                }

                fb_save_tv_channel($db, $channel);
                $seen[$channel['slug']] = true;
            }
        } catch (Throwable $error) {
            $errors[] = $path . ': ' . $error->getMessage();
            fb_log('warning', 'Could not discover TV channels', [
                'path' => $path,
                'error' => $error->getMessage(),
            ]);
        }
    }

    return [
        'paths' => count($paths),
        'rows' => $rowsScanned,
        'channels' => count($seen),
        'errors' => $errors,
    ];
}

function fb_fetch_tv_events(array $config, SQLite3 $db): array
{
    $events = [];
    $seen = [];

    foreach (fb_tv_channels($config) as $channel) {
        try {
            foreach (fb_fetch_tv_events_by_channel($config, $db, $channel) as $event) {
                $key = ($event['event_id'] !== '' ? $event['event_id'] : $event['id']) . ':' . $event['configured_channel_slug'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $events[] = $event;
            }
        } catch (Throwable $error) {
            fb_log('warning', 'Could not fetch TV events for channel', [
                'channel' => $channel['slug'],
                'error' => $error->getMessage(),
            ]);
        }
    }

    usort($events, static function (array $a, array $b): int {
        return $a['sort_time'] === $b['sort_time']
            ? strcmp($a['channel'], $b['channel'])
            : $a['sort_time'] <=> $b['sort_time'];
    });

    return $events;
}

function fb_tv_events_in_window(array $config, array $events, int $hoursAhead): array
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $cutoff = $now->modify('+' . max(1, $hoursAhead) . ' hours');

    return array_values(array_filter($events, static function (array $event) use ($now, $cutoff): bool {
        if ($event['starts_at'] === '') {
            return true;
        }

        try {
            $eventTime = new DateTimeImmutable((string) $event['starts_at']);
        } catch (Throwable) {
            return true;
        }

        return $eventTime >= $now && $eventTime <= $cutoff;
    }));
}

function fb_tv_channels_for_event_ids(array $config, SQLite3 $db, array $eventIds): array
{
    $eventIds = array_values(array_filter(array_unique(array_map('strval', $eventIds))));

    if ($eventIds === [] || empty($config['tv']['include_in_match_previews'])) {
        return [];
    }

    $wanted = array_fill_keys($eventIds, true);
    $map = [];

    foreach (fb_fetch_tv_events($config, $db) as $event) {
        $eventId = (string) $event['event_id'];

        if ($eventId === '' || !isset($wanted[$eventId])) {
            continue;
        }

        $map[$eventId][$event['channel']] = true;
    }

    foreach ($eventIds as $eventId) {
        foreach (fb_fetch_event_tv_channels($config, $db, $eventId) as $channel) {
            $map[$eventId][$channel] = true;
        }
    }

    foreach ($map as $eventId => $channels) {
        $map[$eventId] = array_keys($channels);
        sort($map[$eventId]);
    }

    return $map;
}

function fb_fetch_event_tv_channels(array $config, SQLite3 $db, string $eventId): array
{
    if ($eventId === '' || empty($config['tv']['include_in_match_previews'])) {
        return [];
    }

    try {
        $payload = fb_thesportsdb_get(
            $config,
            $db,
            '/lookup/event_tv/' . rawurlencode($eventId),
            (int) $config['thesportsdb']['tv_cache_ttl']
        );
    } catch (Throwable $error) {
        fb_log('warning', 'Could not fetch event TV lookup', [
            'event_id' => $eventId,
            'error' => $error->getMessage(),
        ]);
        return [];
    }

    $channels = [];
    $configuredChannels = [];
    $countryChannels = [];
    $eventCountryChannels = [];
    $configuredSlugs = fb_tv_configured_channel_slugs($config);
    $countrySlugs = array_values(array_filter(array_map(
        static fn (mixed $country): string => fb_tv_channel_slug((string) $country),
        $config['tv']['discovery_countries'] ?? []
    )));

    foreach (fb_extract_list($payload, ['lookup', 'tv', 'tvevents', 'events']) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string) ($row['strChannel'] ?? $row['strChannelName'] ?? ''));
        $id = trim((string) ($row['idChannel'] ?? ''));

        if ($name === '' && $id !== '') {
            $name = fb_tv_channel_label('channel_' . $id);
        }

        if ($name === '') {
            continue;
        }

        $channels[$name] = true;
        $channelSlug = fb_tv_channel_slug($name);
        $countrySlug = fb_tv_channel_slug((string) ($row['strCountry'] ?? ''));
        $eventCountrySlug = fb_tv_channel_slug((string) ($row['strEventCountry'] ?? ''));

        if ($configuredSlugs !== [] && in_array($channelSlug, $configuredSlugs, true)) {
            $configuredChannels[$name] = true;
        }

        if ($countrySlugs !== [] && in_array($countrySlug, $countrySlugs, true)) {
            $countryChannels[$name] = true;
        }

        if ($eventCountrySlug !== '' && $countrySlug === $eventCountrySlug) {
            $eventCountryChannels[$name] = true;
        }

        $channel = fb_tv_channel_from_event($row);

        if ($channel !== null) {
            fb_save_tv_channel($db, $channel);
        }
    }

    // Return all available channels so customers can see every broadcasting option,
    // but prioritise configured channels first, then country-matched, then event-country.
    $prioritised = [];
    foreach (array_keys($configuredChannels) as $name) {
        $prioritised[$name] = true;
    }
    foreach (array_keys($countryChannels) as $name) {
        $prioritised[$name] = true;
    }
    foreach (array_keys($eventCountryChannels) as $name) {
        $prioritised[$name] = true;
    }
    foreach (array_keys($channels) as $name) {
        $prioritised[$name] = true;
    }
    $channels = $prioritised;

    $channels = array_keys($channels);
    sort($channels);

    return $channels;
}

function fb_tv_daily_alert_time(array $config): string
{
    $time = trim((string) ($config['tv']['daily_alert_time'] ?? '08:00'));

    return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time) === 1 ? $time : '08:00';
}

function fb_format_tv_schedule_message(array $config, array $events, int $hoursAhead): string
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $events = fb_tv_events_in_window($config, $events, $hoursAhead);
    $channels = fb_tv_channels($config);
    $byChannel = [];

    foreach ($events as $event) {
        $byChannel[$event['configured_channel_slug']][] = $event;
    }

    $lines = [
        'TV Sports Guide',
        $now->format('D j M') . ' - next ' . max(1, $hoursAhead) . ' hours',
    ];

    $sportFilter = array_values(array_filter($config['tv']['sports'] ?? []));

    if ($sportFilter !== []) {
        $lines[] = implode(', ', $sportFilter);
    } elseif (!empty($config['tv']['football_only'])) {
        $lines[] = 'Soccer';
    }

    if ($channels === []) {
        $lines[] = '';
        $lines[] = 'No TV channels are configured yet.';
    }

    foreach ($channels as $channel) {
        $lines[] = '';
        $lines[] = $channel['label'];
        $channelEvents = $byChannel[$channel['slug']] ?? [];

        if ($channelEvents === []) {
            $lines[] = 'No listed events.';
            continue;
        }

        foreach ($channelEvents as $event) {
            $sport = trim((string) $event['sport']);
            $league = trim((string) $event['league']);
            $homeTeam = trim((string) ($event['home_team'] ?? ''));
            $awayTeam = trim((string) ($event['away_team'] ?? ''));
            $eventName = trim((string) $event['event']);

            // Use explicit team names for fixture-style display when available
            if ($homeTeam !== '' && $awayTeam !== '') {
                $displayEvent = $homeTeam . ' vs ' . $awayTeam;
            } else {
                $displayEvent = $eventName;
            }

            $line = trim($event['time_label'] . ' ' . ($sport !== '' ? $sport . ' - ' : '') . $displayEvent);

            if ($league !== '') {
                $line .= ' (' . $league . ')';
            }

            $lines[] = $line;
        }
    }

    $message = trim(implode("\n", $lines));

    if (strlen($message) > 3900) {
        $message = substr($message, 0, 3900) . "\nMore events hidden in this digest.";
    }

    return $message;
}

function fb_detect_tv_schedule_alerts(array $config, SQLite3 $db): array
{
    if (empty($config['tv']['enabled']) || empty($config['tv']['daily_alerts']) || fb_tv_channels($config) === []) {
        return [];
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $scheduled = new DateTimeImmutable($now->format('Y-m-d') . ' ' . fb_tv_daily_alert_time($config), $tz);

    if ($now < $scheduled) {
        return [];
    }

    $channelsHash = sha1(json_encode([
        'channels' => array_column(fb_tv_channels($config), 'slug'),
        'sports' => $config['tv']['sports'] ?? [],
        'hours' => (int) ($config['tv']['lookahead_hours'] ?? 24),
        'football_only' => !empty($config['tv']['football_only']),
        'send_image' => !empty($config['tv']['send_image']),
    ]));
    $key = 'tv_schedule:' . $now->format('Y-m-d') . ':' . $channelsHash;

    if (fb_was_alert_sent($db, $key)) {
        return [];
    }

    $events = fb_fetch_tv_events($config, $db);
    $hoursAhead = (int) ($config['tv']['lookahead_hours'] ?? 24);

    return [[
        'key' => $key,
        'type' => 'TV_SCHEDULE',
        'text' => fb_format_tv_schedule_message($config, $events, $hoursAhead),
        'events' => fb_tv_events_in_window($config, $events, $hoursAhead),
        'hours_ahead' => $hoursAhead,
        'meta' => [
            'channels' => array_column(fb_tv_channels($config), 'slug'),
            'sports' => $config['tv']['sports'] ?? [],
            'lookahead_hours' => $hoursAhead,
            'event_count' => count(fb_tv_events_in_window($config, $events, $hoursAhead)),
        ],
    ]];
}

function fb_fetch_scheduled_matches_between(array $config, SQLite3 $db, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $windowStart = $windowStart->setTimezone($tz);
    $windowEnd = $windowEnd->setTimezone($tz);
    $upcoming = [];

    foreach (fb_coverage_schedule_leagues($config, $db) as $coverageLeague) {
        $leagueId = (string) ($coverageLeague['league_id'] ?? '');

        if ($leagueId === '') {
            continue;
        }

        try {
            $payload = fb_thesportsdb_get(
                $config,
                $db,
                '/schedule/next/league/' . rawurlencode((string) $leagueId),
                300
            );

            $events = fb_extract_list($payload, ['schedule', 'events', 'next']);

            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $eventTime = fb_event_datetime($event, $tz);

                if ($eventTime === null) {
                    continue;
                }

                if ($eventTime >= $windowStart && $eventTime <= $windowEnd) {
                    $event = fb_with_local_event_time($event, $eventTime);
                    $event['_allowedLeagueId'] = $leagueId;
                    $event['_allowedLeagueName'] = (string) ($coverageLeague['league_name'] ?? $event['strLeague'] ?? '');
                    $event['_allowedSport'] = (string) ($coverageLeague['sport'] ?? $event['strSport'] ?? 'Soccer');
                    $league = fb_coverage_match_allowed($config, $event);

                    if ($league === null) {
                        continue;
                    }

                    $event['_allowedLeagueId'] = $league['id'];
                    $event['_allowedLeagueName'] = $league['name'];
                    $event['_allowedSport'] = $league['sport'];
                    $upcoming[] = $event;
                }
            }
        } catch (Throwable $error) {
            fb_log('warning', 'Could not fetch upcoming matches for league', [
                'league_id' => $leagueId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    usort($upcoming, static fn (array $a, array $b): int => fb_event_sort_timestamp($a) <=> fb_event_sort_timestamp($b));

    return $upcoming;
}

function fb_fetch_upcoming_matches(array $config, SQLite3 $db, int $hoursAhead = 4): array
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);

    return fb_fetch_scheduled_matches_between($config, $db, $now, $now->modify("+{$hoursAhead} hours"));
}

function fb_detect_preview_alerts(array $config, SQLite3 $db): array
{
    if (!(bool) $config['alerts']['send_match_previews']) {
        return [];
    }

    $hoursAhead = (int) ($config['alerts']['preview_hours_ahead'] ?? 4);
    $upcoming = fb_fetch_upcoming_matches($config, $db, $hoursAhead);
    $alerts = [];
    $tvByEventId = fb_tv_channels_for_event_ids(
        $config,
        $db,
        array_map(static fn (array $event): string => (string) ($event['idEvent'] ?? ''), $upcoming)
    );

    foreach ($upcoming as $event) {
        $match = fb_normalize_match($event);
        $tvChannels = $tvByEventId[$match['event_id']] ?? [];

        if (!empty($config['tv']['preview_require_tv']) && $tvChannels === []) {
            continue;
        }

        $previewKey = 'preview:' . $match['event_id'];

        if (fb_was_alert_sent($db, $previewKey)) {
            continue;
        }

        $match = fb_enrich_match_team_badges($config, $db, $match);
        $leagueArtwork = fb_fetch_league_artwork($config, $db, $match['league_id']);
        $match['league_logo'] = $leagueArtwork['logo'] ?: ($leagueArtwork['badge'] ?? '');

        $alerts[] = fb_build_base_alert('MATCH_PREVIEW', $previewKey, $match, [
            'minute' => $match['progress'] ?? 0,
            'event_time' => $match['event_time'],
            'league_name' => $match['league_name'],
            'tv_channels' => $tvChannels,
        ]);
    }

    return $alerts;
}

function fb_configured_telegram_route_sports(array $config): array
{
    $routes = $config['telegram']['routes'] ?? [];

    if (!is_array($routes)) {
        return [];
    }

    $sports = [];

    foreach ($routes as $sport => $chatIds) {
        if (fb_sport_key((string) $sport) === 'default') {
            continue;
        }

        $ids = is_array($chatIds) ? $chatIds : (is_string($chatIds) ? preg_split('/[\r\n,]+/', $chatIds) : []);

        if (array_values(array_filter(array_map('strval', $ids ?: []))) !== []) {
            $sports[fb_sport_key((string) $sport)] = fb_canonical_sport((string) $sport, (string) $sport);
        }
    }

    return $sports;
}

function fb_detect_daily_card_alerts(array $config, SQLite3 $db): array
{
    if (!(bool) $config['alerts']['send_daily_card']) {
        return [];
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $scheduledTime = new DateTimeImmutable($today . ' ' . $config['alerts']['daily_card_time'], $tz);

    if ($now < $scheduledTime) {
        return [];
    }

    $upcoming = fb_fetch_upcoming_matches($config, $db, 24);
    $byLeague = [];

    // Fetch TV channels for all matches at once
    $tvByEventId = fb_tv_channels_for_event_ids(
        $config,
        $db,
        array_map(static fn(array $event): string => (string) ($event['idEvent'] ?? ''), $upcoming)
    );

    foreach ($upcoming as $event) {
        $league = fb_coverage_match_allowed($config, $event);

        if ($league === null) {
            $league = [
                'id' => (string) ($event['_allowedLeagueId'] ?? $event['idLeague'] ?? ''),
                'name' => (string) ($event['_allowedLeagueName'] ?? $event['strLeague'] ?? 'Football'),
                'sport' => fb_match_sport($event),
            ];
        }

        $match = fb_normalize_match($event);
        $match = fb_enrich_match_team_badges($config, $db, $match);
        $leagueArtwork = fb_fetch_league_artwork($config, $db, $match['league_id']);
        $match['league_logo'] = $leagueArtwork['logo'] ?: ($leagueArtwork['badge'] ?? '');
        $match['tv_channels'] = $tvByEventId[$match['event_id']] ?? [];

        $sport = fb_canonical_sport((string) ($league['sport'] ?? $match['sport'] ?? 'Soccer'));
        $leagueId = $sport . ':' . $league['id'];

        if (!isset($byLeague[$leagueId])) {
            $byLeague[$leagueId] = [
                'league_id' => $leagueId,
                'league_name' => $league['name'],
                'sport' => $sport,
                'league_logo' => $match['league_logo'],
                'matches' => [],
            ];
        }

        $byLeague[$leagueId]['matches'][] = $match;
    }

    if ($byLeague === []) {
        return [];
    }

    $byLeague = array_values($byLeague);
    $alerts = [];
    $allKey = 'daily_card:' . $today . ':all';

    if (!fb_was_alert_sent($db, $allKey)) {
        $alerts[] = [
            'key' => $allKey,
            'type' => 'DAILY_CARD',
            'text' => fb_format_daily_card_message($config, $byLeague),
            'leagues' => $byLeague,
            'meta' => [
                'date' => $today,
                'sport' => '',
                'league_count' => count($byLeague),
                'match_count' => array_sum(array_map(static fn(array $l): int => count($l['matches']), $byLeague)),
            ],
        ];
    }

    $routeSports = fb_configured_telegram_route_sports($config);

    foreach ($routeSports as $sportKey => $sportName) {
        $sportLeagues = array_values(array_filter($byLeague, static function (array $league) use ($sportKey): bool {
            return fb_sport_key((string) ($league['sport'] ?? '')) === $sportKey;
        }));

        if ($sportLeagues === []) {
            continue;
        }

        $sportAlertKey = 'daily_card:' . $today . ':' . fb_tv_channel_slug($sportName);

        if (fb_was_alert_sent($db, $sportAlertKey)) {
            continue;
        }

        $alerts[] = [
            'key' => $sportAlertKey,
            'type' => 'DAILY_CARD',
            'text' => fb_format_daily_card_message($config, $sportLeagues),
            'leagues' => $sportLeagues,
            'meta' => [
                'date' => $today,
                'sport' => $sportName,
                'league_count' => count($sportLeagues),
                'match_count' => array_sum(array_map(static fn(array $l): int => count($l['matches']), $sportLeagues)),
            ],
        ];
    }

    return $alerts;
}

function fb_format_daily_card_message(array $config, array $byLeague): string
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $sports = array_values(array_unique(array_filter(array_map(static fn(array $league): string => (string) ($league['sport'] ?? ''), $byLeague))));
    $lines = [];
    $title = count($sports) === 1 ? $sports[0] . ' Matches' : "Today's Sports";
    $lines[] = sprintf("%s - %s", $title, $now->format('D j M Y'));
    $lines[] = '';
    $currentSport = null;

    foreach ($byLeague as $league) {
        $sport = (string) ($league['sport'] ?? '');

        if (count($sports) > 1 && $sport !== '' && $sport !== $currentSport) {
            $lines[] = $sport;
            $currentSport = $sport;
        }

        $lines[] = $league['league_name'];

        foreach ($league['matches'] as $match) {
            $time = $match['event_time'] ?? '';
            $timeShort = $time !== '' ? substr($time, 0, 5) : 'TBC';
            $tvChannels = $match['tv_channels'] ?? [];
            $tvInfo = $tvChannels !== [] ? ' TV: ' . implode(', ', $tvChannels) : '';
            $lines[] = sprintf("  %s  %s vs %s%s", $timeShort, $match['home_team'], $match['away_team'], $tvInfo);
        }

        $lines[] = '';
    }

    $message = trim(implode("\n", $lines));

    if (strlen($message) > 3900) {
        $message = substr($message, 0, 3900) . "\nMore matches hidden in this digest.";
    }

    return $message;
}

function fb_card_type_slug(string $type): string
{
    return strtolower(str_replace([' ', '-'], '_', trim($type)));
}

function fb_card_type_enabled(array $config, string $type): bool
{
    $enabled = array_values(array_unique(array_merge(
        array_map('fb_card_type_slug', $config['cards']['types_enabled'] ?? []),
        array_map('fb_card_type_slug', $config['content']['packs_enabled'] ?? [])
    )));

    return in_array(fb_card_type_slug($type), $enabled, true);
}

function fb_card_timezone_label(array $config, ?DateTimeImmutable $time = null): string
{
    $tz = new DateTimeZone($config['app']['timezone']);
    $time = ($time ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);

    return 'UK time ' . $time->format('T');
}

function fb_card_match_sort_timestamp(array $match): int
{
    $startsAt = trim((string) ($match['starts_at'] ?? ''));

    if ($startsAt !== '') {
        try {
            return (new DateTimeImmutable($startsAt))->getTimestamp();
        } catch (Throwable) {
            // Fall through to date/time fields.
        }
    }

    $date = trim((string) ($match['date_event'] ?? ''));
    $time = trim((string) ($match['event_time'] ?? '00:00'));

    if ($date !== '') {
        try {
            return (new DateTimeImmutable($date . ' ' . $time))->getTimestamp();
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    return PHP_INT_MAX;
}

function fb_prepare_card_matches(array $config, SQLite3 $db, array $events, bool $includeTv = true): array
{
    $matches = [];
    $eventIds = [];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $match = isset($event['event_id']) ? $event : fb_normalize_match($event);
        $matches[] = $match;

        if (!empty($match['event_id'])) {
            $eventIds[] = (string) $match['event_id'];
        }
    }

    $tvByEventId = $includeTv ? fb_tv_channels_for_event_ids($config, $db, $eventIds) : [];
    $tvRegistry = $includeTv ? fb_card_tv_channel_registry($db) : [];

    foreach ($matches as &$match) {
        try {
            $match = fb_enrich_match_team_badges($config, $db, $match);
        } catch (Throwable $error) {
            fb_log('warning', 'Could not enrich card match team badges', [
                'event_id' => $match['event_id'] ?? '',
                'error' => $error->getMessage(),
            ]);
        }

        try {
            $leagueArtwork = fb_fetch_league_artwork($config, $db, (string) ($match['league_id'] ?? ''));
            $match['league_logo'] = $match['league_logo'] ?? ($leagueArtwork['logo'] ?: ($leagueArtwork['badge'] ?? ''));
        } catch (Throwable $error) {
            $match['league_logo'] = $match['league_logo'] ?? '';
        }

        $eventId = (string) ($match['event_id'] ?? '');
        $channels = $match['tv_channels'] ?? ($tvByEventId[$eventId] ?? []);
        $channels = is_array($channels) ? $channels : [];
        $channels = array_values(array_unique(array_filter(array_map('strval', $channels))));

        $match['tv_channels'] = $channels;
        $match['tv_channel_details'] = fb_card_tv_channel_details($channels, $tvRegistry);
    }
    unset($match);

    usort($matches, static fn (array $a, array $b): int => fb_card_match_sort_timestamp($a) <=> fb_card_match_sort_timestamp($b));

    return $matches;
}

function fb_cache_card_asset_urls(array $config, array $urls): void
{
    $urls = array_values(array_unique(array_filter(array_map(
        static fn (mixed $url): string => trim((string) $url),
        $urls
    ), static fn (string $url): bool => $url !== '')));

    foreach ($urls as $url) {
        try {
            fb_download_asset($config, $url);
        } catch (Throwable $error) {
            fb_log('warning', 'Could not cache card image asset', [
                'url' => $url,
                'error' => $error->getMessage(),
            ]);
        }
    }
}

function fb_cache_matchday_card_assets(array $config, array $card): void
{
    $urls = [];
    $addMatch = static function (array $match) use (&$urls): void {
        $urls[] = (string) ($match['home_badge'] ?? '');
        $urls[] = (string) ($match['away_badge'] ?? '');
        $urls[] = (string) ($match['league_logo'] ?? '');

        foreach (($match['tv_channel_details'] ?? []) as $channel) {
            if (is_array($channel)) {
                $urls[] = (string) ($channel['logo'] ?? '');
            }
        }
    };
    $addEvent = static function (array $event) use (&$urls): void {
        $urls[] = (string) ($event['home_badge'] ?? '');
        $urls[] = (string) ($event['away_badge'] ?? '');
        $urls[] = (string) ($event['channel_logo'] ?? '');
    };

    if (is_array($card['match'] ?? null)) {
        $addMatch($card['match']);
    }

    if (is_array($card['event'] ?? null)) {
        $addEvent($card['event']);
    }

    foreach (($card['sections'] ?? []) as $section) {
        if (!is_array($section)) {
            continue;
        }

        $urls[] = (string) ($section['logo'] ?? '');

        foreach (($section['matches'] ?? []) as $match) {
            if (is_array($match)) {
                $addMatch($match);
            }
        }

        foreach (($section['events'] ?? []) as $event) {
            if (is_array($event)) {
                $addEvent($event);
            }
        }
    }

    fb_cache_card_asset_urls($config, $urls);
}

function fb_group_card_matches_by_league(array $matches): array
{
    $groups = [];

    foreach ($matches as $match) {
        $sport = fb_canonical_sport((string) ($match['sport'] ?? 'Sport'), (string) ($match['sport'] ?? 'Sport'));
        $leagueId = (string) ($match['league_id'] ?? '');
        $leagueName = (string) ($match['league_name'] ?? $sport);
        $key = fb_sport_key($sport) . ':' . ($leagueId !== '' ? $leagueId : fb_sport_key($leagueName));

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'title' => $leagueName,
                'subtitle' => $sport,
                'sport' => $sport,
                'logo' => (string) ($match['league_logo'] ?? ''),
                'matches' => [],
            ];
        }

        $groups[$key]['matches'][] = $match;
    }

    return array_values($groups);
}

function fb_card_tv_channel_registry(SQLite3 $db): array
{
    $registry = [];

    foreach (fb_list_tv_channel_registry($db) as $channel) {
        $slug = fb_tv_channel_slug((string) ($channel['channel_name'] ?? $channel['channel_slug'] ?? ''));

        if ($slug === '') {
            $slug = fb_tv_channel_slug((string) ($channel['channel_slug'] ?? ''));
        }

        if ($slug !== '') {
            $registry[$slug] = $channel;
        }
    }

    return $registry;
}

function fb_card_tv_channel_details(array $channelNames, array $registry): array
{
    $details = [];

    foreach ($channelNames as $channelName) {
        $name = trim((string) $channelName);

        if ($name === '') {
            continue;
        }

        $slug = fb_tv_channel_slug($name);
        $registered = $registry[$slug] ?? [];
        $details[] = [
            'name' => $name,
            'slug' => $slug,
            'logo' => (string) ($registered['logo'] ?? ''),
        ];
    }

    return $details;
}

function fb_card_paginate_sections(array $sections, string $itemKey = 'matches', int $maxRows = 8): array
{
    $pages = [];
    $current = [];
    $rowCount = 0;

    foreach ($sections as $section) {
        $items = array_values($section[$itemKey] ?? []);

        if ($items === []) {
            continue;
        }

        while ($items !== []) {
            $remaining = max(1, $maxRows - $rowCount);
            $chunk = array_splice($items, 0, $remaining);
            $pageSection = $section;
            $pageSection[$itemKey] = $chunk;
            $current[] = $pageSection;
            $rowCount += count($chunk);

            if ($rowCount >= $maxRows) {
                $pages[] = $current;
                $current = [];
                $rowCount = 0;
            }
        }
    }

    if ($current !== []) {
        $pages[] = $current;
    }

    return $pages === [] ? [[]] : $pages;
}

function fb_card_bucket(DateTimeImmutable $now, int $cooldownMinutes): string
{
    $seconds = max(300, $cooldownMinutes * 60);

    return (string) intdiv($now->getTimestamp(), $seconds);
}

function fb_card_job_key(string $cardType, string $routeKey, string $sport, string $bucket): string
{
    return implode(':', [
        'card',
        fb_card_type_slug($cardType),
        fb_tv_channel_slug($routeKey !== '' ? $routeKey : 'default'),
        fb_tv_channel_slug($sport !== '' ? $sport : 'all'),
        fb_tv_channel_slug($bucket),
    ]);
}

function fb_card_make_job(array $config, string $cardType, string $routeKey, string $sport, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, array $pagePayloads, string $bucket): array
{
    $pageCount = max(1, count($pagePayloads));

    foreach ($pagePayloads as $idx => &$pagePayload) {
        $pagePayload['card_type'] = $cardType;
        $pagePayload['sport'] = $sport;
        $pagePayload['route_key'] = $routeKey;
        $pagePayload['page'] = $idx + 1;
        $pagePayload['page_count'] = $pageCount;
        $pagePayload['timezone_label'] = $pagePayload['timezone_label'] ?? fb_card_timezone_label($config, $windowStart);
        $pagePayload['generated_at'] = $pagePayload['generated_at'] ?? (new DateTimeImmutable('now', new DateTimeZone($config['app']['timezone'])))->format('H:i');
    }
    unset($pagePayload);

    return [
        'job_key' => fb_card_job_key($cardType, $routeKey, $sport, $bucket),
        'card_type' => $cardType,
        'sport' => $sport,
        'route_key' => $routeKey,
        'window_start' => $windowStart->format(DateTimeInterface::ATOM),
        'window_end' => $windowEnd->format(DateTimeInterface::ATOM),
        'status' => 'pending',
        'payload' => [
            'pages' => $pagePayloads,
        ],
        'page_count' => $pageCount,
    ];
}

function fb_card_store_job(SQLite3 $db, array $job): array
{
    $payloadJson = json_encode($job['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    fb_execute(
        $db,
        'INSERT INTO card_jobs (
            job_key, card_type, sport, route_key, window_start, window_end, status, payload_json, page_count, created_at, updated_at, last_error
        ) VALUES (
            :job_key, :card_type, :sport, :route_key, :window_start, :window_end, "pending", :payload_json, :page_count, :created_at, :updated_at, NULL
        ) ON CONFLICT(job_key) DO UPDATE SET
            payload_json = CASE WHEN card_jobs.status != "sent" THEN excluded.payload_json ELSE card_jobs.payload_json END,
            page_count = CASE WHEN card_jobs.status != "sent" THEN excluded.page_count ELSE card_jobs.page_count END,
            window_start = excluded.window_start,
            window_end = excluded.window_end,
            updated_at = CASE WHEN card_jobs.status != "sent" THEN excluded.updated_at ELSE card_jobs.updated_at END',
        [
            ':job_key' => $job['job_key'],
            ':card_type' => $job['card_type'],
            ':sport' => $job['sport'],
            ':route_key' => $job['route_key'],
            ':window_start' => $job['window_start'],
            ':window_end' => $job['window_end'],
            ':payload_json' => $payloadJson ?: '{"pages":[]}',
            ':page_count' => (int) $job['page_count'],
            ':created_at' => fb_now(),
            ':updated_at' => fb_now(),
        ]
    );

    $stored = fb_fetch_one($db, 'SELECT * FROM card_jobs WHERE job_key = :job_key', [
        ':job_key' => $job['job_key'],
    ]);

    return $stored ?: $job;
}

function fb_card_build_match_jobs(array $config, SQLite3 $db, string $cardType, string $slug, string $title, string $subtitle, array $matches, int $threshold, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, bool $persist, ?string $bucketOverride = null): array
{
    if (!fb_card_type_enabled($config, $slug) || $matches === []) {
        return [];
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $bucket = $bucketOverride ?? fb_card_bucket($now, (int) ($config['cards']['burst_cooldown_minutes'] ?? 60));
    $jobs = [];

    $build = function (string $routeKey, string $sport, array $routeMatches, int $routeThreshold) use ($config, $db, $cardType, $title, $subtitle, $windowStart, $windowEnd, $bucket, $persist): array {
        if (count($routeMatches) < $routeThreshold) {
            return [];
        }

        $limit = max(1, (int) ($config['cards']['max_items_per_type'] ?? 4));
        $routeMatches = array_slice(array_values($routeMatches), 0, $limit);
        $routeJobs = [];
        $total = count($routeMatches);

        foreach ($routeMatches as $idx => $match) {
            $matchSport = fb_canonical_sport((string) ($match['sport'] ?? $sport), (string) ($match['sport'] ?? $sport));
            $leagueName = trim((string) ($match['league_name'] ?? $matchSport));
            $matchKey = trim((string) ($match['event_id'] ?? ''));

            if ($matchKey === '') {
                $matchKey = sha1(json_encode([
                    $match['home_team'] ?? '',
                    $match['away_team'] ?? '',
                    $match['starts_at'] ?? '',
                    $match['event_time'] ?? '',
                    $leagueName,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ('match-' . $idx));
            }

            $section = [
                'title' => $leagueName !== '' ? $leagueName : $matchSport,
                'subtitle' => $matchSport,
                'sport' => $matchSport,
                'logo' => (string) ($match['league_logo'] ?? ''),
                'matches' => [$match],
            ];

            $pagePayload = [
                'title' => $sport !== '' ? $sport . ' ' . $title : $title,
                'subtitle' => $leagueName !== '' ? $leagueName : $subtitle,
                'card_subtitle' => $subtitle,
                'kind' => 'match',
                'match' => $match,
                'section' => $section,
                'sections' => [$section],
                'total_count' => $total,
                'sequence' => $idx + 1,
                'sequence_count' => $total,
            ];

            $job = fb_card_make_job(
                $config,
                $cardType,
                $routeKey,
                $sport,
                $windowStart,
                $windowEnd,
                [$pagePayload],
                $bucket . ':match:' . $matchKey
            );

            $routeJobs[] = $persist ? fb_card_store_job($db, $job) : $job;
        }

        return $routeJobs;
    };

    $jobs = array_merge($jobs, $build('default', '', $matches, $threshold));

    foreach (fb_configured_telegram_route_sports($config) as $sportKey => $sportName) {
        $sportMatches = array_values(array_filter($matches, static function (array $match) use ($sportKey): bool {
            return fb_sport_key((string) ($match['sport'] ?? '')) === $sportKey;
        }));
        $jobs = array_merge($jobs, $build($sportKey, $sportName, $sportMatches, 1));
    }

    return $jobs;
}

function fb_card_build_tv_jobs(array $config, SQLite3 $db, array $events, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, bool $persist, string $cardType = 'TV_GUIDE', string $slug = 'tv_guide', string $title = 'TV Guide', string $subtitle = 'Today and next live listings', ?string $bucketOverride = null): array
{
    if (!fb_card_type_enabled($config, $slug) || $events === []) {
        return [];
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $bucket = $bucketOverride ?? fb_card_bucket($now, (int) ($config['cards']['burst_cooldown_minutes'] ?? 60));
    $jobs = [];

    $build = function (string $routeKey, string $sport, array $routeEvents) use ($config, $db, $windowStart, $windowEnd, $bucket, $persist, $cardType, $title, $subtitle): array {
        if ($routeEvents === []) {
            return [];
        }

        $limit = max(1, (int) ($config['cards']['max_items_per_type'] ?? 4));
        $routeEvents = array_slice(array_values($routeEvents), 0, $limit);
        $routeJobs = [];
        $total = count($routeEvents);

        foreach ($routeEvents as $idx => $event) {
            $slug = (string) ($event['configured_channel_slug'] ?? $event['channel_slug'] ?? 'other');
            $channelLabel = (string) ($event['configured_channel_label'] ?? $event['channel'] ?? fb_tv_channel_label($slug));
            $eventKey = trim((string) ($event['event_id'] ?? $event['id'] ?? ''));

            if ($eventKey === '') {
                $eventKey = sha1(json_encode([
                    $event['event'] ?? '',
                    $event['starts_at'] ?? '',
                    $channelLabel,
                    $event['sport'] ?? '',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ('event-' . $idx));
            }

            $section = [
                'title' => $channelLabel !== '' ? $channelLabel : fb_tv_channel_label($slug),
                'subtitle' => 'TV/Streaming',
                'sport' => $sport,
                'logo' => (string) ($event['channel_logo'] ?? ''),
                'events' => [$event],
            ];

            $pagePayload = [
                'title' => $sport !== '' ? $sport . ' ' . $title : $title,
                'subtitle' => $channelLabel !== '' ? $channelLabel : $subtitle,
                'card_subtitle' => $subtitle,
                'kind' => 'tv_event',
                'event' => $event,
                'section' => $section,
                'sections' => [$section],
                'total_count' => $total,
                'sequence' => $idx + 1,
                'sequence_count' => $total,
            ];

            $job = fb_card_make_job(
                $config,
                $cardType,
                $routeKey,
                $sport,
                $windowStart,
                $windowEnd,
                [$pagePayload],
                $bucket . ':event:' . $eventKey . ':' . $slug
            );

            $routeJobs[] = $persist ? fb_card_store_job($db, $job) : $job;
        }

        return $routeJobs;
    };

    $jobs = array_merge($jobs, $build('default', '', $events));

    foreach (fb_configured_telegram_route_sports($config) as $sportKey => $sportName) {
        $sportEvents = array_values(array_filter($events, static function (array $event) use ($sportKey): bool {
            return fb_sport_key(fb_canonical_sport((string) ($event['sport'] ?? ''), (string) ($event['sport'] ?? ''))) === $sportKey;
        }));
        $jobs = array_merge($jobs, $build($sportKey, $sportName, $sportEvents));
    }

    return $jobs;
}

function fb_schedule_matchday_card_jobs(array $config, SQLite3 $db, array $liveRows = [], bool $persist = true): array
{
    if (empty($config['cards']['bursts_enabled'])) {
        return [];
    }

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $jobs = [];

    $liveMatches = fb_prepare_card_matches($config, $db, $liveRows, false);
    $liveOnly = array_values(array_filter($liveMatches, static fn (array $match): bool => fb_is_live_status((string) ($match['status'] ?? '')) || fb_is_half_time_status((string) ($match['status'] ?? ''))));
    $results = array_values(array_filter($liveMatches, static fn (array $match): bool => fb_is_full_time_status((string) ($match['status'] ?? ''))));

    if ($liveOnly !== []) {
        $jobs = array_merge($jobs, fb_card_build_match_jobs(
            $config,
            $db,
            'LIVE_NOW',
            'live_now',
            'Live Now',
            'Scores and periods updating from TheSportsDB',
            $liveOnly,
            (int) ($config['cards']['burst_min_live'] ?? 2),
            $now,
            $now,
            $persist
        ));
    }

    if ($results !== []) {
        $jobs = array_merge($jobs, fb_card_build_match_jobs(
            $config,
            $db,
            'RESULTS_ROUNDUP',
            'results',
            'Results Roundup',
            'Final scores seen this run',
            $results,
            (int) ($config['cards']['burst_min_results'] ?? 3),
            $now,
            $now,
            $persist
        ));
    }

    $fixtures = fb_prepare_card_matches($config, $db, fb_fetch_upcoming_matches($config, $db, 24), true);
    if ($fixtures !== []) {
        $jobs = array_merge($jobs, fb_card_build_match_jobs(
            $config,
            $db,
            'FIXTURES_BURST',
            'fixtures',
            'Fixtures',
            'Next 24 hours',
            $fixtures,
            (int) ($config['cards']['burst_min_fixtures'] ?? 3),
            $now,
            $now->modify('+24 hours'),
            $persist
        ));
    }

    if (fb_content_pack_enabled($config, 'morning_planner') && $fixtures !== []) {
        $todayEnd = $now->setTime(23, 59, 59);
        $todayFixtures = array_values(array_filter($fixtures, static function (array $match) use ($now, $todayEnd): bool {
            $startsAt = fb_card_match_sort_timestamp($match);

            return $startsAt !== PHP_INT_MAX && $startsAt >= $now->getTimestamp() && $startsAt <= $todayEnd->getTimestamp();
        }));

        if ($todayFixtures !== []) {
            $jobs = array_merge($jobs, fb_card_build_match_jobs(
                $config,
                $db,
                'MORNING_PLANNER',
                'morning_planner',
                'Morning Planner',
                'All sports still to come today',
                $todayFixtures,
                (int) ($config['cards']['burst_min_fixtures'] ?? 3),
                $now,
                $todayEnd,
                $persist,
                'day:' . $now->format('Ymd')
            ));
        }
    }

    $kickoffCutoff = $now->modify('+' . max(1, (int) ($config['alerts']['kickoff_reminder_minutes'] ?? 10)) . ' minutes');
    $kickoffSoon = array_values(array_filter($fixtures, static function (array $match) use ($now, $kickoffCutoff): bool {
        $startsAt = fb_card_match_sort_timestamp($match);

        return $startsAt !== PHP_INT_MAX && $startsAt >= $now->getTimestamp() && $startsAt <= $kickoffCutoff->getTimestamp();
    }));

    if ($kickoffSoon !== []) {
        $jobs = array_merge($jobs, fb_card_build_match_jobs(
            $config,
            $db,
            'KICKOFF_SOON',
            'kickoff_soon',
            'Kick-off Soon',
            'Starting in the reminder window',
            $kickoffSoon,
            1,
            $now,
            $kickoffCutoff,
            $persist
        ));
    }

    if (!empty($config['tv']['enabled']) && fb_tv_channels($config) !== []) {
        $tvEvents = fb_tv_events_in_window($config, fb_fetch_tv_events($config, $db), (int) ($config['tv']['lookahead_hours'] ?? 24));
        $jobs = array_merge($jobs, fb_card_build_tv_jobs($config, $db, $tvEvents, $now, $now->modify('+' . (int) ($config['tv']['lookahead_hours'] ?? 24) . ' hours'), $persist));

        if (fb_content_pack_enabled($config, 'tv_now')) {
            $tvNowHours = 3;
            $tvNowEvents = fb_tv_events_in_window($config, $tvEvents, $tvNowHours);
            $jobs = array_merge($jobs, fb_card_build_tv_jobs(
                $config,
                $db,
                $tvNowEvents,
                $now,
                $now->modify('+' . $tvNowHours . ' hours'),
                $persist,
                'TV_NOW',
                'tv_now',
                'TV Now',
                'Live and starting in the next 3 hours'
            ));
        }
    }

    $tomorrowStart = (new DateTimeImmutable('tomorrow', $tz))->setTime(0, 0);
    $tomorrowEnd = $tomorrowStart->setTime(23, 59, 59);
    $tomorrowMatches = fb_prepare_card_matches($config, $db, fb_fetch_scheduled_matches_between($config, $db, $tomorrowStart, $tomorrowEnd), true);

    if ($tomorrowMatches !== []) {
        $tomorrowJobs = fb_card_build_match_jobs(
            $config,
            $db,
            'TOMORROW_LOOKAHEAD',
            'tomorrow',
            'Tomorrow',
            $tomorrowStart->format('D j M'),
            $tomorrowMatches,
            (int) ($config['cards']['burst_min_fixtures'] ?? 3),
            $tomorrowStart,
            $tomorrowEnd,
            $persist
        );
        $jobs = array_merge($jobs, $tomorrowJobs);
    }

    if (fb_content_pack_enabled($config, 'weekend')) {
        $day = (int) $now->format('N');
        $today = $now->setTime(0, 0);
        if ($day <= 5) {
            $weekendStart = $today->modify('saturday this week');
        } elseif ($day === 6) {
            $weekendStart = $today;
        } else {
            $weekendStart = $today->modify('-1 day');
        }
        $weekendEnd = $weekendStart->modify('+1 day')->setTime(23, 59, 59);

        if ($weekendEnd->getTimestamp() >= $now->getTimestamp()) {
            $weekendMatches = fb_prepare_card_matches($config, $db, fb_fetch_scheduled_matches_between($config, $db, $weekendStart, $weekendEnd), true);

            if ($weekendMatches !== []) {
                $jobs = array_merge($jobs, fb_card_build_match_jobs(
                    $config,
                    $db,
                    'WEEKEND_PLANNER',
                    'weekend',
                    'Weekend Planner',
                    $weekendStart->format('D j M') . ' - ' . $weekendEnd->format('D j M'),
                    $weekendMatches,
                    (int) ($config['cards']['burst_min_fixtures'] ?? 3),
                    $weekendStart,
                    $weekendEnd,
                    $persist,
                    'weekend:' . $weekendStart->format('Ymd')
                ));
            }
        }
    }

    return $jobs;
}

function fb_fetch_card_jobs(SQLite3 $db, int $limit = 20, array $statuses = ['pending', 'failed']): array
{
    $statuses = array_values(array_filter(array_map('strval', $statuses)));

    if ($statuses === []) {
        return [];
    }

    $placeholders = [];
    $params = [':limit' => $limit];

    foreach ($statuses as $idx => $status) {
        $key = ':status' . $idx;
        $placeholders[] = $key;
        $params[$key] = $status;
    }

    $stmt = $db->prepare(
        'SELECT * FROM card_jobs
         WHERE status IN (' . implode(',', $placeholders) . ')
         ORDER BY updated_at ASC, id ASC
         LIMIT :limit'
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, is_int($value) ? $value : (string) $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $jobs = [];

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $jobs[] = $row;
        }
    }

    return $jobs;
}

function fb_update_card_job_status(SQLite3 $db, string $jobKey, string $status, ?string $error = null): void
{
    fb_execute(
        $db,
        'UPDATE card_jobs SET status = :status, last_error = :last_error, updated_at = :updated_at WHERE job_key = :job_key',
        [
            ':status' => $status,
            ':last_error' => $error,
            ':updated_at' => fb_now(),
            ':job_key' => $jobKey,
        ]
    );
}

function fb_card_dispatch_sent(SQLite3 $db, string $jobKey, string $chatId, int $pageNo): bool
{
    return fb_fetch_one(
        $db,
        'SELECT id FROM card_dispatches WHERE job_key = :job_key AND chat_id = :chat_id AND page_no = :page_no AND status = "sent"',
        [
            ':job_key' => $jobKey,
            ':chat_id' => $chatId,
            ':page_no' => $pageNo,
        ]
    ) !== null;
}

function fb_record_card_dispatch(SQLite3 $db, string $jobKey, string $chatId, int $pageNo, string $status, ?string $messageId, ?string $imagePath, ?string $error): void
{
    fb_execute(
        $db,
        'INSERT INTO card_dispatches (
            job_key, chat_id, page_no, telegram_message_id, image_path, status, sent_at, last_error
        ) VALUES (
            :job_key, :chat_id, :page_no, :message_id, :image_path, :status, :sent_at, :last_error
        ) ON CONFLICT(job_key, chat_id, page_no) DO UPDATE SET
            telegram_message_id = excluded.telegram_message_id,
            image_path = excluded.image_path,
            status = excluded.status,
            sent_at = excluded.sent_at,
            last_error = excluded.last_error',
        [
            ':job_key' => $jobKey,
            ':chat_id' => $chatId,
            ':page_no' => $pageNo,
            ':message_id' => $messageId,
            ':image_path' => $imagePath,
            ':status' => $status,
            ':sent_at' => $status === 'sent' ? fb_now() : null,
            ':last_error' => $error,
        ]
    );
}

function fb_card_chat_ids(array $config, array $job): array
{
    $routeKey = (string) ($job['route_key'] ?? 'default');
    $sport = (string) ($job['sport'] ?? '');

    if ($routeKey === 'default' || $sport === '') {
        return fb_telegram_default_chat_ids($config);
    }

    return fb_telegram_route_chat_ids($config, $sport);
}

function fb_card_targets(array $config, array $job): array
{
    $routeKey = (string) ($job['route_key'] ?? 'default');
    $sport = (string) ($job['sport'] ?? '');

    if ($routeKey === 'default' || $sport === '') {
        return fb_telegram_default_targets($config);
    }

    return fb_telegram_route_targets($config, $sport);
}

function fb_card_caption_compact_lines(array $lines): array
{
    $out = [];
    $lastBlank = false;

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '') {
            if ($out !== [] && !$lastBlank) {
                $out[] = '';
                $lastBlank = true;
            }

            continue;
        }

        $out[] = $line;
        $lastBlank = false;
    }

    while ($out !== [] && end($out) === '') {
        array_pop($out);
    }

    return $out;
}

function fb_card_caption_trim(string $caption): string
{
    $caption = trim($caption);

    if (strlen($caption) <= 1000) {
        return $caption;
    }

    $trimmed = substr($caption, 0, 997);
    $lastNewline = strrpos($trimmed, "\n");

    if ($lastNewline !== false && $lastNewline > 650) {
        $trimmed = substr($trimmed, 0, $lastNewline);
    }

    return rtrim($trimmed) . '...';
}

function fb_card_caption_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function fb_card_caption_hashtag(string $value): string
{
    $tag = preg_replace('/[^A-Za-z0-9]/', '', str_replace('&', 'and', $value)) ?? '';
    $tag = substr($tag, 0, 32);

    return $tag !== '' ? '#' . $tag : '';
}

function fb_card_caption_tags(string ...$values): string
{
    $tags = [];

    foreach ($values as $value) {
        $tag = fb_card_caption_hashtag($value);

        if ($tag !== '' && !in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }

        if (count($tags) >= 3) {
            break;
        }
    }

    return implode(' ', $tags);
}

function fb_card_caption_date_label(array $item): string
{
    $raw = trim((string) ($item['date_event'] ?? $item['date'] ?? ''));

    if ($raw !== '') {
        try {
            return (new DateTimeImmutable(substr($raw, 0, 10) . ' 12:00:00'))->format('D j M');
        } catch (Throwable) {
            return $raw;
        }
    }

    $startsAt = trim((string) ($item['starts_at'] ?? ''));

    if ($startsAt !== '') {
        try {
            return (new DateTimeImmutable($startsAt))->format('D j M');
        } catch (Throwable) {
            return '';
        }
    }

    return '';
}

function fb_card_caption_time_label(array $item): string
{
    foreach ([$item['event_time'] ?? null, $item['time'] ?? null, $item['time_label'] ?? null] as $value) {
        $raw = trim((string) $value);

        if ($raw === '') {
            continue;
        }

        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $raw, $matches) === 1) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
        }
    }

    $startsAt = trim((string) ($item['starts_at'] ?? ''));

    if ($startsAt !== '') {
        try {
            return (new DateTimeImmutable($startsAt))->format('H:i');
        } catch (Throwable) {
            return '';
        }
    }

    return '';
}

function fb_card_caption_minutes_until(array $item): ?int
{
    $startsAt = trim((string) ($item['starts_at'] ?? ''));

    if ($startsAt === '') {
        return null;
    }

    try {
        $start = new DateTimeImmutable($startsAt);
        $now = new DateTimeImmutable('now', $start->getTimezone());
    } catch (Throwable) {
        return null;
    }

    $diff = $start->getTimestamp() - $now->getTimestamp();

    if ($diff < -60 || $diff > 6 * 60 * 60) {
        return null;
    }

    return max(0, (int) ceil($diff / 60));
}

function fb_card_caption_minute_label(array $match): string
{
    $progress = trim((string) ($match['progress'] ?? ''));

    if ($progress !== '' && preg_match('/^\d+/', $progress, $matches) === 1) {
        return (int) $matches[0] . "'";
    }

    $status = strtoupper(trim((string) ($match['status'] ?? '')));

    if (preg_match('/^\d+/', $status, $matches) === 1) {
        return (int) $matches[0] . "'";
    }

    if (fb_is_half_time_status($status)) {
        return 'HT';
    }

    if (fb_is_full_time_status($status)) {
        return 'FT';
    }

    if (fb_is_live_status($status)) {
        return 'LIVE';
    }

    return '';
}

function fb_card_caption_tv_names(array $item): array
{
    $names = [];
    $details = is_array($item['tv_channel_details'] ?? null) ? $item['tv_channel_details'] : [];

    foreach ($details as $detail) {
        $name = is_array($detail)
            ? trim((string) ($detail['name'] ?? $detail['channel'] ?? ''))
            : trim((string) $detail);

        if ($name !== '') {
            $names[] = $name;
        }
    }

    $channels = is_array($item['tv_channels'] ?? null) ? $item['tv_channels'] : [];

    foreach ($channels as $channel) {
        $name = trim((string) $channel);

        if ($name !== '') {
            $names[] = $name;
        }
    }

    // Also accept common single-field channel labels from provider data
    $possibleFields = [
        'channel',
        'configured_channel_label',
        'tv_channel',
        'tv_channel_name',
    ];

    foreach ($possibleFields as $field) {
        if (!empty($item[$field]) && !is_array($item[$field])) {
            $name = trim((string) $item[$field]);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if (!empty($item[$field]) && is_array($item[$field])) {
            foreach ($item[$field] as $c) {
                $n = trim((string) $c);
                if ($n !== '') {
                    $names[] = $n;
                }
            }
        }
    }

    // If there's a meta block with tv_channels, prefer those too
    if (!empty($item['meta']) && is_array($item['meta'])) {
        $meta = $item['meta'];
        if (!empty($meta['tv_channels']) && is_array($meta['tv_channels'])) {
            foreach ($meta['tv_channels'] as $c) {
                $n = trim((string) $c);
                if ($n !== '') {
                    $names[] = $n;
                }
            }
        }
    }

    return array_values(array_unique($names));
}

function fb_card_caption_tv_label(array $item, string $fallback = 'TV channel TBC'): string
{
    $names = fb_card_caption_tv_names($item);

    if ($names === []) {
        return $fallback;
    }

    return implode(', ', array_slice($names, 0, 3));
}

function fb_card_caption_match_status(array $page, array $match): string
{
    $cardType = (string) ($page['card_type'] ?? '');
    $status = strtoupper(trim((string) ($match['status'] ?? '')));
    $minute = fb_card_caption_minute_label($match);
    $time = fb_card_caption_time_label($match);

    if ($cardType === 'RESULTS_ROUNDUP' || fb_is_full_time_status($status)) {
        return 'Full-time';
    }

    if ($cardType === 'LIVE_NOW' || fb_is_live_status($status) || fb_is_half_time_status($status)) {
        return $minute !== '' ? $minute : 'Live';
    }

    $minutesUntil = fb_card_caption_minutes_until($match);

    if ($cardType === 'KICKOFF_SOON' && $minutesUntil !== null) {
        return 'Kick-off in ' . $minutesUntil . ' minute' . ($minutesUntil === 1 ? '' : 's');
    }

    return $time !== '' ? 'Kick-off ' . $time : 'Kick-off TBC';
}

function fb_card_match_caption(array $page): string
{
    $match = is_array($page['match'] ?? null) ? $page['match'] : [];
    $cardType = (string) ($page['card_type'] ?? '');
    $home = trim((string) ($match['home_team'] ?? 'Home'));
    $away = trim((string) ($match['away_team'] ?? 'Away'));
    $league = trim((string) ($match['league_name'] ?? $page['subtitle'] ?? 'Match'));
    $sport = trim((string) ($match['sport'] ?? $page['sport'] ?? ''));
    $date = fb_card_caption_date_label($match);
    $time = fb_card_caption_time_label($match);
    $timezone = trim((string) ($page['timezone_label'] ?? ''));
    $venue = trim((string) ($match['venue'] ?? ''));
    $tv = fb_card_caption_tv_label($match);
    $status = strtoupper(trim((string) ($match['status'] ?? '')));
    $isLive = $cardType === 'LIVE_NOW' || fb_is_live_status($status) || fb_is_half_time_status($status);
    $isResult = $cardType === 'RESULTS_ROUNDUP' || fb_is_full_time_status($status);
    $showScore = $isLive || $isResult;
    $score = trim((string) ($match['home_score'] ?? '0')) . '-' . trim((string) ($match['away_score'] ?? '0'));
    $headline = $showScore ? "{$home} {$score} {$away}" : "{$home} vs {$away}";
    $leagueLine = ($isLive ? '🔴 LIVE · ' : '⚽ ') . fb_card_caption_upper($league);
    $dateBits = array_values(array_filter([$date, $time], static fn (string $value): bool => $value !== ''));

    if ($timezone !== '') {
        $dateBits[] = $timezone;
    }

    $meta = [
        '⏰ ' . fb_card_caption_match_status($page, $match),
        '📺 ' . $tv,
    ];

    if ($venue !== '') {
        $meta[] = '🏟 ' . $venue;
    }

    $note = match (true) {
        $isResult => '✅ Final score confirmed from the live feed.',
        $isLive => '🔥 Live score and match status are updating.',
        $cardType === 'KICKOFF_SOON' => '🔥 Starting soon. Team and TV details are ready.',
        default => '📈 Fixture details with UK time and broadcast info.',
    };

    $tags = fb_card_caption_tags($league, $home, $away, $sport);
    $lines = [
        $leagueLine,
        $headline,
        '',
        $dateBits !== [] ? '📅 ' . implode(' · ', $dateBits) : '',
        implode(' | ', $meta),
        '',
        $note,
        $tags,
    ];

    return fb_card_caption_trim(implode("\n", fb_card_caption_compact_lines($lines)));
}

function fb_card_tv_event_caption(array $page): string
{
    $event = is_array($page['event'] ?? null) ? $page['event'] : [];
    $cardType = (string) ($page['card_type'] ?? '');
    $home = trim((string) ($event['home_team'] ?? ''));
    $away = trim((string) ($event['away_team'] ?? ''));
    $name = $home !== '' && $away !== ''
        ? "{$home} vs {$away}"
        : trim((string) ($event['event'] ?? $page['title'] ?? 'TV event'));
    $channel = trim((string) ($event['configured_channel_label'] ?? $event['channel'] ?? $page['subtitle'] ?? 'TV'));
    $sport = trim((string) ($event['sport'] ?? $page['sport'] ?? 'Sport'));
    $league = trim((string) ($event['league'] ?? $page['card_subtitle'] ?? ''));
    $date = fb_card_caption_date_label($event);
    $time = fb_card_caption_time_label($event);
    $timezone = trim((string) ($page['timezone_label'] ?? ''));
    $dateBits = array_values(array_filter([$date, $time], static fn (string $value): bool => $value !== ''));

    if ($timezone !== '') {
        $dateBits[] = $timezone;
    }

    $lines = [
        '📺 ' . ($cardType === 'TV_NOW' ? 'WATCH LIVE' : 'TV GUIDE'),
        $name,
        '',
        $dateBits !== [] ? '📅 ' . implode(' · ', $dateBits) : '',
        '📺 ' . $channel,
        trim($sport . ($league !== '' ? ' · ' . $league : '')),
        '',
        fb_card_caption_tags($sport, $league, $channel),
    ];

    return fb_card_caption_trim(implode("\n", fb_card_caption_compact_lines($lines)));
}

function fb_card_caption(array $page): string
{
    if (($page['kind'] ?? '') === 'match' && is_array($page['match'] ?? null)) {
        return fb_card_match_caption($page);
    }

    if (($page['kind'] ?? '') === 'tv_event' && is_array($page['event'] ?? null)) {
        return fb_card_tv_event_caption($page);
    }

    $title = trim((string) ($page['title'] ?? 'Sports Card'));
    $subtitle = trim((string) ($page['subtitle'] ?? ''));
    $pageNo = (int) ($page['page'] ?? 1);
    $pageCount = (int) ($page['page_count'] ?? 1);
    $lines = [$title];

    if ($subtitle !== '') {
        $lines[] = $subtitle;
    }

    if ($pageCount > 1) {
        $lines[] = 'Page ' . $pageNo . '/' . $pageCount;
    }

    return implode("\n", $lines);
}

function fb_card_action_button(SQLite3 $db, string $label, string $feed, array $payload = []): ?array
{
    $callbackData = fb_register_follow_button($db, 'feed', '', $feed, ['source' => 'match_card'] + $payload);

    if ($callbackData === null) {
        return null;
    }

    return [
        'text' => $label,
        'callback_data' => $callbackData,
    ];
}

function fb_card_reply_markup(array $config, SQLite3 $db, array $page, array $target = []): array
{
    $cardType = (string) ($page['card_type'] ?? '');
    $kind = (string) ($page['kind'] ?? '');
    $match = is_array($page['match'] ?? null) ? $page['match'] : [];
    $event = is_array($page['event'] ?? null) ? $page['event'] : [];
    $sport = trim((string) ($match['sport'] ?? $event['sport'] ?? $page['sport'] ?? ''));
    $eventId = trim((string) ($match['event_id'] ?? $event['event_id'] ?? ''));
    $payload = array_filter([
        'card_type' => $cardType,
        'sport' => $sport,
        'event_id' => $eventId,
    ], static fn (mixed $value): bool => trim((string) $value) !== '');

    if ($kind === 'tv_event' || in_array($cardType, ['TV_GUIDE', 'TV_NOW'], true)) {
        $actions = [
            ['TV Guide', 'TV guide'],
            ['Fixtures', 'Fixtures'],
            ['Live Scores', 'Live scores'],
        ];
    } elseif ($cardType === 'RESULTS_ROUNDUP') {
        $actions = [
            ['Match Stats', 'Match stats'],
            ['Table', 'Standings'],
            ['Highlights', 'Highlights'],
        ];
    } elseif ($cardType === 'LIVE_NOW') {
        $actions = [
            ['Live Scores', 'Live scores'],
            ['Match Stats', 'Match stats'],
            ['Live Table', 'Standings'],
        ];
    } else {
        $actions = [
            ['Lineups', 'Lineups'],
            ['Stats', 'Match stats'],
            ['Watch Live', 'TV guide'],
        ];
    }

    $rows = [];
    $row = [];

    foreach ($actions as [$label, $feed]) {
        $button = fb_card_action_button($db, $label, $feed, $payload);

        if ($button === null) {
            continue;
        }

        $row[] = $button;
    }

    if ($row !== []) {
        $rows[] = $row;
    }

    $topicUrl = fb_telegram_topic_url((string) ($target['chat_id'] ?? ''), $target['message_thread_id'] ?? null);
    if ($topicUrl !== '') {
        $rows[] = [[
            'text' => 'Open Topic',
            'url' => $topicUrl,
        ]];
    }

    return $rows !== [] ? ['inline_keyboard' => $rows] : [];
}

function fb_dispatch_matchday_card_jobs(array $config, SQLite3 $db, bool $dryRun = false, array $dryRunJobs = []): array
{
    $summary = [
        'jobs' => 0,
        'pages' => 0,
        'sent' => 0,
        'failed' => 0,
        'images' => [],
        'messages' => [],
    ];

    $jobs = $dryRun
        ? $dryRunJobs
        : fb_fetch_card_jobs($db, (int) ($config['cards']['max_sends_per_run'] ?? 12));

    $maxPages = max(1, (int) ($config['cards']['max_pages_per_run'] ?? 12));
    $pagesRendered = 0;

    foreach ($jobs as $job) {
        $payload = $job['payload'] ?? null;

        if ($payload === null && !empty($job['payload_json'])) {
            $decoded = json_decode((string) $job['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : ['pages' => []];
        }

        $pages = array_values($payload['pages'] ?? []);

        if ($pages === []) {
            continue;
        }

        $summary['jobs']++;
        $summary['pages'] += count($pages);

        if ($dryRun) {
            $summary['messages'][] = sprintf(
                '[CARD %s] %s route=%s sport=%s pages=%d',
                (string) ($job['card_type'] ?? ''),
                (string) ($job['job_key'] ?? ''),
                (string) ($job['route_key'] ?? 'default'),
                (string) ($job['sport'] ?? 'all'),
                count($pages)
            );
            continue;
        }

        $jobKey = (string) $job['job_key'];
        fb_update_card_job_status($db, $jobKey, 'rendering');
        $targets = fb_card_targets($config, $job);
        $jobFailed = false;

        foreach ($pages as $idx => $page) {
            if ($pagesRendered >= $maxPages) {
                $jobFailed = true;
                break;
            }

            $pageNo = (int) ($page['page'] ?? ($idx + 1));

            try {
                $imagePath = fb_generate_matchday_card_image($config, $page);
                $summary['images'][] = $imagePath;
                $pagesRendered++;
            } catch (Throwable $error) {
                $jobFailed = true;
                fb_update_card_job_status($db, $jobKey, 'failed', $error->getMessage());
                break;
            }

            foreach ($targets as $target) {
                $chatKey = fb_telegram_target_key($target);

                if (fb_card_dispatch_sent($db, $jobKey, $chatKey, $pageNo)) {
                    continue;
                }

                try {
                    $options = fb_card_reply_markup($config, $db, $page, $target);
                    $result = fb_telegram_send_photo_to_outbox(
                        $config,
                        $db,
                        'card:' . $jobKey . ':p' . $pageNo,
                        (string) $target['chat_id'],
                        $imagePath,
                        fb_card_caption($page),
                        $jobKey,
                        $target['message_thread_id'] ?? null,
                        $options
                    );
                    if (($result['ok'] ?? false) !== true) {
                        throw new RuntimeException((string) ($result['error'] ?? 'Telegram card send failed.'));
                    }
                    $messageId = (string) ($result['result']['message_id'] ?? '');
                    fb_record_card_dispatch($db, $jobKey, $chatKey, $pageNo, 'sent', $messageId, $imagePath, null);
                    $summary['sent']++;
                } catch (Throwable $error) {
                    $jobFailed = true;
                    fb_record_card_dispatch($db, $jobKey, $chatKey, $pageNo, 'failed', null, $imagePath, $error->getMessage());
                    $summary['failed']++;
                }
            }
        }

        fb_update_card_job_status($db, $jobKey, $jobFailed ? 'failed' : 'sent', $jobFailed ? 'One or more card pages failed to send.' : null);
    }

    return $summary;
}

function fb_detect_kickoff_reminder_alerts(array $config, SQLite3 $db): array
{
    if (!(bool) $config['alerts']['send_kickoff_reminder']) {
        return [];
    }

    if (!empty($config['cards']['bursts_enabled']) && fb_card_type_enabled($config, 'kickoff_soon')) {
        return [];
    }

    $reminderMinutes = (int) $config['alerts']['kickoff_reminder_minutes'];
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $cutoff = $now->modify("+{$reminderMinutes} minutes");
    $alerts = [];

    foreach (fb_coverage_schedule_leagues($config, $db) as $coverageLeague) {
        $leagueId = (string) ($coverageLeague['league_id'] ?? '');

        if ($leagueId === '') {
            continue;
        }

        try {
            $payload = fb_thesportsdb_get(
                $config,
                $db,
                '/schedule/next/league/' . rawurlencode((string) $leagueId),
                300
            );

            $events = fb_extract_list($payload, ['schedule', 'events', 'next']);

            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $eventTime = fb_event_datetime($event, $tz);

                if ($eventTime === null) {
                    continue;
                }

                $minutesUntilKickoff = (int) (($eventTime->getTimestamp() - $now->getTimestamp()) / 60);

                if ($minutesUntilKickoff < 0 || $minutesUntilKickoff > $reminderMinutes) {
                    continue;
                }

                $event = fb_with_local_event_time($event, $eventTime);
                $event['_allowedLeagueId'] = $leagueId;
                $event['_allowedLeagueName'] = (string) ($coverageLeague['league_name'] ?? $event['strLeague'] ?? '');
                $event['_allowedSport'] = (string) ($coverageLeague['sport'] ?? $event['strSport'] ?? 'Soccer');
                $league = fb_coverage_match_allowed($config, $event);

                if ($league === null) {
                    continue;
                }

                $event['_allowedLeagueId'] = $league['id'];
                $event['_allowedLeagueName'] = $league['name'];
                $event['_allowedSport'] = $league['sport'];
                $match = fb_normalize_match($event);
                $reminderKey = 'kickoff_reminder:' . $match['event_id'];

                if (fb_was_alert_sent($db, $reminderKey)) {
                    continue;
                }

                $match = fb_enrich_match_team_badges($config, $db, $match);
                $leagueArtwork = fb_fetch_league_artwork($config, $db, $match['league_id']);
                $match['league_logo'] = $leagueArtwork['logo'] ?: ($leagueArtwork['badge'] ?? '');

                $tvChannels = fb_tv_channels_for_event_ids(
                    $config,
                    $db,
                    [$match['event_id']]
                );
                $matchTvChannels = $tvChannels[$match['event_id']] ?? [];

                // Per-team alerts: one for home, one for away
                foreach (['home', 'away'] as $side) {
                    $teamKey = $reminderKey . ':' . $side;
                    if (fb_was_alert_sent($db, $teamKey)) {
                        continue;
                    }

                    $alerts[] = fb_build_base_alert('KICKOFF_REMINDER', $teamKey, $match, [
                        'minute' => 0,
                        'event_time' => $match['event_time'],
                        'league_name' => $match['league_name'],
                        'tv_channels' => $matchTvChannels,
                        'minutes_until' => $minutesUntilKickoff,
                        'primary_side' => $side,
                    ]);
                }
            }
        } catch (Throwable $error) {
            fb_log('warning', 'Could not fetch upcoming matches for kickoff reminder', [
                'league_id' => $leagueId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    usort($alerts, static function (array $a, array $b): int {
        return ($a['meta']['minutes_until'] ?? 0) <=> ($b['meta']['minutes_until'] ?? 0);
    });

    return $alerts;
}

function fb_caption_for_alert(array $alert): string
{
    $match = $alert['match'];
    $score = sprintf('%s %d-%d %s', $match['home_team'], $match['home_score'], $match['away_score'], $match['away_team']);
    $sport = (string) ($match['sport'] ?? 'Sport');
    $sportLabel = (string) ($alert['meta']['sport_label'] ?? $sport);
    $startLabel = strtoupper((string) ($alert['meta']['start_label'] ?? ($sport . ' started')));
    $updateLabel = strtoupper((string) ($alert['meta']['update_label'] ?? 'Score update'));
    $periodLabel = strtoupper((string) ($alert['meta']['period_label'] ?? 'Update'));
    $statusLabel = (string) ($alert['meta']['status_label'] ?? ($alert['meta']['status'] ?? $match['status']));
    $finalLabel = strtoupper((string) ($alert['meta']['final_label'] ?? 'Full-time'));

    return match ($alert['type']) {
        'GOAL' => sprintf("GOAL: %s\n%s", $alert['meta']['scorer'] ?? 'Goal', $score),
        'KICK_OFF' => sprintf("KICK-OFF\n%s", $score),
        'MATCH_START' => sprintf("%s\n%s", $startLabel, $score),
        'SCORE_UPDATE' => sprintf("%s %s\n%s", strtoupper($sportLabel), $updateLabel, $score),
        'PERIOD_CHANGE' => sprintf("%s %s: %s\n%s", strtoupper($sportLabel), $periodLabel, $statusLabel, $score),
        'HALF_TIME' => sprintf("HALF-TIME\n%s", $score),
        'FULL_TIME' => sprintf("%s\n%s", $finalLabel, $score),
        'RED_CARD' => sprintf("RED CARD: %s\n%s", $alert['meta']['player'] ?? 'Player unavailable', $score),
        'YELLOW_CARD' => sprintf("YELLOW CARD: %s\n%s", $alert['meta']['player'] ?? 'Player unavailable', $score),
        'SUBSTITUTION' => sprintf("SUBSTITUTION: %s\n%s", $alert['meta']['player_on'] ?? 'Substitution', $score),
        'MATCH_PREVIEW' => trim(sprintf(
            "UPCOMING: %s vs %s\n%s",
            $match['home_team'],
            $match['away_team'],
            !empty($alert['meta']['tv_channels']) ? 'TV: ' . implode(', ', $alert['meta']['tv_channels']) : ''
        )),
        'KICKOFF_REMINDER' => (function () use ($alert, $match) {
            $side = $alert['meta']['primary_side'] ?? 'home';
            $primaryTeam = $side === 'home' ? $match['home_team'] : $match['away_team'];
            $opponent = $side === 'home' ? $match['away_team'] : $match['home_team'];
            $minutes = (int) ($alert['meta']['minutes_until'] ?? 10);
            $lines = ["{$primaryTeam} - starts in {$minutes} min"];
            $lines[] = "vs {$opponent}";
            if (!empty($alert['meta']['event_time'])) {
                $lines[] = 'Kick-off ' . $alert['meta']['event_time'];
            }
            if (!empty($alert['meta']['tv_channels'])) {
                $lines[] = 'TV: ' . implode(', ', $alert['meta']['tv_channels']);
            }
            return trim(implode("\n", $lines));
        })(),
        default => $score,
    };
}

function fb_enrich_alert_assets(array $config, SQLite3 $db, array $alert): array
{
    $alert['match'] = fb_enrich_match_team_badges($config, $db, $alert['match']);
    $leagueArtwork = fb_fetch_league_artwork($config, $db, $alert['match']['league_id']);
    $alert['match']['league_logo'] = $leagueArtwork['logo'] ?: ($leagueArtwork['badge'] ?? '');

    $playerId = $alert['meta']['player_id'] ?? '';

    if ($playerId !== '') {
        $playerArtwork = fb_fetch_player_artwork($config, $db, $playerId);
        $alert['meta']['player_image'] = $playerArtwork['render'] ?: ($playerArtwork['thumb'] ?? '');
    }

    return $alert;
}
