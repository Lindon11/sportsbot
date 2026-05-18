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

$motorsportLeagueIds = [
    '4370', // Formula 1
    '4486', // Formula 2
    '4487', // Formula 3
    '4371', // Formula E
    '4407', // MotoGP
    '4436', // Moto2
    '4437', // Moto3
    '4372', // BTCC
    '4373', // IndyCar Series
    '4393', // NASCAR Cup Series
    '4409', // WRC
    '4412', // Super GT
    '4413', // WEC
    '4438', // DTM
    '4447', // Dakar Rally
    '4466', // Electric GT
    '4488', // IMSA
    '4489', // V8 Supercars
    '4576', // WTCR
    '4732', // Isle of Man TT
    '5264', // British Superbike
    '5412', // SuperMotocross
    '5600', // British Speedway
    '5873', // WorldSSP
];

$americanFootballLeagueIds = [
    '4391', // NFL
    '4405', // CFL
    '4479', // NCAA Division 1
    '4470', // Arena Football League
];

$iceHockeyLeagueIds = [
    '4380', // NHL
    '4381', // UK Elite Ice Hockey League
    '4419', // Swedish Hockey League
    '4738', // AHL
];

$cricketLeagueIds = [
    '4458', // English County Championship Div 1
    '4459', // English County Championship Div 2
    '4460', // Indian Premier League
    '4461', // Big Bash League
    '4462', // South African T20
    '4463', // English t20 Blast
    '4575', // Cricket World Cup
];

$basketballLeagueIds = [
    '4387', // NBA
    '4408', // Spanish Liga ACB
    '4431', // Super League Basketball
    '4433', // Italian Lega Basket
    '4434', // Australian NBL
    '4441', // German BBL
];

$baseballLeagueIds = [
    '4424', // MLB
    '4591', // Nippon Baseball League
    '4830', // Korean KBO League
];

$tennisLeagueIds = [
    '4464', // ATP World Tour
    '4517', // WTA Tour
    '4581', // Laver Cup
    '5347', // Davis Cup
    '5348', // Fed Cup
];

$defaultTvChannels = [
    // UK
    'Sky Sports Main Event',
    'Sky Sports Premier League',
    'Sky Sports Football',
    'Sky Sports Cricket',
    'Sky Sports Golf',
    'Sky Sports F1',
    'Sky Sports Arena',
    'Sky Sports Action',
    'Sky Sports Mix',
    'Sky Sports Racing',
    'Sky Sports Tennis',
    'Sky Sports Ultra HDR',
    'TNT Sports 1',
    'TNT Sports 2',
    'TNT Sports 3',
    'TNT Sports 4',
    'TNT Sports Ultimate',
    'BBC One',
    'BBC Two',
    'BBC Three',
    'BBC Four',
    'BBC iPlayer',
    'BBC Sport Website',
    'ITV1',
    'ITV4',
    'ITVX',
    'Channel 4',
    'Channel 5',
    'Premier Sports 1',
    'Premier Sports 2',
    'Viaplay Sports 1 UK',
    'Viaplay Sports 2 UK',
    'Sky Sports+',
    'DAZN UK',
    'Eurosport 1',
    'Eurosport 2',
    'Racing TV',
    'discovery+',
    'Amazon Prime Video',
    'Apple TV+',
    'YouTube',
    // Canada
    'TSN',
    'TSN1',
    'TSN2',
    'TSN3',
    'TSN4',
    'TSN5',
    'Sportsnet',
    'Sportsnet One',
    'Sportsnet 360',
    'SN1',
    'SN360',
    'CBC Sports',
    'CBC Gem',
    'RDS',
    'RDS2',
    'TVA Sports',
    'TVA Sports 2',
    // US
    'ESPN',
    'ESPN2',
    'ESPN+',
    'FS1',
    'FS2',
    'Fox Sports 1',
    'Fox Sports 2',
    'NBC Sports',
    'USA Network',
    'CBS Sports Network',
    'CBS',
    'ABC',
    'NFL Network',
    'NBA TV',
    'NHL Network',
    'MLB Network',
    'Golf Channel',
    'TNT',
    'TBS',
    'Peacock',
    'Big Ten Network',
    'BTN',
    'ACC Network',
    'ACCN',
    'SEC Network',
    'SECN',
    'The CW',
    'truTV',
];

