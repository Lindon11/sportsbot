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
        'extra_chat_ids' => $csv(env('SPORTSBOT_TELEGRAM_EXTRA_CHAT_IDS', $legacyEnv('TELEGRAM_EXTRA_CHAT_IDS', ''))),
        'parse_mode' => env('SPORTSBOT_TELEGRAM_PARSE_MODE', 'HTML'),
        'disable_notification' => env('SPORTSBOT_TELEGRAM_DISABLE_NOTIFICATION', $legacyEnv('BOT_TELEGRAM_DISABLE_NOTIFICATION', false)),
    ],

    'coverage' => [
        'enabled_sports' => $csv(env('SPORTSBOT_ENABLED_SPORTS', 'Soccer')),
        'allowed_league_ids' => $csv(env('SPORTSBOT_ALLOWED_LEAGUE_IDS', $legacyEnv('BOT_ALLOWED_LEAGUE_IDS', ''))),
        'max_live_matches_per_run' => (int) env('SPORTSBOT_MAX_LIVE_MATCHES_PER_RUN', $legacyEnv('BOT_MAX_LIVE_MATCHES_PER_RUN', 75)),
    ],

    'features' => [
        'send_score_updates' => env('SPORTSBOT_SEND_SCORE_UPDATES', true),
        'send_status_updates' => env('SPORTSBOT_SEND_STATUS_UPDATES', true),
        'send_first_seen_live_alerts' => env('SPORTSBOT_SEND_FIRST_SEEN_LIVE_ALERTS', false),
    ],
];
