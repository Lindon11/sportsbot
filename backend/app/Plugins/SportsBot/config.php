<?php

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
    // England
    '4328', // English Premier League
    '4329', // EFL Championship
    '4396', // League One
    '4397', // League Two
    '4590', // National League
    '4681', // National League North
    '4682', // National League South
    '4646', // Northern Premier League Premier Division
    '4647', // Isthmian League Premier Division
    '4648', // Southern Premier League South Division
    '5324', // Southern Premier League Central Division
    '4482', // FA Cup
    '4570', // EFL Cup
    '4571', // FA Community Shield
    '4847', // EFL Trophy
    '4849', // Womens Super League
    '5212', // Womens Championship
    '5441', // FA Womens Challenge Cup
    '4887', // FA Womens League Cup

    // Scotland
    '4330', // Scottish Premiership
    '4395', // Scottish Championship
    '4669', // Scottish League One
    '4670', // Scottish League Two
    '5095', // Scottish Highland League
    '5096', // Scottish Lowland League
    '4723', // Scottish FA Cup
    '4888', // Scottish League Cup

    // Wales
    '4472', // Cymru Premier
    '5315', // Cymru North/South
    '5099', // Welsh League Cup

    // Northern Ireland
    '4659', // NIFL Premiership
    '4755', // NIFL Championship
    '5097', // NIFL Premier Intermediate League

    // European competitions with frequent UK club coverage
    '4480', // UEFA Champions League
    '4481', // UEFA Europa League
    '4889', // UEFA Womens Champions League

    // International football
    '4429', // FIFA World Cup
    '4502', // UEFA European Championships
    '5518', // World Cup Qualifying UEFA
    '5519', // UEFA European Championships Qualifying
    '4490', // UEFA Nations League
    '4562', // International Friendlies
    '5819', // Finalissima
    '4566', // UEFA European Under-21 Championship
    '4565', // FIFA Womens World Cup
    '4865', // UEFA Womens Euro
    '5840', // Womens World Cup Qualifying UEFA
    '5410', // UEFA Womens Nations League
    '5400', // International Friendlies Women
];

$internationalFootballLeagueIds = [
    '4429', // FIFA World Cup
    '4502', // UEFA European Championships
    '5518', // World Cup Qualifying UEFA
    '5519', // UEFA European Championships Qualifying
    '4490', // UEFA Nations League
    '4562', // International Friendlies
    '5819', // Finalissima
    '4566', // UEFA European Under-21 Championship
    '4565', // FIFA Womens World Cup
    '4865', // UEFA Womens Euro
    '5840', // Womens World Cup Qualifying UEFA
    '5410', // UEFA Womens Nations League
    '5400', // International Friendlies Women
];

$rugbyLeagueIds = [
    '4414', // English Premiership Rugby
    '5695', // Premiership Rugby Cup
    '4722', // English RFU Championship
    '4415', // Rugby League Super League
    '4589', // RFL Championship
    '4416', // Australian National Rugby League
    '4446', // United Rugby Championship
    '4550', // European Rugby Champions Cup
    '5418', // European Rugby Challenge Cup
    '4714', // Six Nations Championship
    '5563', // Six Nations Women
    '5082', // Six Nations Under 20s
    '4984', // Autumn Nations Series
    '5479', // Rugby Union International Friendlies
    '5512', // British and Irish Lions Tours
    '4574', // Rugby World Cup
    '5682', // Womens Rugby World Cup
    '5806', // Rugby League World Cup
    '5807', // Rugby League Pacific Cup
    '5808', // Rugby League Pacific Bowl
    '5834', // World Club Challenge
    '5835', // State of Origin
    '5173', // Scottish Premiership Rugby
];

