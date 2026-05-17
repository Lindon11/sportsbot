<?php

$legacyEnvPath = base_path('footballbot/config/footballbot.env');

$legacyEnv = static function (string $key, mixed $default = null) use ($legacyEnvPath): mixed {
    if (!is_file($legacyEnvPath)) {
        return $default;
    }

    foreach (file($legacyEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        if (trim($name) !== $key) {
            continue;
        }

        return trim(trim($value), "\"'");
    }

    return $default;
};

$csv = static function (mixed $value): array {
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), static fn (string $item): bool => $item !== ''));
    }

    return array_values(array_filter(
        array_map('trim', explode(',', (string) $value)),
        static fn (string $item): bool => $item !== ''
    ));
};

$legacyDefaultLeagueIds = [
    '4328', // English Premier League
    '4329', // EFL Championship
    '4396', // League One
    '4397', // League Two
    '4482', // FA Cup
    '4570', // EFL Cup
    '4330', // Scottish Premiership
    '4395', // Scottish Championship
    '4669', // Scottish League One
    '4670', // Scottish League Two
    '4723', // Scottish FA Cup
    '4888', // Scottish League Cup
    '4480', // UEFA Champions League
    '4481', // UEFA Europa League
    '4889', // UEFA Womens Champions League
];

$defaultTvChannels = [
    'Sky Sports Main Event',
    'Sky Sports Premier League',
    'Sky Sports Football',
    'Sky Sports Cricket',
    'Sky Sports Golf',
    'Sky Sports F1',
    'TNT Sports 1',
    'TNT Sports 2',
    'TNT Sports 3',
    'TNT Sports 4',
    'BBC One',
    'BBC Two',
    'ITV1',
    'Channel 4',
    'Premier Sports 1',
    'DAZN UK',
];

