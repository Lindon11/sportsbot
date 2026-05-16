<?php

declare(strict_types=1);

/**
 * Admin system/operations view.
 *
 * Expected variables: $env, $config, $rateLimitInfo, $csrf
 */
?>
<aside class="card page-wide" id="ops">
    <h2>Operations</h2>
    <div class="status-list">
        <div>Telegram token: <b><?= htmlspecialchars(admin_mask($env['TELEGRAM_BOT_TOKEN'] ?? '')) ?></b></div>
        <div>Primary chat: <b><?= htmlspecialchars(admin_mask($env['TELEGRAM_CHAT_ID'] ?? '')) ?></b></div>
        <div>Default topic: <b><?= admin_env_value($env, 'TELEGRAM_MESSAGE_THREAD_ID') !== '' ? htmlspecialchars(admin_env_value($env, 'TELEGRAM_MESSAGE_THREAD_ID')) : 'None' ?></b></div>
        <div>Error chat: <b><?= htmlspecialchars(admin_mask($env['TELEGRAM_ERROR_CHAT_ID'] ?? 'same as primary')) ?></b></div>
        <div>Extra groups: <b><?= admin_env_value($env, 'TELEGRAM_EXTRA_CHAT_IDS') !== '' ? htmlspecialchars(admin_env_value($env, 'TELEGRAM_EXTRA_CHAT_IDS')) : 'None' ?></b></div>
        <div>Sport routes: <b><?= admin_env_value($env, 'BOT_TELEGRAM_ROUTES_JSON') !== '' ? 'Configured' : 'Default only' ?></b></div>
        <div>Enabled sports: <b><?= htmlspecialchars(implode(', ', array_slice($config['coverage']['enabled_sports'] ?? [], 0, 6))) ?><?= count($config['coverage']['enabled_sports'] ?? []) > 6 ? '...' : '' ?></b></div>
        <div>TV listings: <b><?= !empty($config['tv']['enabled']) ? 'Enabled' : 'Disabled' ?></b></div>
        <div>Customer guide: <b><?= !empty($config['customer']['guide_enabled']) ? htmlspecialchars(fb_customer_guide_time($config)) : 'Disabled' ?></b></div>
        <div>Follow buttons: <b><?= !empty($config['customer']['follow_buttons_enabled']) ? 'Enabled' : 'Disabled' ?></b></div>
        <div>Button polling: <b><?= !empty($config['telegram']['updates_enabled']) ? 'Enabled' : 'Disabled' ?></b></div>
        <div>TV guide time: <b><?= htmlspecialchars(fb_tv_daily_alert_time($config)) ?></b></div>
        <div>TV output: <b><?= !empty($config['tv']['send_image']) ? 'Image' : 'Text' ?></b></div>
        <div>Daily card: <b><?= !empty($config['alerts']['send_daily_card']) ? htmlspecialchars($config['alerts']['daily_card_time']) : 'Disabled' ?></b></div>
        <div>Kick-off reminder: <b><?= !empty($config['alerts']['send_kickoff_reminder']) ? htmlspecialchars($config['alerts']['kickoff_reminder_minutes'] . ' min before') : 'Disabled' ?></b></div>
        <div>TV sports: <b><?= ($config['tv']['sports'] ?? []) !== [] ? htmlspecialchars(implode(', ', $config['tv']['sports'])) : 'All sports' ?></b></div>
        <div>Last API call: <b><?= isset($rateLimitInfo['last_request_ago_ms']) ? (int) $rateLimitInfo['last_request_ago_ms'] . 'ms ago' : 'No recorded request' ?></b></div>
    </div>
    <form class="actions" method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button name="action" value="test_telegram" type="submit">Send Test</button>
        <button class="secondary" name="action" value="generate_samples" type="submit">Generate Samples</button>
        <button class="secondary" name="action" value="generate_last_match" type="submit">Last Match Graphic</button>
        <button class="secondary" name="action" value="dry_run" type="submit">Dry Check</button>
        <button class="secondary" name="action" value="discover_coverage" type="submit">Discover Coverage</button>
        <button class="secondary" name="action" value="discover_tv_channels" type="submit">Discover TV Channels</button>
        <button class="secondary" name="action" value="test_telegram_routes" type="submit">Route Test</button>
        <button class="secondary" name="action" value="send_tv_schedule_test" type="submit">TV Guide Test</button>
        <button class="secondary" name="action" value="send_customer_guide_test" type="submit">Customer Guide Test</button>
        <button class="secondary" name="action" value="send_daily_card_test" type="submit">Daily Card Test</button>
        <button class="secondary" name="action" value="send_kickoff_reminder_test" type="submit">Kickoff Reminder Test</button>
        <button class="warning" name="action" value="clear_api_cache" type="submit">Clear Cache</button>
        <button class="danger" name="action" value="reset_state" type="submit" onclick="return confirm('Reset match state and sent alert history?')">Reset State</button>
    </form>

    <div class="form-section">
        <h3>Write Via Bot</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="send_manual_message">
            <label for="manual_message">Message</label>
            <textarea id="manual_message" name="manual_message" maxlength="4096" required></textarea>
            <div class="actions">
                <button type="submit">Send Message</button>
            </div>
        </form>
    </div>
</aside>