$defaultBroadcastScheduleUrls = [
    // UK / Sky
    'https://www.skysports.com/watch/sport-on-sky',
    'https://www.skysports.com/watch/football-on-sky',
    'https://www.skysports.com/watch/f1-on-sky',
    'https://www.skysports.com/watch/boxing-on-sky',
    'https://www.tntsports.co.uk/all-sports/live/',
    'https://www.live-footballontv.com/',
    'https://www.wheresthematch.com/',
    'https://www.wheresthematch.com/live-football-on-tv/',
    'https://www.wheresthematch.com/rugby-union-on-tv/',
    'https://www.wheresthematch.com/rugby-league-on-tv/',
    'https://www.wheresthematch.com/cricket-on-tv/',
    'https://www.wheresthematch.com/motorsport-on-tv/',
    'https://www.wheresthematch.com/boxing-on-tv/',
    'https://www.wheresthematch.com/american-football-on-tv/',
    'https://www.wheresthematch.com/tennis-on-tv/',
    'https://www.wheresthematch.com/basketball-on-tv/',
    'https://www.wheresthematch.com/baseball-on-tv/',
    'https://www.wheresthematch.com/ice-hockey-on-tv/',
    'https://www.tvguide.co.uk/sport',
    'https://www.tvguide.co.uk/sport/football/',
    'https://tv24.co.uk/sports/today',
    'https://www.whatsportson.com/',
    'https://watchthematch.club/',
    'https://www.livescore.com/en/tv-guide/football-on-tv/',
    // Canada - CFL, NHL, NBA
    'https://www.tsn.ca/tv-schedule',
    'https://www.sportsnet.ca/schedule/',
    'https://watch.sportsnet.ca/schedule',
    'https://www.cbc.ca/sports',
    // Multi-sport TV listings with per-game search
    'https://www.livesportsontv.com/search?q={home_team}+vs+{away_team}',
    'https://www.livesportsontv.com/search?q={event_name}',
    'https://www.sportsglory.com/search?q={home_team}+vs+{away_team}',
    'https://www.sportsglory.com/search?q={event_name}',
    'https://www.sportsmediawatch.com/tv-schedules/',
    'https://www.sportingnews.com/us/schedule',
    // US schedules
    'https://www.espn.com/nfl/schedule',
    'https://www.espn.com/nhl/schedule',
    'https://www.espn.com/nba/schedule',
    'https://www.espn.com/mlb/schedule',
    'https://www.espn.com/racing/schedule/_/year/2026',
    'https://www.foxsports.com/event-schedule',
    'https://www.cbssports.com/cbssports/schedules',
];

$defaultCombatPosterUrls = [
    'https://www.skysports.com/watch/boxing-on-sky',
    'https://www.tntsports.co.uk/all-sports/live/',
    'https://www.dazn.com/en-GB/p/boxing',
    'https://www.ufc.com/events',
];

