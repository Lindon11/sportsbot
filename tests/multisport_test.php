<?php

declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../telegram.php';

function fb_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/footballbot-multisport-' . getmypid();
mkdir($root . '/cache/images', 0775, true);
mkdir($root . '/generated', 0775, true);
mkdir($root . '/logs', 0775, true);

$config = fb_config(true);
$config['paths']['cache'] = $root . '/cache';
$config['paths']['image_cache'] = $root . '/cache/images';
$config['paths']['generated'] = $root . '/generated';
$config['paths']['logs'] = $root . '/logs';
$config['paths']['state_db'] = $root . '/cache/state.sqlite';
$config['paths']['api_cache_lock'] = $root . '/cache/api-rate.lock';
$config['paths']['run_lock'] = $root . '/cache/check-live.lock';
$config['app']['log_file'] = $root . '/logs/bot.log';
$config['coverage']['enabled_sports'] = ['Soccer', 'Rugby', 'Basketball'];
$config['coverage']['enabled_league_ids'] = [];
$config['coverage']['legacy_soccer_league_ids'] = ['4328'];
$config['alerts']['send_score_updates'] = true;
$config['telegram']['chat_id'] = '-100default';
$config['telegram']['extra_chat_ids'] = ['-100extra'];
$config['telegram']['routes'] = [
    'Rugby' => ['-100rugby'],
    'Basketball' => '-100basketball',
];

$db = fb_open_db($config);
$eventColumns = fb_db_columns($db, 'event_state');
$alertColumns = fb_db_columns($db, 'sent_alerts');
fb_test_assert(isset($eventColumns['sport']), 'event_state has sport column');
fb_test_assert(isset($alertColumns['sport']), 'sent_alerts has sport column');

$rows = [
    [
        'idEvent' => 'soccer-ok',
        'idLeague' => '4328',
        'strSport' => 'Soccer',
        'strLeague' => 'English Premier League',
        'strHomeTeam' => 'Arsenal',
        'strAwayTeam' => 'Chelsea',
    ],
    [
        'idEvent' => 'soccer-filtered',
        'idLeague' => '999999',
        'strSport' => 'Soccer',
        'strLeague' => 'Other Soccer',
        'strHomeTeam' => 'A',
        'strAwayTeam' => 'B',
    ],
    [
        'idEvent' => 'rugby-ok',
        'idLeague' => '5555',
        'strSport' => 'Rugby Union',
        'strLeague' => 'Premiership Rugby',
        'strHomeTeam' => 'Bath',
        'strAwayTeam' => 'Sale Sharks',
    ],
    [
        'idEvent' => 'basketball-ok',
        'idLeague' => '4387',
        'strSport' => 'Basketball',
        'strLeague' => 'NBA',
        'strHomeTeam' => 'Boston Celtics',
        'strAwayTeam' => 'LA Lakers',
    ],
];

$allowed = fb_filter_allowed_matches($config, $rows);
fb_test_assert(count($allowed) === 3, 'coverage filter allows legacy soccer and enabled non-soccer sports');
fb_test_assert(array_values(array_map(static fn (array $row): string => (string) $row['idEvent'], $allowed)) === ['soccer-ok', 'rugby-ok', 'basketball-ok'], 'coverage filter preserves expected event IDs');

$rugby = fb_normalize_match(array_merge($allowed[1], [
    'intHomeScore' => '10',
    'intAwayScore' => '7',
    'strProgress' => '42',
]));
fb_test_assert($rugby['sport'] === 'Rugby', 'rugby union normalizes to Rugby');
fb_test_assert($rugby['home_score'] === 10 && $rugby['away_score'] === 7, 'rugby scores normalize');

$localKickoff = fb_event_datetime(['strTimestamp' => '2026-05-16T11:30:00Z'], new DateTimeZone('Europe/London'));
fb_test_assert($localKickoff instanceof DateTimeImmutable, 'timestamp kickoff parses');
$timedEvent = fb_with_local_event_time($allowed[1], $localKickoff);
$timedMatch = fb_normalize_match($timedEvent);
fb_test_assert($timedMatch['event_time'] === '12:30', 'UTC timestamp displays in configured local timezone');
fb_test_assert(str_starts_with($timedMatch['starts_at'], '2026-05-16T12:30:00'), 'local starts_at is stored with timezone offset');

$previous = [
    'status' => 'LIVE',
    'home_score' => 7,
    'away_score' => 7,
];
$alerts = fb_detect_generic_alerts($config, $db, $rugby, $previous);
fb_test_assert(count($alerts) === 1 && $alerts[0]['type'] === 'SCORE_UPDATE', 'generic score update detected');

$defaultRoute = fb_telegram_route_chat_ids($config, null);
$rugbyRoute = fb_telegram_route_chat_ids($config, 'Rugby Union');
$basketballRoute = fb_telegram_route_chat_ids($config, 'Basketball');
fb_test_assert($defaultRoute === ['-100default', '-100extra'], 'default Telegram route uses primary and extras');
fb_test_assert($rugbyRoute === ['-100rugby'], 'rugby route resolves via canonical sport');
fb_test_assert($basketballRoute === ['-100basketball'], 'string route resolves to a chat ID');

echo "multisport tests passed\n";
