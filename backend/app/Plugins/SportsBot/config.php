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

$jsonObject = static function (mixed $value): array {
    if (is_array($value)) {
        return $value;
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
};

$legacyDefaultLeagueIds = [
    // England
    '4328', // English Premier League
    '4329', // EFL Championship
    '4396', // League One
    '4397', // League Two
    '4482', // FA Cup
    '4570', // EFL Cup
    '4571', // FA Community Shield
    '4849', // Womens Super League

    // Scotland
    '4330', // Scottish Premiership
    '4395', // Scottish Championship

    // Northern Ireland
    '4659', // NIFL Premiership

    // Republic of Ireland
    '4643', // Irish Premier Division (League of Ireland Premier)
    '5638', // Irish FAI Cup

    // European competitions with frequent UK club coverage
    '4480', // UEFA Champions League
    '4481', // UEFA Europa League
    '4889', // UEFA Womens Champions League

    // Spain
    '4335', // La Liga

    // Germany
    '4331', // Bundesliga

    // Italy
    '4332', // Serie A

    // France
    '4334', // Ligue 1

    // Netherlands
    '4337', // Eredivisie

    // Portugal
    '4344', // Primeira Liga

    // USA
    '4346', // Major League Soccer

    // Saudi Arabia
    '4668', // Saudi Pro League

    // International football
    '4429', // FIFA World Cup
    '4502', // UEFA European Championships
    '5518', // World Cup Qualifying UEFA
    '5519', // UEFA European Championships Qualifying
    '4490', // UEFA Nations League
    '4562', // International Friendlies
    '4565', // FIFA Womens World Cup
    '4865', // UEFA Womens Euro
    '5410', // UEFA Womens Nations League
];

$internationalFootballLeagueIds = [
    '4429', // FIFA World Cup
    '4502', // UEFA European Championships
    '5518', // World Cup Qualifying UEFA
    '5519', // UEFA European Championships Qualifying
    '4490', // UEFA Nations League
    '4562', // International Friendlies
    '4565', // FIFA Womens World Cup
    '4865', // UEFA Womens Euro
    '5410', // UEFA Womens Nations League
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
    '4444', // WWE
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
    // International
    'DAZN',
    'DAZN Canada',
    'DAZN Spain',
    'DAZN Germany',
    'DAZN Japan',
    'DAZN Italy',
    'DAZN Portugal',
    'DAZN Belgium',
    'Viaplay',
    'Viaplay UK',
    'Viaplay Sweden',
    'Viaplay Norway',
    'Viaplay Denmark',
    'Viaplay Finland',
    'Viaplay Netherlands',
    'Viaplay Poland',
    'Eurosport 1',
    'Eurosport 2',
    'beIN Sports',
    'beIN Sports 1',
    'beIN Sports 2',
    'beIN Sports 3',
    'beIN Sports 4',
    'SuperSport',
    'SuperSport 1',
    'SuperSport 2',
    'SuperSport 3',
    'SuperSport 4',
    'SuperSport 5',
    'SuperSport 6',
    'SuperSport 7',
    'SuperSport 8',
    'SuperSport 9',
    'SuperSport 10',
    'SuperSport 11',
    'SuperSport 12',
    'SuperSport 13',
    'SuperSport 14',
    'SuperSport 15',
    'SuperSport 16',
    'SuperSport 17',
    'SuperSport 18',
    'SuperSport 19',
    'SuperSport 20',
    'SuperSport Maximo',
    'ESPN',
    'ESPN 2',
    'ESPN 3',
    'ESPN Deportes',
    'ESPN Brazil',
    'ESPN Argentina',
    'ESPN Mexico',
    'ESPN UK',
    'ESPN Australia',
    'ESPN Caribbean',
    'Fox Sports',
    'Fox Sports 1',
    'Fox Sports 2',
    'Fox Soccer Plus',
    'Fox Deportes',
    'Fox Sports Australia',
    'Fox Sports Brazil',
    'Fox Sports Mexico',
    'Fox Sports Argentina',
    'CBS Sports',
    'CBS Sports Network',
    'NBC Sports',
    'NBC Sports Network',
    'NBC Sports California',
    'NBC Sports Chicago',
    'NBC Sports Boston',
    'NBC Sports Philadelphia',
    'NBC Sports Washington',
    'NBC Sports Bay Area',
    'ABC',
    'ABC Australia',
    'ABC America',
    'BBC One',
    'BBC Two',
    'BBC Three',
    'BBC Four',
    'BBC Scotland',
    'BBC Wales',
    'BBC Northern Ireland',
    'BBC Red Button',
    'BBC Sport Website',
    'ITV 1',
    'ITV 4',
    'STV',
    'Channel 4',
    'Channel 5',
    'UKTV',
    'S4C',
    'TG4',
    'RTE',
    'RTE 2',
    'Virgin Media One',
    'Virgin Media Two',
    'Virgin Media Three',
    'Sky Sports Main Event',
    'Sky Sports Premier League',
    'Sky Sports Football',
    'Sky Sports Golf',
    'Sky Sports Cricket',
    'Sky Sports Tennis',
    'Sky Sports F1',
    'Sky Sports NBA',
    'Sky Sports NFL',
    'Sky Sports Racing',
    'Sky Sports News',
    'Sky Sports Mix',
    'Sky Sports Arena',
    'Sky Sports Action',
    'Sky Sports Ultra HDR',
    'Sky Sports+',
    'Sky Sports Box Office',
    'Sky Sports Australia',
    'Sky Sports New Zealand',
    'Sky Sports Italy',
    'Sky Sports Germany',
    'Sky Sports Mexico',
    'Sky Go',
    'TNT Sports 1',
    'TNT Sports 2',
    'TNT Sports 3',
    'TNT Sports 4',
    'TNT Sports 5',
    'TNT Sports Ultimate',
    'TNT Sports Box Office',
    'Premier Sports 1',
    'Premier Sports 2',
    'Premier Sports ROI',
    'Amazon Prime Video',
    'Amazon Prime Video Australia',
    'Apple TV+',
    'Netflix',
    'Disney+',
    'Paramount+',
    'Peacock',
    'Peacock Premium',
    'HBO Max',
    'Max',
    'HBO',
    'Showtime',
    'Starz',
    'Fubo TV',
    'Fubo Sports',
    'Sling TV',
    'Sling Orange',
    'Sling Blue',
    'YouTube TV',
    'Hulu + Live TV',
    'DirecTV Stream',
    'DirecTV',
    'Optus Sport',
    'Kayo Sports',
    'Foxtel',
    'Stan Sport',
    'Watch AFL',
    'Watch NRL',
    'Kayo Freebies',
    '7plus',
    '9Now',
    '10 Play',
    'SBS On Demand',
    'ABC iView',
    'TSN 1',
    'TSN 2',
    'TSN 3',
    'TSN 4',
    'TSN 5',
    'RDS',
    'RDS 2',
    'RDS Info',
    'Sportsnet',
    'Sportsnet 360',
    'Sportsnet One',
    'SN Now',
    'TSN+',
    'CBC Sports',
    'CBC Gem',
    'TVA Sports',
    'TVA Sports 2',
    'ATG TV',
    'MLB Network',
    'NBA TV',
    'NHL Network',
    'NFL Network',
    'NFL Sunday Ticket',
    'NFL RedZone',
    'MLS Season Pass',
    'Golf Channel',
    'Tennis Channel',
    'Big Ten Network',
    'SEC Network',
    'ACC Network',
    'Pac-12 Network',
    'Longhorn Network',
    'MSG Network',
    'NESN',
    'YES Network',
    'SNY',
    'Marquee Sports',
    'Bally Sports',
    'Bally Sports Arizona',
    'Bally Sports Detroit',
    'Bally Sports Florida',
    'Bally Sports Great Lakes',
    'Bally Sports Indiana',
    'Bally Sports Kansas City',
    'Bally Sports Midwest',
    'Bally Sports New Orleans',
    'Bally Sports North',
    'Bally Sports Ohio',
    'Bally Sports Oklahoma',
    'Bally Sports San Diego',
    'Bally Sports SoCal',
    'Bally Sports South',
    'Bally Sports Southeast',
    'Bally Sports Southwest',
    'Bally Sports Sun',
    'Bally Sports West',
    'Bally Sports Wisconsin',
    'NBC Sports Regional',
    'Altitude Sports',
    'Root Sports',
    'MASN',
    'SportsTime Ohio',
    'Mid-Atlantic Sports',
    'WWE Network',
    'UFC Fight Pass',
    'Triller TV',
    'FITE TV',
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
    // === GLOBAL TV GUIDES ===
    'https://www.livesoccertv.com/',
    'https://www.livesportstv.com/',
    'https://www.onsport.com/guide/',
    'https://www.tvgids24.nl/sport',
    // UK
    'https://www.live-footballontv.com/',
    'https://www.wheresthematch.com/',
    'https://www.tvguide.co.uk/sport',
    'https://www.whatsportson.com/',
    'https://watchthematch.club/',
    // USA
    'https://www.livesportsontv.com/',
    'https://www.sportsmediawatch.com/tv-schedules/',
    'https://www.sportingnews.com/us/schedule',
    'https://www.espn.com/nfl/schedule',
    'https://www.espn.com/nba/schedule',
    'https://www.espn.com/mlb/schedule',
    'https://www.espn.com/nhl/schedule',
    'https://www.espn.com/racing/schedule/_/year/2026',
    'https://www.espn.com/mma/schedule',
    'https://www.foxsports.com/event-schedule',
    'https://www.cbssports.com/nfl/schedule/',
    'https://www.cbssports.com/nba/schedule/',
    'https://www.cbssports.com/mlb/schedule/',
    'https://www.cbssports.com/nhl/schedule/',
    'https://www.ustvgo.tv/sports/',
    'https://www.titantv.com/guide/sports.aspx',
    // Canada
    'https://www.tsn.ca/tv-schedule',
    'https://www.sportsnet.ca/schedule/',
    // Australia
    'https://www.foxsports.com.au/tv-guide/',
    'https://www.kayosports.com.au/schedule',
    'https://www.espn.com.au/sports/schedule',
    // Europe
    'https://www.fernsehserien.de/sport',
    'https://www.programme-tv.net/sport/',
    // Middle East / Africa
    'https://www.supersport.com/tv-guide/',
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
        'width' => (int) env('SPORTSBOT_CARD_WIDTH', 1536),
        'height' => (int) env('SPORTSBOT_CARD_HEIGHT', 864),
        'font_regular' => env('SPORTSBOT_CARD_FONT_REGULAR', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
        'font_bold' => env('SPORTSBOT_CARD_FONT_BOLD', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'),
        'v3_browser_enabled' => env('SPORTSBOT_CARD_V3_BROWSER_ENABLED', true),
        'v3_renderer_script' => env('SPORTSBOT_CARD_V3_RENDERER_SCRIPT', base_path('resources/sportsbot/v3-card-renderer.cjs')),
        'node_binary' => env('SPORTSBOT_CARD_NODE_BINARY', 'node'),
        'chrome_path' => env('SPORTSBOT_CARD_CHROME_PATH', env('PUPPETEER_EXECUTABLE_PATH', '')),
        'browser_timeout' => (int) env('SPORTSBOT_CARD_BROWSER_TIMEOUT', 15),
        'browser_retries' => (int) env('SPORTSBOT_CARD_BROWSER_RETRIES', 1),
        'browser_concurrency' => (int) env('SPORTSBOT_CARD_BROWSER_CONCURRENCY', 2),
        'browser_args' => $csv(env('SPORTSBOT_CARD_BROWSER_ARGS', '--no-sandbox,--disable-setuid-sandbox,--disable-dev-shm-usage')),
        'gd_fallback_enabled' => env('SPORTSBOT_CARD_GD_FALLBACK_ENABLED', true),
        'low_bandwidth_mode' => env('SPORTSBOT_CARD_LOW_BANDWIDTH_MODE', false),
        'asset_cache_timeout' => (int) env('SPORTSBOT_CARD_ASSET_CACHE_TIMEOUT', 12),
        'asset_cache_retries' => (int) env('SPORTSBOT_CARD_ASSET_CACHE_RETRIES', 2),
        'asset_cache_retry_delay_ms' => (int) env('SPORTSBOT_CARD_ASSET_CACHE_RETRY_DELAY_MS', 250),
        'asset_cache_stale_days' => (int) env('SPORTSBOT_CARD_ASSET_CACHE_STALE_DAYS', 30),
        'image_cache_ttl' => (int) env('SPORTSBOT_CARD_IMAGE_CACHE_TTL', 604800),
        'fight_art_url_cache_ttl' => (int) env('SPORTSBOT_FIGHT_ART_URL_CACHE_TTL', 2592000),
        'template_registry' => [
            'template' => env('SPORTSBOT_CARD_DEFAULT_TEMPLATE', 'stadium-v3'),
            'theme' => env('SPORTSBOT_CARD_DEFAULT_THEME', 'limitless-dark'),
            'card_version' => 'v3',
            'branding' => [
                'watermark' => env('SPORTSBOT_CARD_WATERMARK', 'The Sports Hub'),
                'telegram' => env('SPORTSBOT_CARD_TELEGRAM_BRAND', 'Telegram'),
                'discord' => env('SPORTSBOT_CARD_DISCORD_BRAND', 'Discord'),
                'sponsor_slot' => env('SPORTSBOT_CARD_SPONSOR_SLOT', ''),
            ],
            'templates' => [
                'stadium-v3' => ['type' => 'fixture-card', 'version' => 'v3'],
                'usa-broadcast-v3' => ['type' => 'fixture-card', 'version' => 'v3'],
                'fight-poster-v3' => ['type' => 'fight-poster-card', 'version' => 'v3'],
                'compact-tv-v1' => ['type' => 'tv-guide-card', 'version' => 'v1'],
                'result-card-v1' => ['type' => 'result-card', 'version' => 'v1'],
                'highlight-card-v1' => ['type' => 'highlight-card', 'version' => 'v1'],
            ],
            'themes' => [
                'limitless-dark' => ['label' => 'Limitless TV', 'variant' => 'dark'],
                'criminal-empire' => ['label' => 'Criminal Empire', 'variant' => 'dark'],
                'dark-neon' => ['label' => 'Dark Neon', 'variant' => 'dark'],
                'fight-night' => ['label' => 'Fight Night', 'variant' => 'dark'],
                'stadium' => ['label' => 'Stadium', 'variant' => 'dark'],
                'espn-style' => ['label' => 'ESPN-style', 'variant' => 'light'],
                'sky-style' => ['label' => 'Sky-style', 'variant' => 'light'],
                'usa-broadcast' => ['label' => 'USA Broadcast', 'variant' => 'dark'],
                'motorsport-neon' => ['label' => 'Motorsport Neon', 'variant' => 'dark'],
            ],
            'sports' => [
                'football' => ['template' => 'stadium-v3', 'theme' => 'stadium', 'card_version' => 'v3'],
                'rugby' => ['template' => 'stadium-v3', 'theme' => 'stadium', 'card_version' => 'v3'],
                'fights' => ['template' => 'fight-poster-v3', 'theme' => 'fight-night', 'card_version' => 'v3'],
                'mma' => ['template' => 'fight-poster-v3', 'theme' => 'fight-night', 'card_version' => 'v3'],
                'boxing' => ['template' => 'fight-poster-v3', 'theme' => 'fight-night', 'card_version' => 'v3'],
                'basketball' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast', 'card_version' => 'v3'],
                'baseball' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast', 'card_version' => 'v3'],
                'american_football' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast', 'card_version' => 'v3'],
                'ice_hockey' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast', 'card_version' => 'v3'],
                'formula_1' => ['template' => 'stadium-v3', 'theme' => 'motorsport-neon', 'card_version' => 'v3'],
                'other_sports' => ['template' => 'stadium-v3', 'theme' => 'limitless-dark', 'card_version' => 'v3'],
            ],
            'routes' => [
                'FOOTBALL' => ['template' => 'stadium-v3', 'theme' => 'stadium'],
                'BASKETBALL' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast'],
                'BASEBALL' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast'],
                'AMERICAN_FOOTBALL' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast'],
                'ICE_HOCKEY' => ['template' => 'usa-broadcast-v3', 'theme' => 'usa-broadcast'],
                'MMA' => ['template' => 'fight-poster-v3', 'theme' => 'fight-night'],
                'BOXING' => ['template' => 'fight-poster-v3', 'theme' => 'fight-night'],
                'COMBAT_OTHER' => ['template' => 'fight-poster-v3', 'theme' => 'fight-night'],
                'TV_GUIDE' => ['template' => 'compact-tv-v1', 'theme' => 'limitless-dark'],
                'TENNIS' => ['template' => 'stadium-v3', 'theme' => 'limitless-dark'],
                'CRICKET' => ['template' => 'stadium-v3', 'theme' => 'limitless-dark'],
                'GOLF' => ['template' => 'stadium-v3', 'theme' => 'limitless-dark'],
                'FORMULA_1' => ['template' => 'stadium-v3', 'theme' => 'motorsport-neon'],
                'FORMULA_2' => ['template' => 'stadium-v3', 'theme' => 'motorsport-neon'],
                'FORMULA_3' => ['template' => 'stadium-v3', 'theme' => 'motorsport-neon'],
                'MOTOGP' => ['template' => 'stadium-v3', 'theme' => 'motorsport-neon'],
                'MOTORSPORT_OTHER' => ['template' => 'stadium-v3', 'theme' => 'motorsport-neon'],
            ],
            'topics' => [],
        ],
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
        'bot_token' => env('SPORTSBOT_DISCORD_BOT_TOKEN', ''),
        'default_channel_id' => env('SPORTSBOT_DISCORD_DEFAULT_CHANNEL_ID', env('SPORTSBOT_DISCORD_CHANNEL_ID', '')),
        'bot_channels' => $jsonObject(env('SPORTSBOT_DISCORD_BOT_CHANNELS_JSON', '')),
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
        'sports' => $csv(env('SPORTSBOT_TV_SPORTS', 'Soccer,Basketball,Baseball,American Football,Ice Hockey,MMA,Tennis,Rugby,Cricket,Formula 1,Golf')),
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
        'retry_backoff_minutes' => (int) env('SPORTSBOT_SCRAPER_RETRY_BACKOFF_MINUTES', 30),
        'combat_poster_urls' => $csv(env('SPORTSBOT_COMBAT_POSTER_URLS', implode(',', $defaultCombatPosterUrls))),
        'broadcast_schedule_urls' => $csv(env('SPORTSBOT_BROADCAST_SCHEDULE_URLS', implode(',', $defaultBroadcastScheduleUrls))),
        'f1_schedule_urls' => $csv(env('SPORTSBOT_F1_SCHEDULE_URLS', implode(',', $defaultF1ScheduleUrls))),
        'combat_poster_search_queries' => $csv(env('SPORTSBOT_COMBAT_POSTER_SEARCH_QUERIES', implode(',', [
            '{home_team} vs {away_team} fight poster',
            '{event_name} poster',
            '{home_team} {away_team} broadcast channel tv',
        ]))),
        'broadcast_schedule_search_queries' => $csv(env('SPORTSBOT_BROADCAST_SCHEDULE_SEARCH_QUERIES', implode(',', [
            '{home_team} vs {away_team} live on',
            '{home_team} vs {away_team} tv channel broadcast',
            '{home_team} {away_team} stream channel',
            '{event_name} tv schedule channel',
            '{home_team} vs {away_team} what channel',
            '{event_name} live stream channel',
            '{home_team} {away_team} broadcast schedule',
            'watch {event_name} online',
            '{home_team} {away_team} streaming',
        ]))),
        'f1_schedule_search_queries' => $csv(env('SPORTSBOT_F1_SCHEDULE_SEARCH_QUERIES', '')),
    ],

    'epg' => [
        'default_region' => env('SPORTSBOT_EPG_DEFAULT_REGION', 'UK'),
        'source_policy' => env('SPORTSBOT_EPG_SOURCE_POLICY', 'uk_sports_first'),
        'feed_url' => env('SPORTSBOT_EPG_FEED_URL', ''),
        'feed_urls' => [
            env('SPORTSBOT_EPG_FEED_URL_UK', 'https://epgshare01.online/epgshare01/epg_ripper_UK1.xml.gz'),
            env('SPORTSBOT_EPG_FEED_URL_DE', ''),
            env('SPORTSBOT_EPG_FEED_URL_FR', ''),
            env('SPORTSBOT_EPG_FEED_URL_ES', ''),
            env('SPORTSBOT_EPG_FEED_URL_IT', ''),
            env('SPORTSBOT_EPG_FEED_URL_AU', ''),
            env('SPORTSBOT_EPG_FEED_URL_NL', ''),
            env('SPORTSBOT_EPG_FEED_URL_BR', ''),
        ],
        'import_enabled' => env('SPORTSBOT_EPG_IMPORT_ENABLED', false),
        'export_token' => env('SPORTSBOT_EPG_EXPORT_TOKEN', ''),
        'import_chunk_size' => (int) env('SPORTSBOT_EPG_IMPORT_CHUNK_SIZE', 2000),
        'max_programmes' => (int) env('SPORTSBOT_EPG_MAX_PROGRAMMES', 80000),
        'skip_unchanged' => env('SPORTSBOT_EPG_SKIP_UNCHANGED', true),
        'allow_private_feed_urls' => env('SPORTSBOT_EPG_ALLOW_PRIVATE_FEED_URLS', false),
        'schedule_verifier_min_confidence' => (float) env('SPORTSBOT_EPG_SCHEDULE_VERIFIER_MIN_CONFIDENCE', 0.8),
        'schedule_verifier_boost' => (float) env('SPORTSBOT_EPG_SCHEDULE_VERIFIER_BOOST', 0.08),
        'grabbers' => [
            'tools_path' => env('SPORTSBOT_EPG_GRABBER_TOOLS_PATH', storage_path('app/sportsbot/epg/tools')),
            'output_path' => env('SPORTSBOT_EPG_GRABBER_OUTPUT_PATH', storage_path('app/sportsbot/epg/grabber-output')),
            'iptv_org_path' => env('SPORTSBOT_EPG_IPTV_ORG_PATH', storage_path('app/sportsbot/epg/tools/iptv-org-epg')),
            'iptv_org_sites' => env('SPORTSBOT_EPG_IPTV_ORG_SITES', 'sky.com,bbc.co.uk,itv.com,channel4.com'),
            'external_timeout' => (int) env('SPORTSBOT_EPG_GRABBER_TIMEOUT', 900),
        ],
        'retention' => [
            'output_days' => (int) env('SPORTSBOT_EPG_OUTPUT_RETENTION_DAYS', 3),
            'history_days' => (int) env('SPORTSBOT_EPG_HISTORY_RETENTION_DAYS', 21),
            'programme_past_days' => (int) env('SPORTSBOT_EPG_PROGRAMME_PAST_RETENTION_DAYS', 2),
        ],
        'health' => [
            'export_max_age_hours' => (int) env('SPORTSBOT_EPG_EXPORT_MAX_AGE_HOURS', 8),
            'notify_cooldown_minutes' => (int) env('SPORTSBOT_EPG_HEALTH_NOTIFY_COOLDOWN_MINUTES', 360),
            'grabber_failure_runs' => (int) env('SPORTSBOT_EPG_GRABBER_FAILURE_ALERT_RUNS', 3),
            'programme_drop_ratio' => (float) env('SPORTSBOT_EPG_PROGRAMME_DROP_RATIO', 0.25),
        ],
    ],

    'publishing' => [
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
            'prefetch_time' => env('SPORTSBOT_FIXTURE_QUEUE_PREFETCH_TIME', '01:00'),
            'enrich_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_ENABLED', true),
            'enrich_frequency' => env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_FREQUENCY', 'everyThirtyMinutes'),
            'enrich_days' => (int) env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_DAYS', 2),
            'enrich_limit' => (int) env('SPORTSBOT_FIXTURE_QUEUE_ENRICH_LIMIT', 30),
            'render_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_RENDER_ENABLED', true),
            'render_frequency' => env('SPORTSBOT_FIXTURE_QUEUE_RENDER_FREQUENCY', 'everyTenMinutes'),
            'publish_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_ENABLED', true),
            'publish_frequency' => env('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_FREQUENCY', 'everyFiveMinutes'),
            'publish_time' => env('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_TIME', '00:00'),
            'allow_gd_fallback_publish' => env('SPORTSBOT_FIXTURE_QUEUE_ALLOW_GD_FALLBACK_PUBLISH', false),
            'fallback_retry_enabled' => env('SPORTSBOT_FIXTURE_QUEUE_FALLBACK_RETRY_ENABLED', true),
            'fallback_retry_minutes' => (int) env('SPORTSBOT_FIXTURE_QUEUE_FALLBACK_RETRY_MINUTES', 30),
        ],
    ],

    'features' => [
        'send_score_updates' => env('SPORTSBOT_SEND_SCORE_UPDATES', true),
        'send_status_updates' => env('SPORTSBOT_SEND_STATUS_UPDATES', true),
        'send_first_seen_live_alerts' => env('SPORTSBOT_SEND_FIRST_SEEN_LIVE_ALERTS', false),
        'rich_cards' => env('SPORTSBOT_RICH_CARDS_ENABLED', true),
        'callback_throttle_seconds' => (int) env('SPORTSBOT_CALLBACK_THROTTLE_SECONDS', 2),
    ],

    'updater' => [
        'enabled' => env('SPORTSBOT_UPDATER_ENABLED', true),
        'remote' => env('SPORTSBOT_UPDATER_REMOTE', 'origin'),
        'force_sync_target' => env('SPORTSBOT_UPDATER_FORCE_SYNC_TARGET', env('SPORTSBOT_UPDATER_REMOTE', 'origin') . '/main'),
        'admin_frontend_path' => env('SPORTSBOT_UPDATER_ADMIN_FRONTEND_PATH', 'resources/admin'),
        'repair_permissions_enabled' => env('SPORTSBOT_UPDATER_REPAIR_PERMISSIONS_ENABLED', true),
    ],
];
