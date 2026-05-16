<?php

declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../generate_image.php';
require_once __DIR__ . '/../telegram.php';

function fb_card_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/footballbot-cards-' . getmypid();
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
$config['app']['timezone'] = 'Europe/London';
$config['cards']['bursts_enabled'] = true;
$config['cards']['types_enabled'] = ['fixtures', 'kickoff_soon', 'live_now', 'tv_guide', 'results', 'tomorrow'];
$config['cards']['burst_cooldown_minutes'] = 60;
$config['cards']['max_pages_per_run'] = 10;
$config['cards']['max_sends_per_run'] = 10;
$config['cards']['max_items_per_type'] = 20;
$config['telegram']['chat_id'] = '-100default';
$config['telegram']['extra_chat_ids'] = ['-100extra'];
$config['telegram']['routes'] = [
    'Rugby' => ['-100rugby'],
    'Soccer' => ['-100soccer'],
];

$db = fb_open_db($config);
$jobColumns = fb_db_columns($db, 'card_jobs');
$dispatchColumns = fb_db_columns($db, 'card_dispatches');
fb_card_test_assert(isset($jobColumns['job_key']), 'card_jobs table exists');
fb_card_test_assert(isset($dispatchColumns['page_no']), 'card_dispatches has page_no');

$tz = new DateTimeZone('Europe/London');
$now = new DateTimeImmutable('2026-05-16 09:00:00', $tz);
$matches = [];

for ($i = 1; $i <= 10; $i++) {
    $sport = $i <= 4 ? 'Rugby' : 'Soccer';
    $league = $sport === 'Rugby' ? 'Premiership Rugby' : 'English Premier League';
    $matches[] = [
        'event_id' => 'fixture-' . $i,
        'sport' => $sport,
        'league_id' => $sport === 'Rugby' ? '5555' : '4328',
        'league_name' => $league,
        'event_name' => 'Team ' . $i . ' vs Opponent ' . $i,
        'home_team' => 'Team ' . $i,
        'away_team' => 'Opponent ' . $i,
        'home_score' => 0,
        'away_score' => 0,
        'status' => '',
        'progress' => null,
        'event_time' => $now->modify('+' . $i . ' hours')->format('H:i'),
        'date_event' => $now->format('Y-m-d'),
        'starts_at' => $now->modify('+' . $i . ' hours')->format(DateTimeInterface::ATOM),
        'venue' => $i === 1 ? 'Test Stadium' : '',
        'league_logo' => '',
        'home_badge' => '',
        'away_badge' => '',
        'tv_channels' => ($i === 1 || $i % 2 === 0) ? ['Sky Sports'] : [],
    ];
}

$sections = fb_group_card_matches_by_league($matches);
$pages = fb_card_paginate_sections($sections, 'matches', 8);
fb_card_test_assert(count($pages) === 2, '10 match rows split into two pages');

$jobs = fb_card_build_match_jobs(
    $config,
    $db,
    'FIXTURES_BURST',
    'fixtures',
    'Fixtures',
    'Next 24 hours',
    $matches,
    3,
    $now,
    $now->modify('+24 hours'),
    true
);

fb_card_test_assert(count($jobs) === 20, 'one card job per match is created for default and sport routes');
$storedJobs = fb_fetch_card_jobs($db, 10, ['pending']);
fb_card_test_assert(count($storedJobs) === 10, 'pending card jobs can be fetched with a limit');

$defaultJob = array_values(array_filter($jobs, static fn (array $job): bool => ($job['route_key'] ?? '') === 'default'))[0] ?? [];
$rugbyJob = array_values(array_filter($jobs, static fn (array $job): bool => ($job['sport'] ?? '') === 'Rugby'))[0] ?? [];
fb_card_test_assert((int) ($defaultJob['page_count'] ?? 0) === 1, 'single-match jobs store one page');
fb_card_test_assert(fb_card_chat_ids($config, $rugbyJob) === ['-100rugby'], 'sport job routes to sport chat');

$payload = $defaultJob['payload'] ?? json_decode((string) ($defaultJob['payload_json'] ?? '{}'), true);
$firstPage = $payload['pages'][0] ?? [];
fb_card_test_assert(($firstPage['timezone_label'] ?? '') === 'UK time BST', 'May card uses BST label');
fb_card_test_assert(($firstPage['kind'] ?? '') === 'match', 'card page contains one match');
fb_card_test_assert(($firstPage['match']['event_id'] ?? '') === 'fixture-1', 'first card keeps the fixture payload');
$caption = fb_card_caption($firstPage);
fb_card_test_assert(str_contains($caption, 'Team 1 vs Opponent 1'), 'match card caption contains the fixture title');
fb_card_test_assert(str_contains($caption, 'Sky Sports'), 'match card caption contains the TV channel');
fb_card_test_assert(str_contains($caption, 'Test Stadium'), 'match card caption contains the venue when available');
$replyMarkup = fb_card_reply_markup($config, $db, $firstPage, ['chat_id' => '-1001234567890', 'message_thread_id' => 44]);
fb_card_test_assert(isset($replyMarkup['inline_keyboard'][0][0]['callback_data']), 'match card action buttons are generated');
fb_card_test_assert(($replyMarkup['inline_keyboard'][1][0]['url'] ?? '') === 'https://t.me/c/1234567890/44', 'match card topic button links to the topic');
fb_card_test_assert(fb_card_timezone_label($config, new DateTimeImmutable('2026-12-01 12:00:00', $tz)) === 'UK time GMT', 'winter card uses GMT label');

$config['tv']['enabled'] = true;
$config['tv']['include_in_match_previews'] = true;
$config['tv']['channels'] = ['sky_sports_main_event'];
$config['tv']['discovery_countries'] = ['united_kingdom'];
$fallbackTvPayload = [
    'lookup' => [
        [
            'idEvent' => 'fallback-tv',
            'strChannel' => 'BeIn Sports Max 4',
            'strCountry' => 'France',
            'strEventCountry' => 'United States',
        ],
        [
            'idEvent' => 'fallback-tv',
            'strChannel' => 'MLB.tv',
            'strCountry' => 'United States',
            'strEventCountry' => 'United States',
        ],
    ],
];
fb_api_cache_set($db, 'tsdb:' . sha1('/lookup/event_tv/fallback-tv'), json_encode($fallbackTvPayload), 200, 3600);
fb_card_test_assert(fb_fetch_event_tv_channels($config, $db, 'fallback-tv') === ['MLB.tv'], 'event TV lookup falls back to event-country channels when configured guide channels do not match');

fb_record_card_dispatch($db, (string) $defaultJob['job_key'], '-100default', 1, 'sent', '123', '/tmp/card.png', null);
fb_card_test_assert(fb_card_dispatch_sent($db, (string) $defaultJob['job_key'], '-100default', 1), 'sent dispatch idempotency is recorded');

$image = fb_generate_matchday_card_image($config, $firstPage);
fb_card_test_assert(is_file($image) && filesize($image) > 0, 'matchday card renderer creates an image');

echo "card scheduler tests passed\n";
