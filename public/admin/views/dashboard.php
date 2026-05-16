<?php

declare(strict_types=1);

/**
 * Admin dashboard view.
 *
 * Expected variables: $stateCounts, $cardJobs, $config, $rateLimitInfo, $env, $tvChannels, $customerFollows, $csrf
 */
?>
<div class="card ops-hero" id="dashboard">
    <div class="ops-grid">
        <div>
            <h2>Operations Dashboard</h2>
            <div class="metric-grid" style="margin-top:14px">
                <div class="metric"><span>Pending Cards</span><strong><?= (int) ($stateCounts['cards_pending'] ?? 0) ?></strong></div>
                <div class="metric"><span>Sent Card Jobs</span><strong><?= (int) ($stateCounts['cards_sent'] ?? 0) ?></strong></div>
                <div class="metric"><span>Failed Cards</span><strong><?= (int) ($stateCounts['cards_failed'] ?? 0) ?></strong></div>
                <div class="metric"><span>Outbox Failed</span><strong><?= (int) ($stateCounts['outbox_failed'] ?? 0) ?></strong></div>
                <div class="metric"><span>Tracked Matches</span><strong><?= (int) $stateCounts['matches'] ?></strong></div>
                <div class="metric"><span>Sent Alerts</span><strong><?= (int) $stateCounts['alerts'] ?></strong></div>
                <div class="metric"><span>Decision Logs</span><strong><?= (int) ($stateCounts['decisions'] ?? 0) ?></strong></div>
                <div class="metric"><span>TV Channels</span><strong><?= count($tvChannels) ?></strong></div>
                <div class="metric"><span>Customer Follows</span><strong><?= (int) ($customerFollows['counts']['total'] ?? 0) ?></strong></div>
            </div>
        </div>
        <div>
            <h2>Next Card Queue</h2>
            <div class="queue-list">
                <?php if ($cardJobs === []): ?>
                    <p class="muted">No card jobs queued yet. Run a dry check or send cards now.</p>
                <?php else: ?>
                    <?php foreach (array_slice($cardJobs, 0, 5) as $job): ?>
                        <?php
                            $status = (string) ($job['status'] ?? '');
                            $badgeClass = $status === 'failed' ? 'danger' : ($status === 'pending' ? 'warn' : '');
                        ?>
                        <div class="queue-item">
                            <div>
                                <b><?= htmlspecialchars((string) $job['card_type']) ?></b>
                                <p class="muted"><?= htmlspecialchars((string) ($job['sport'] ?: $job['route_key'])) ?> · <?= (int) $job['page_count'] ?> page(s)</p>
                            </div>
                            <span class="badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="topline">
        <span class="pill">State DB <strong><?= is_file($config['paths']['state_db']) ? 'Ready' : 'Pending' ?></strong></span>
        <span class="pill">Notifications <strong><?= !empty($config['telegram']['disable_notification']) ? 'Quiet' : 'Normal' ?></strong></span>
        <span class="pill">Last API Call <strong><?= isset($rateLimitInfo['last_request_ago_ms']) ? (int) $rateLimitInfo['last_request_ago_ms'] . 'ms ago' : 'None' ?></strong></span>
        <span class="pill">Routes <strong><?= admin_env_value($env, 'BOT_TELEGRAM_ROUTES_JSON') !== '' ? 'Configured' : 'Default only' ?></strong></span>
    </div>
</div>