return [
    'enabled' => env('SPORTSBOT_ENABLED', true),
    'send_messages' => env('SPORTSBOT_SEND_MESSAGES', false),

    'schedule' => [
        'enabled' => env('SPORTSBOT_SCHEDULE_ENABLED', false),
        'frequency' => env('SPORTSBOT_SCHEDULE_FREQUENCY', 'everyTwoMinutes'),
    ],

    'provider' => [
        'name' => env('SPORTSBOT_PROVIDER', 'thesportsdb'),
        'base_url' => env('SPORTSBOT_THESPORTSDB_BASE_URL', 'https://www.thesportsdb.com/api/v2/json'),
        'api_key' => env('SPORTSBOT_THESPORTSDB_API_KEY', $legacyEnv('THESPORTSDB_API_KEY')),
        'timeout' => (int) env('SPORTSBOT_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('SPORTSBOT_HTTP_CONNECT_TIMEOUT', 10),
        'live_score_cache_ttl' => (int) env('SPORTSBOT_LIVE_SCORE_CACHE_TTL', $legacyEnv('BOT_LIVESCORE_CACHE_TTL', 75)),
    ],

    'telegram' => [
        'bot_token' => env('SPORTSBOT_TELEGRAM_BOT_TOKEN', $legacyEnv('TELEGRAM_BOT_TOKEN')),
        'chat_id' => env('SPORTSBOT_TELEGRAM_CHAT_ID', $legacyEnv('TELEGRAM_CHAT_ID')),
        'message_thread_id' => env('SPORTSBOT_TELEGRAM_MESSAGE_THREAD_ID', $legacyEnv('TELEGRAM_MESSAGE_THREAD_ID')),
        'extra_chat_ids' => $csv(env('SPORTSBOT_TELEGRAM_EXTRA_CHAT_IDS', $legacyEnv('TELEGRAM_EXTRA_CHAT_IDS', ''))),
        'parse_mode' => env('SPORTSBOT_TELEGRAM_PARSE_MODE', 'HTML'),
        'disable_notification' => env('SPORTSBOT_TELEGRAM_DISABLE_NOTIFICATION', $legacyEnv('BOT_TELEGRAM_DISABLE_NOTIFICATION', false)),
        'webhook_enabled' => env('SPORTSBOT_TELEGRAM_WEBHOOK_ENABLED', false),
        'webhook_secret' => env('SPORTSBOT_TELEGRAM_WEBHOOK_SECRET', ''),
    ],

    'legacy' => [
        'state_db' => env('SPORTSBOT_LEGACY_STATE_DB', base_path('footballbot/cache/state.sqlite')),
    ],

    'coverage' => [
        'enabled_sports' => $csv(env('SPORTSBOT_ENABLED_SPORTS', 'Soccer')),
        'allowed_league_ids' => $csv(env('SPORTSBOT_ALLOWED_LEAGUE_IDS', $legacyEnv('BOT_ALLOWED_LEAGUE_IDS', ''))),
        'max_live_matches_per_run' => (int) env('SPORTSBOT_MAX_LIVE_MATCHES_PER_RUN', $legacyEnv('BOT_MAX_LIVE_MATCHES_PER_RUN', 75)),
    ],

    'fixtures_today' => [
        'default_league_ids' => $legacyDefaultLeagueIds,
        'max_per_sport' => (int) env('SPORTSBOT_FIXTURES_TODAY_MAX_PER_SPORT', 5),
    ],

    'live_now' => [
        'max_per_sport' => (int) env('SPORTSBOT_LIVE_NOW_MAX_PER_SPORT', 8),
    ],

    'tv' => [
        'enabled' => env('SPORTSBOT_TV_ENABLED', $legacyEnv('BOT_TV_ENABLED', true)),
        'channels' => $csv(env('SPORTSBOT_TV_CHANNELS', $legacyEnv('BOT_TV_CHANNELS', implode(',', $defaultTvChannels)))),
        'sports' => $csv(env('SPORTSBOT_TV_SPORTS', $legacyEnv('BOT_TV_SPORTS', 'Soccer,Basketball,Baseball,MMA,Tennis,Rugby'))),
        'lookahead_hours' => (int) env('SPORTSBOT_TV_LOOKAHEAD_HOURS', $legacyEnv('BOT_TV_LOOKAHEAD_HOURS', 24)),
        'max_events_per_channel' => (int) env('SPORTSBOT_TV_MAX_EVENTS_PER_CHANNEL', $legacyEnv('BOT_TV_MAX_EVENTS_PER_CHANNEL', 20)),
        'max_per_channel' => (int) env('SPORTSBOT_TV_GUIDE_MAX_PER_CHANNEL', 8),
        'show_empty_channels' => env('SPORTSBOT_TV_GUIDE_SHOW_EMPTY_CHANNELS', false),
        'cache_ttl' => (int) env('SPORTSBOT_TV_CACHE_TTL', $legacyEnv('BOT_TV_CACHE_TTL', 900)),
    ],

    'publishing' => [
        'fixtures_today' => [
            'enabled' => env('SPORTSBOT_FIXTURES_TODAY_SCHEDULE_ENABLED', false),
            'time' => env('SPORTSBOT_FIXTURES_TODAY_SCHEDULE_TIME', $legacyEnv('BOT_DAILY_CARD_TIME', '08:00')),
        ],
        'tv_guide' => [
            'enabled' => env('SPORTSBOT_TV_GUIDE_SCHEDULE_ENABLED', false),
            'time' => env('SPORTSBOT_TV_GUIDE_SCHEDULE_TIME', $legacyEnv('BOT_TV_DAILY_ALERT_TIME', '08:00')),
        ],
        'live_now' => [
            'enabled' => env('SPORTSBOT_LIVE_NOW_SCHEDULE_ENABLED', false),
            'frequency' => env('SPORTSBOT_LIVE_NOW_SCHEDULE_FREQUENCY', 'everyFiveMinutes'),
        ],
    ],

    'features' => [
        'send_score_updates' => env('SPORTSBOT_SEND_SCORE_UPDATES', true),
        'send_status_updates' => env('SPORTSBOT_SEND_STATUS_UPDATES', true),
        'send_first_seen_live_alerts' => env('SPORTSBOT_SEND_FIRST_SEEN_LIVE_ALERTS', false),
    ],
];
