<?php

declare(strict_types=1);

if (!function_exists('footballbot_env')) {
    function footballbot_load_env_file(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = stripcslashes(substr($value, 1, -1));
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    function footballbot_env(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    function footballbot_env_list(string $name, string $default = ''): array
    {
        $raw = footballbot_env($name, $default) ?? '';
        $items = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
    }

    function footballbot_env_bool(string $name, bool $default): bool
    {
        $value = footballbot_env($name);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    function footballbot_env_int(string $name, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = footballbot_env($name);
        $int = is_numeric((string) $value) ? (int) $value : $default;

        return max($min, min($max, $int));
    }

    function footballbot_env_json(string $name, array $default = []): array
    {
        $value = footballbot_env($name);

        if ($value === null) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }
}

$root = __DIR__;
footballbot_load_env_file($root . '/config/footballbot.env');

$defaultSports = [
    'Soccer',
    'Rugby',
    'Rugby Union',
    'Rugby League',
    'Cricket',
    'Tennis',
    'Darts',
    'Snooker',
    'Golf',
    'Motorsport',
    'Formula 1',
    'Boxing',
    'MMA',
    'American Football',
    'Basketball',
    'Baseball',
    'Ice Hockey',
];
$enabledSports = footballbot_env_list('BOT_ENABLED_SPORTS', implode(',', $defaultSports));
$enabledSports = $enabledSports === [] ? $defaultSports : $enabledSports;
$coverageCountries = footballbot_env_list(
    'BOT_COVERAGE_COUNTRIES',
    'england,scotland,wales,northern_ireland,ireland,united_kingdom,europe,international,world,united_states'
);
$renderEngine = strtolower((string) footballbot_env('BOT_RENDER_ENGINE', 'auto'));
if (!in_array($renderEngine, ['auto', 'puppeteer', 'gd'], true)) {
    $renderEngine = 'auto';
}

$availableLeagues = [
    '4328' => [
        'name' => 'English Premier League',
        'aliases' => ['english premier league', 'premier league', 'epl'],
    ],
    '4329' => [
        'name' => 'EFL Championship',
        'aliases' => ['english league championship', 'efl championship', 'championship'],
    ],
    '4396' => [
        'name' => 'League One',
        'aliases' => ['english league 1', 'english league one', 'efl league one', 'league one'],
    ],
    '4397' => [
        'name' => 'League Two',
        'aliases' => ['english league 2', 'english league two', 'efl league two', 'league two'],
    ],
    '4482' => [
        'name' => 'FA Cup',
        'aliases' => ['fa cup', 'english fa cup'],
    ],
    '4570' => [
        'name' => 'EFL Cup',
        'aliases' => ['efl cup', 'english league cup', 'carabao cup'],
    ],
    '4330' => [
        'name' => 'Scottish Premiership',
        'aliases' => ['scottish premier league', 'scottish premiership'],
    ],
    '4395' => [
        'name' => 'Scottish Championship',
        'aliases' => ['scottish championship'],
    ],
    '4669' => [
        'name' => 'Scottish League One',
        'aliases' => ['scottish league 1', 'scottish league one'],
    ],
    '4670' => [
        'name' => 'Scottish League Two',
        'aliases' => ['scottish league 2', 'scottish league two'],
    ],
    '4723' => [
        'name' => 'Scottish FA Cup',
        'aliases' => ['scottish fa cup'],
    ],
    '4888' => [
        'name' => 'Scottish League Cup',
        'aliases' => ['scottish league cup'],
    ],
    '4480' => [
        'name' => 'UEFA Champions League',
        'aliases' => ['uefa champions league', 'champions league'],
    ],
    '4481' => [
        'name' => 'UEFA Europa League',
        'aliases' => ['uefa europa league', 'europa league'],
    ],
    '4889' => [
        'name' => 'UEFA Womens Champions League',
        'aliases' => ['uefa womens champions league', "uefa women's champions league"],
    ],
];
$legacyAllowedLeagueIds = footballbot_env_list('BOT_ALLOWED_LEAGUE_IDS', implode(',', array_keys($availableLeagues)));
$allowedLeagueIds = footballbot_env_list('BOT_ALLOWED_LEAGUE_IDS', implode(',', array_keys($availableLeagues)));
$allowedLeagueIds = array_values(array_intersect($allowedLeagueIds, array_keys($availableLeagues)));
$allowedLeagues = array_intersect_key($availableLeagues, array_flip($allowedLeagueIds));
$enabledLeagueIds = footballbot_env_list('BOT_ENABLED_LEAGUE_IDS');

if ($allowedLeagues === []) {
    $allowedLeagues = $availableLeagues;
}

return [
    'app' => [
        'root' => $root,
        'timezone' => footballbot_env('BOT_TIMEZONE', 'Europe/London'),
        'log_file' => $root . '/logs/bot.log',
        'debug' => filter_var(footballbot_env('BOT_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    'telegram' => [
        'bot_token' => footballbot_env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => footballbot_env('TELEGRAM_CHAT_ID'),
        'message_thread_id' => footballbot_env_int('TELEGRAM_MESSAGE_THREAD_ID', 0, 0),
        'extra_chat_ids' => array_filter(array_map('trim', explode(',', footballbot_env('TELEGRAM_EXTRA_CHAT_IDS', '')))),
        'routes' => footballbot_env_json('BOT_TELEGRAM_ROUTES_JSON', []),
        'updates_enabled' => footballbot_env_bool('TELEGRAM_UPDATES_ENABLED', true),
        'api_base' => 'https://api.telegram.org',
        'timeout' => 25,
        'connect_timeout' => 10,
        'disable_notification' => footballbot_env_bool('BOT_TELEGRAM_DISABLE_NOTIFICATION', false),
    ],

    'thesportsdb' => [
        'api_key' => footballbot_env('THESPORTSDB_API_KEY'),
        'base_url' => 'https://www.thesportsdb.com/api/v2/json',
        'timeout' => 25,
        'connect_timeout' => 10,
        'min_request_interval_ms' => footballbot_env_int('BOT_API_MIN_INTERVAL_MS', 350, 0),
        'livescore_cache_ttl' => footballbot_env_int('BOT_LIVESCORE_CACHE_TTL', 75, 0),
        'timeline_cache_ttl' => footballbot_env_int('BOT_TIMELINE_CACHE_TTL', 45, 0),
        'lookup_cache_ttl' => footballbot_env_int('BOT_LOOKUP_CACHE_TTL', 604800, 0),
        'tv_cache_ttl' => footballbot_env_int('BOT_TV_CACHE_TTL', 900, 0),
        'max_live_matches_per_run' => footballbot_env_int('BOT_MAX_LIVE_MATCHES_PER_RUN', 25, 1, 100),
        'max_live_matches_per_sport' => footballbot_env_int('BOT_MAX_LIVE_MATCHES_PER_SPORT', 8, 1, 100),
    ],

    'paths' => [
        'assets' => $root . '/assets',
        'cache' => $root . '/cache',
        'image_cache' => $root . '/cache/images',
        'generated' => $root . '/generated',
        'fonts' => $root . '/fonts',
        'logs' => $root . '/logs',
        'state_db' => $root . '/cache/state.sqlite',
        'api_cache_lock' => $root . '/cache/api-rate.lock',
        'run_lock' => $root . '/cache/check-live.lock',
    ],

    'leagues' => [
        'available' => $availableLeagues,
        'allowed' => $allowedLeagues,
    ],

    'coverage' => [
        'preset' => footballbot_env('BOT_COVERAGE_PRESET', 'uk_sports'),
        'available_sports' => $defaultSports,
        'enabled_sports' => $enabledSports,
        'enabled_league_ids' => $enabledLeagueIds,
        'legacy_soccer_league_ids' => array_values(array_intersect($legacyAllowedLeagueIds, array_keys($availableLeagues))),
        'countries' => $coverageCountries,
        'auto_enable_discovered_leagues' => footballbot_env_bool('BOT_AUTO_ENABLE_DISCOVERED_LEAGUES', true),
        'max_schedule_leagues' => footballbot_env_int('BOT_MAX_SCHEDULE_LEAGUES', 80, 1, 500),
    ],

    'alerts' => [
        'kickoff_progress_max' => footballbot_env_int('BOT_KICKOFF_PROGRESS_MAX', 3, 0, 15),
        'allow_first_seen_goal_alerts' => footballbot_env_bool('BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS', false),
        'allow_first_seen_full_time_alerts' => footballbot_env_bool('BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS', false),
        'allow_first_seen_red_card_alerts' => footballbot_env_bool('BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS', false),
        'send_red_cards' => footballbot_env_bool('BOT_SEND_RED_CARDS', true),
        'send_yellow_cards' => footballbot_env_bool('BOT_SEND_YELLOW_CARDS', true),
        'allow_first_seen_yellow_card_alerts' => footballbot_env_bool('BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS', false),
        'send_substitutions' => footballbot_env_bool('BOT_SEND_SUBSTITUTIONS', true),
        'allow_first_seen_substitution_alerts' => footballbot_env_bool('BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS', false),
        'send_match_starts' => footballbot_env_bool('BOT_SEND_MATCH_STARTS', false),
        'send_score_updates' => footballbot_env_bool('BOT_SEND_SCORE_UPDATES', false),
        'send_period_changes' => footballbot_env_bool('BOT_SEND_PERIOD_CHANGES', false),
        'send_match_previews' => footballbot_env_bool('BOT_SEND_MATCH_PREVIEWS', true),
        'preview_hours_ahead' => footballbot_env_int('BOT_PREVIEW_HOURS_AHEAD', 4, 1, 72),
        'send_kickoff_reminder' => footballbot_env_bool('BOT_SEND_KICKOFF_REMINDER', true),
        'kickoff_reminder_minutes' => footballbot_env_int('BOT_KICKOFF_REMINDER_MINUTES', 10, 1, 120),
        'send_daily_card' => footballbot_env_bool('BOT_SEND_DAILY_CARD', true),
        'daily_card_time' => footballbot_env('BOT_DAILY_CARD_TIME', '08:00'),
        'daily_card_send_image' => footballbot_env_bool('BOT_DAILY_CARD_SEND_IMAGE', true),
        'error_alert_chat_id' => footballbot_env('TELEGRAM_ERROR_CHAT_ID'),
    ],

    'cards' => [
        'bursts_enabled' => footballbot_env_bool('BOT_CARD_BURSTS_ENABLED', true),
        'route_mode' => footballbot_env('BOT_CARD_ROUTE_MODE', 'smart'),
        'types_enabled' => footballbot_env_list('BOT_CARD_TYPES_ENABLED', 'kickoff_soon,live_now,results,tv_now'),
        'burst_min_fixtures' => footballbot_env_int('BOT_CARD_BURST_MIN_FIXTURES', 3, 1, 100),
        'burst_min_live' => footballbot_env_int('BOT_CARD_BURST_MIN_LIVE', 2, 1, 100),
        'burst_min_results' => footballbot_env_int('BOT_CARD_BURST_MIN_RESULTS', 3, 1, 100),
        'burst_cooldown_minutes' => footballbot_env_int('BOT_CARD_BURST_COOLDOWN_MINUTES', 60, 5, 1440),
        'max_items_per_type' => footballbot_env_int('BOT_CARD_MAX_ITEMS_PER_TYPE', 4, 1, 50),
        'max_pages_per_run' => footballbot_env_int('BOT_CARD_MAX_PAGES_PER_RUN', 12, 1, 60),
        'max_sends_per_run' => footballbot_env_int('BOT_CARD_MAX_SENDS_PER_RUN', 12, 1, 100),
    ],

    'content' => [
        'packs_enabled' => footballbot_env_list('BOT_CONTENT_PACKS_ENABLED', 'live_now,kickoff_soon,results,tv_now'),
    ],

    'customer' => [
        'guide_enabled' => footballbot_env_bool('BOT_CUSTOMER_GUIDE_ENABLED', true),
        'guide_time' => footballbot_env('BOT_CUSTOMER_GUIDE_TIME', '09:00'),
        'guide_lookahead_hours' => footballbot_env_int('BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS', 24, 1, 168),
        'team_watchlist' => footballbot_env_list('BOT_TEAM_WATCHLIST'),
        'player_watchlist' => footballbot_env_list('BOT_PLAYER_WATCHLIST'),
        'follow_buttons_enabled' => footballbot_env_bool('BOT_FOLLOW_BUTTONS_ENABLED', true),
        'max_follow_buttons' => footballbot_env_int('BOT_MAX_FOLLOW_BUTTONS', 8, 0, 24),
    ],

    'health' => [
        'alerts_enabled' => footballbot_env_bool('BOT_HEALTH_ALERTS_ENABLED', true),
        'alert_time' => footballbot_env('BOT_HEALTH_ALERT_TIME', '07:30'),
    ],

    'tv' => [
        'enabled' => footballbot_env_bool('BOT_TV_ENABLED', true),
        'channels' => footballbot_env_list('BOT_TV_CHANNELS'),
        'sports' => footballbot_env_list('BOT_TV_SPORTS', implode(',', $enabledSports)),
        'discovery_countries' => footballbot_env_list('BOT_TV_DISCOVERY_COUNTRIES', 'united_kingdom,ireland'),
        'discovery_days_ahead' => footballbot_env_int('BOT_TV_DISCOVERY_DAYS_AHEAD', 7, 1, 31),
        'daily_alerts' => footballbot_env_bool('BOT_TV_DAILY_ALERTS', true),
        'send_image' => footballbot_env_bool('BOT_TV_SEND_IMAGE', true),
        'daily_alert_time' => footballbot_env('BOT_TV_DAILY_ALERT_TIME', '08:00'),
        'lookahead_hours' => footballbot_env_int('BOT_TV_LOOKAHEAD_HOURS', 24, 1, 168),
        'include_in_match_previews' => footballbot_env_bool('BOT_TV_INCLUDE_IN_PREVIEWS', true),
        'preview_require_tv' => footballbot_env_bool('BOT_TV_PREVIEW_REQUIRE_TV', false),
        'football_only' => footballbot_env_bool('BOT_TV_FOOTBALL_ONLY', false),
        'max_events_per_channel' => footballbot_env_int('BOT_TV_MAX_EVENTS_PER_CHANNEL', 20, 1, 100),
    ],

    'sports' => [
        'available' => $defaultSports,
        'profiles_json' => footballbot_env('BOT_SPORT_PROFILES_JSON', ''),
    ],

    'images' => [
        'width' => 1280,
        'height' => 720,
        'quality' => footballbot_env_int('BOT_IMAGE_QUALITY', 9, 0, 9),
        'cleanup_after_seconds' => footballbot_env_int('BOT_IMAGE_CLEANUP_SECONDS', 86400, 0),
        'preserve_sample_images' => footballbot_env_bool('BOT_IMAGE_PRESERVE_SAMPLE_IMAGES', true),
        'font_regular' => footballbot_env('BOT_FONT_REGULAR'),
        'font_bold' => footballbot_env('BOT_FONT_BOLD'),
        'render_engine' => $renderEngine,
        'render_chrome_path' => footballbot_env('BOT_RENDER_CHROME_PATH', ''),
        'render_user_data_dir' => footballbot_env('BOT_RENDER_USER_DATA_DIR', $root . '/cache/chrome'),
        'render_extra_args' => footballbot_env_list('BOT_RENDER_EXTRA_ARGS'),
    ],
];
