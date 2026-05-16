<?php

declare(strict_types=1);

/**
 * Admin publishing/studio view.
 *
 * Expected variables: $csrf, $cardJobs, $cardDispatches, $config, $customerFollows
 */
?>
<div class="card span-6" id="card-studio">
    <h2>Card Studio</h2>
    <p class="muted">Preview, send, and retry the new burst cards without waiting for cron.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="field-grid">
            <div>
                <label for="preview_card_type">Preview Card Type</label>
                <select id="preview_card_type" name="preview_card_type">
                    <option value="">First due card</option>
                    <option value="FIXTURES_BURST">Fixtures burst</option>
                    <option value="KICKOFF_SOON">Kick-off soon</option>
                    <option value="LIVE_NOW">Live now</option>
                    <option value="TV_GUIDE">TV guide</option>
                    <option value="TV_NOW">TV now</option>
                    <option value="RESULTS_ROUNDUP">Results roundup</option>
                    <option value="MORNING_PLANNER">Morning planner</option>
                    <option value="TOMORROW_LOOKAHEAD">Tomorrow lookahead</option>
                    <option value="WEEKEND_PLANNER">Weekend planner</option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button name="action" value="generate_card_preview" type="submit">Generate Preview</button>
            <button class="secondary" name="action" value="send_cards_now" type="submit">Send Due Cards</button>
            <button class="warning" name="action" value="retry_failed_cards" type="submit">Retry Failed</button>
        </div>
    </form>
    <?php if ($cardDispatches !== []): ?>
        <table class="table" style="margin-top:14px">
            <thead><tr><th>Job</th><th>Chat</th><th>Page</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($cardDispatches, 0, 6) as $dispatch): ?>
                <tr>
                    <td><?= htmlspecialchars(substr((string) $dispatch['job_key'], 0, 28)) ?></td>
                    <td><?= htmlspecialchars(admin_mask((string) $dispatch['chat_id'])) ?></td>
                    <td><?= (int) $dispatch['page_no'] ?></td>
                    <td><?= htmlspecialchars((string) $dispatch['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6" id="scheduler">
    <h2>Scheduler</h2>
    <div class="status-list">
        <div>Mode: <b><?= !empty($config['cards']['bursts_enabled']) ? 'Smart event bursts' : 'Legacy daily cards' ?></b></div>
        <div>Card types: <b><?= htmlspecialchars(implode(', ', $config['cards']['types_enabled'] ?? [])) ?></b></div>
        <div>Content packs: <b><?= htmlspecialchars(implode(', ', $config['content']['packs_enabled'] ?? [])) ?></b></div>
        <div>Cooldown: <b><?= (int) ($config['cards']['burst_cooldown_minutes'] ?? 60) ?> min</b></div>
        <div>Public card cap: <b><?= (int) ($config['cards']['max_items_per_type'] ?? 4) ?> per type</b></div>
        <div>Max pages/run: <b><?= (int) ($config['cards']['max_pages_per_run'] ?? 12) ?></b></div>
        <div>Max jobs/run: <b><?= (int) ($config['cards']['max_sends_per_run'] ?? 12) ?></b></div>
    </div>
    <div class="actions">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button name="action" value="dry_run" type="submit">Show Due Cards</button>
        </form>
    </div>
</div>

<div class="card span-6" id="customer-guide">
    <h2>Customer Guide</h2>
    <div class="status-list">
        <div>Guide: <b><?= !empty($config['customer']['guide_enabled']) ? htmlspecialchars(fb_customer_guide_time($config)) : 'Disabled' ?></b></div>
        <div>Lookahead: <b><?= (int) ($config['customer']['guide_lookahead_hours'] ?? 24) ?> hours</b></div>
        <div>Follow buttons: <b><?= !empty($config['customer']['follow_buttons_enabled']) ? 'On' : 'Off' ?></b></div>
        <div>Users following: <b><?= (int) ($customerFollows['counts']['users'] ?? 0) ?></b></div>
        <div>Teams: <b><?= (int) ($customerFollows['counts']['teams'] ?? 0) ?></b></div>
        <div>Players: <b><?= (int) ($customerFollows['counts']['players'] ?? 0) ?></b></div>
        <div>Feeds: <b><?= (int) ($customerFollows['counts']['feeds'] ?? 0) ?></b></div>
    </div>
    <form class="actions" method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button name="action" value="send_customer_guide_test" type="submit">Send Guide Test</button>
        <a class="button secondary" href="<?= htmlspecialchars(admin_view_url('settings')) ?>#customer-guide-settings">Guide Settings</a>
    </form>
    <?php if (($customerFollows['recent'] ?? []) === []): ?>
        <p class="muted">No customer follows yet. Guide messages can include buttons such as Follow Arsenal or Follow England.</p>
    <?php else: ?>
        <table class="table" style="margin-top:14px">
            <thead><tr><th>Subject</th><th>Kind</th><th>User</th><th>Target</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($customerFollows['recent'], 0, 8) as $follow): ?>
                <?php
                    $target = (string) ($follow['chat_id'] ?? '');
                    if (!empty($follow['message_thread_id'])) {
                        $target .= ':' . (int) $follow['message_thread_id'];
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) $follow['subject']) ?></td>
                    <td><?= htmlspecialchars((string) $follow['kind']) ?></td>
                    <td><?= htmlspecialchars((string) ($follow['username'] ?: $follow['telegram_user_id'])) ?></td>
                    <td><?= htmlspecialchars(admin_mask($target)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>