$fightLeagueIds = [
    '4443', // UFC
    '4445', // Boxing
    '4567', // BKFC
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
    'Premier Sports 2',
    'Viaplay Sports 1 UK',
    'Viaplay Sports 2 UK',
    'Sky Sports+',
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
        'api_key' => env('SPORTSBOT_THESPORTSDB_API_KEY'),
        'timeout' => (int) env('SPORTSBOT_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('SPORTSBOT_HTTP_CONNECT_TIMEOUT', 10),
        'live_score_cache_ttl' => (int) env('SPORTSBOT_LIVE_SCORE_CACHE_TTL', 75),
    ],

    'cache' => [
        'live_scores' => (int) env('SPORTSBOT_CACHE_LIVE_SCORES', 60),
        'fixtures' => (int) env('SPORTSBOT_CACHE_FIXTURES', 900),
        'league_table' => (int) env('SPORTSBOT_CACHE_LEAGUE_TABLE', 3600),
        'tv_guide' => (int) env('SPORTSBOT_CACHE_TV_GUIDE', 1800),
        'team' => (int) env('SPORTSBOT_CACHE_TEAM', 86400),
        'player' => (int) env('SPORTSBOT_CACHE_PLAYER', 86400),
        'metadata' => (int) env('SPORTSBOT_CACHE_METADATA', 86400),
    ],

    'cards' => [
        'enabled' => env('SPORTSBOT_CARDS_ENABLED', true),
        'width' => (int) env('SPORTSBOT_CARD_WIDTH', 1200),
        'height' => (int) env('SPORTSBOT_CARD_HEIGHT', 675),
        'font_regular' => env('SPORTSBOT_CARD_FONT_REGULAR', ''),
        'font_bold' => env('SPORTSBOT_CARD_FONT_BOLD', ''),
        'image_cache_ttl' => (int) env('SPORTSBOT_CARD_IMAGE_CACHE_TTL', 604800),
        'fight_art_url_cache_ttl' => (int) env('SPORTSBOT_FIGHT_ART_URL_CACHE_TTL', 2592000),
    ],

    'telegram' => [
        'bot_token' => env('SPORTSBOT_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('SPORTSBOT_TELEGRAM_CHAT_ID'),
        'message_thread_id' => env('SPORTSBOT_TELEGRAM_MESSAGE_THREAD_ID'),
        'extra_chat_ids' => $csv(env('SPORTSBOT_TELEGRAM_EXTRA_CHAT_IDS', '')),
        'parse_mode' => env('SPORTSBOT_TELEGRAM_PARSE_MODE', 'HTML'),
        'disable_notification' => env('SPORTSBOT_TELEGRAM_DISABLE_NOTIFICATION', false),
        'webhook_enabled' => env('SPORTSBOT_TELEGRAM_WEBHOOK_ENABLED', false),
        'webhook_secret' => env('SPORTSBOT_TELEGRAM_WEBHOOK_SECRET', ''),
    ],

    'legacy' => [
        'state_db' => env('SPORTSBOT_LEGACY_STATE_DB', base_path('footballbot/cache/state.sqlite')),
    ],

    'coverage' => [
        'enabled_sports' => $csv(env('SPORTSBOT_ENABLED_SPORTS', 'Soccer,Basketball,Baseball,American Football,Tennis,Fighting,Rugby,Cricket,Motorsport,Ice Hockey,Golf')),
        'allowed_league_ids' => $csv(env('SPORTSBOT_ALLOWED_LEAGUE_IDS', '')),
        'max_live_matches_per_run' => (int) env('SPORTSBOT_MAX_LIVE_MATCHES_PER_RUN', 75),
    ],

    'fixtures_today' => [
        'default_league_ids' => $legacyDefaultLeagueIds,
        'international_league_ids' => $internationalFootballLeagueIds,
        'rugby_league_ids' => $rugbyLeagueIds,
        'fight_league_ids' => $fightLeagueIds,
        'fight_lookahead_days' => (int) env('SPORTSBOT_FIGHT_FIXTURES_LOOKAHEAD_DAYS', 30),
        'max_per_sport' => (int) env('SPORTSBOT_FIXTURES_TODAY_MAX_PER_SPORT', 5),
        'timezone' => env('SPORTSBOT_FIXTURES_TIMEZONE', 'Europe/London'),
    ],

    'live_now' => [
        'max_per_sport' => (int) env('SPORTSBOT_LIVE_NOW_MAX_PER_SPORT', 8),
    ],

    'tv' => [
        'enabled' => env('SPORTSBOT_TV_ENABLED', true),
        'channels' => $csv(env('SPORTSBOT_TV_CHANNELS', implode(',', $defaultTvChannels))),
        'sports' => $csv(env('SPORTSBOT_TV_SPORTS', 'Soccer,Basketball,Baseball,MMA,Tennis,Rugby')),
        'lookahead_hours' => (int) env('SPORTSBOT_TV_LOOKAHEAD_HOURS', 24),
        'max_events_per_channel' => (int) env('SPORTSBOT_TV_MAX_EVENTS_PER_CHANNEL', 20),
        'max_per_channel' => (int) env('SPORTSBOT_TV_GUIDE_MAX_PER_CHANNEL', 8),
        'show_empty_channels' => env('SPORTSBOT_TV_GUIDE_SHOW_EMPTY_CHANNELS', false),
        'cache_ttl' => (int) env('SPORTSBOT_TV_CACHE_TTL', 900),
    ],

    'publishing' => [
        'fixtures_today' => [
            'enabled' => env('SPORTSBOT_FIXTURES_TODAY_SCHEDULE_ENABLED', false),
            'time' => env('SPORTSBOT_FIXTURES_TODAY_SCHEDULE_TIME', '08:00'),
        ],
        'tv_guide' => [
            'enabled' => env('SPORTSBOT_TV_GUIDE_SCHEDULE_ENABLED', false),
            'time' => env('SPORTSBOT_TV_GUIDE_SCHEDULE_TIME', '08:00'),
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
        'rich_cards' => env('SPORTSBOT_RICH_CARDS_ENABLED', true),
        'callback_throttle_seconds' => (int) env('SPORTSBOT_CALLBACK_THROTTLE_SECONDS', 2),
    ],
];