$defaultF1ScheduleUrls = [
    'https://www.formula1.com/en/racing/2026',
    'https://www.skysports.com/f1/schedule',
    'https://www.skysports.com/watch/f1-on-sky',
    'https://www.wheresthematch.com/motorsport-on-tv/',
    'https://www.whatsportson.com/',
    'https://tv24.co.uk/sports/today',
    'https://www.motogp.com/en/broadcasters',
    'https://www.tntsports.co.uk/all-sports/live/',
    'https://www.nascar.com/news-media/2026/4/19/nascar-tv-guide/amp/',
    'https://www.espn.com/racing/schedule/_/year/2026',
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

    'discord' => [
        'enabled' => env('SPORTSBOT_DISCORD_ENABLED', false),
        'default_webhook_url' => env('SPORTSBOT_DISCORD_WEBHOOK_URL', ''),
        'route_webhooks' => [],
        'username' => env('SPORTSBOT_DISCORD_USERNAME', 'SportsBot'),
        'avatar_url' => env('SPORTSBOT_DISCORD_AVATAR_URL', ''),
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
        'formula_1_league_ids' => $motorsportLeagueIds,
        'american_football_league_ids' => $americanFootballLeagueIds,
        'ice_hockey_league_ids' => $iceHockeyLeagueIds,
        'cricket_league_ids' => $cricketLeagueIds,
        'basketball_league_ids' => $basketballLeagueIds,
        'baseball_league_ids' => $baseballLeagueIds,
        'tennis_league_ids' => $tennisLeagueIds,
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

    'scrapers' => [
        'enabled' => env('SPORTSBOT_SCRAPERS_ENABLED', true),
        'search_enabled' => env('SPORTSBOT_SCRAPER_SEARCH_ENABLED', true),
        'search_url' => env('SPORTSBOT_SCRAPER_SEARCH_URL', ''),
        'search_urls' => $csv(env('SPORTSBOT_SCRAPER_SEARCH_URLS', env('SPORTSBOT_SCRAPER_SEARCH_URL', 'https://html.duckduckgo.com/html/?q={query}'))),
        'search_max_results' => (int) env('SPORTSBOT_SCRAPER_SEARCH_MAX_RESULTS', 5),
        'timeout' => (int) env('SPORTSBOT_SCRAPER_TIMEOUT', 8),
        'user_agent' => env('SPORTSBOT_SCRAPER_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'),
        'auto_use_confidence' => (float) env('SPORTSBOT_SCRAPER_AUTO_USE_CONFIDENCE', 0.9),
        'combat_poster_urls' => $csv(env('SPORTSBOT_COMBAT_POSTER_URLS', implode(',', $defaultCombatPosterUrls))),
        'broadcast_schedule_urls' => $csv(env('SPORTSBOT_BROADCAST_SCHEDULE_URLS', implode(',', $defaultBroadcastScheduleUrls))),
        'f1_schedule_urls' => $csv(env('SPORTSBOT_F1_SCHEDULE_URLS', implode(',', $defaultF1ScheduleUrls))),
        'combat_poster_search_queries' => $csv(env('SPORTSBOT_COMBAT_POSTER_SEARCH_QUERIES', '')),
        'broadcast_schedule_search_queries' => $csv(env('SPORTSBOT_BROADCAST_SCHEDULE_SEARCH_QUERIES', '')),
        'f1_schedule_search_queries' => $csv(env('SPORTSBOT_F1_SCHEDULE_SEARCH_QUERIES', '')),
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
        'fixture_queue' => [
            'enabled' => env('SPORTSBOT_FIXTURE_QUEUE_SCHEDULE_ENABLED', false),
            'prefetch_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_PREFETCH_ENABLED', true),
            'prefetch_time' => env('SPORTSBOT_FIXTURE_QUEUE_PREFETCH_TIME', '05:00'),
            'enrich_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_ENABLED', true),
            'enrich_frequency' => env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_FREQUENCY', 'everyThirtyMinutes'),
            'enrich_days' => (int) env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_DAYS', 2),
            'enrich_limit' => (int) env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_LIMIT', 30),
            'render_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_RENDER_ENABLED', true),
            'render_frequency' => env('SPORTSBOT_FIXTURE_QUEUE_RENDER_FREQUENCY', 'everyTenMinutes'),
            'publish_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_ENABLED', true),
            'publish_frequency' => env('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_FREQUENCY', 'everyFiveMinutes'),
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
