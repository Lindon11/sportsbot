<?php

declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../generate_image.php';
require_once __DIR__ . '/../telegram.php';

function fb_v2_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/footballbot-v2-' . getmypid();
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
$config['images']['render_engine'] = 'gd';
$config['images']['render_user_data_dir'] = $root . '/cache/chrome';
$config['content']['packs_enabled'] = ['morning_planner', 'tv_now', 'weekend'];
$config['sports']['profiles_json'] = '{"Basketball":{"start_label":"Tip-off","period_label":"Quarter"}}';
$config['telegram']['chat_id'] = '-100default';
$config['telegram']['message_thread_id'] = 42;
$config['telegram']['extra_chat_ids'] = [];
$config['telegram']['routes'] = ['Basketball' => [['chat_id' => '-100basketball', 'thread_id' => 777]]];

$db = fb_open_db($config);

foreach (['schema_migrations', 'alert_decisions', 'telegram_outbox', 'render_health_checks'] as $table) {
    $exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . SQLite3::escapeString($table) . "'");
    fb_v2_test_assert($exists === $table, $table . ' table exists');
}

$outboxColumns = fb_db_columns($db, 'telegram_outbox');
fb_v2_test_assert(isset($outboxColumns['message_thread_id']), 'telegram_outbox tracks forum topic ID');

$profile = fb_sport_profile($config, 'Basketball');
fb_v2_test_assert(($profile['start_label'] ?? '') === 'Tip-off', 'sport profile override applies');
fb_v2_test_assert(($profile['period_label'] ?? '') === 'Quarter', 'sport profile period label applies');
fb_v2_test_assert(fb_content_pack_enabled($config, 'tv_now'), 'content pack enabled check works');

$outboxKey = fb_outbox_key('test-alert', 'sendPhoto', '-100default');
fb_outbox_start($db, $outboxKey, 'test-alert', 'sendPhoto', '-100default', null, null, '/tmp/test.png', 'Caption', ['scope' => 'test']);
fb_outbox_finish($db, $outboxKey, 'sent', '123', null);
fb_v2_test_assert(fb_outbox_sent($db, $outboxKey), 'outbox sent idempotency works');
fb_v2_test_assert(fb_outbox_key('test-alert', 'sendPhoto', '-100default', 1) !== fb_outbox_key('test-alert', 'sendPhoto', '-100default', 2), 'outbox keys include topic ID');

$defaultTargets = fb_telegram_default_targets($config);
$basketballTargets = fb_telegram_route_targets($config, 'Basketball');
fb_v2_test_assert(($defaultTargets[0]['message_thread_id'] ?? null) === 42, 'default Telegram target includes topic ID');
fb_v2_test_assert(($basketballTargets[0]['message_thread_id'] ?? null) === 777, 'sport route target includes topic ID');
fb_save_telegram_topic($db, '-1001234567890', 777, 'Basketball', ['icon_color' => 1], 'test');
$topics = fb_list_telegram_topics($db, '-1001234567890');
fb_v2_test_assert(($topics[0]['message_thread_id'] ?? null) === 777, 'Telegram topic IDs are stored');
$menuMarkup = fb_bot_menu_reply_markup($config, $db, '-1001234567890');
fb_v2_test_assert(($menuMarkup['inline_keyboard'][3][0]['url'] ?? '') === 'https://t.me/c/1234567890/777', 'bot menu includes discovered topic links');

$callbackData = fb_register_follow_button($db, 'team', 'Basketball', 'Boston Celtics');
$follow = $callbackData !== null ? fb_follow_button_payload($db, $callbackData) : null;
fb_v2_test_assert(is_array($follow) && $follow['subject'] === 'Boston Celtics', 'follow button payload round-trips');
fb_save_customer_follow($db, '-100basketball', 777, ['id' => '123', 'username' => 'viewer'], $follow);
$followCounts = fb_customer_follow_counts($db);
fb_v2_test_assert($followCounts['teams'] === 1 && $followCounts['users'] === 1, 'customer follow preference is stored');

$config['customer']['follow_buttons_enabled'] = true;
$config['customer']['max_follow_buttons'] = 4;
$replyMarkup = fb_customer_guide_reply_markup($config, $db, [[
    'sport' => 'Basketball',
    'home_team' => 'Boston Celtics',
    'away_team' => 'LA Lakers',
]]);
fb_v2_test_assert(isset($replyMarkup['inline_keyboard'][0][0]['callback_data']), 'customer guide follow buttons are generated');

$autoConfig = $config;
$autoConfig['images']['render_engine'] = 'auto';
fb_render_note_puppeteer_failure($autoConfig);
fb_v2_test_assert(!fb_render_allows_puppeteer($autoConfig), 'auto render circuit breaker disables Puppeteer after a failure');

$alert = fb_sample_alerts()[0];
$image = fb_generate_alert_image($config, $alert);
fb_v2_test_assert(is_file($image) && filesize($image) > 0, 'GD render fallback creates an image');

$renderHealth = fb_run_render_health_check($config, $db);
fb_v2_test_assert($renderHealth['status'] === 'ok', 'render health check succeeds with GD engine');

$checks = fb_system_health($config, $db);
fb_v2_test_assert($checks !== [], 'system health returns checks');

echo "v2 foundation tests passed\n";
