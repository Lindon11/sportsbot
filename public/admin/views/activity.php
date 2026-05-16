<?php

declare(strict_types=1);

/**
 * Admin activity view.
 *
 * Expected variables: $outboxItems, $alertDecisions, $cardJobs, $recentAlerts, $recentMatches, $stateCounts
 */
?>
<div class="card span-6" id="outbox">
    <h2>Telegram Outbox</h2>
    <?php if ($outboxItems === []): ?>
        <p class="muted">No outbox deliveries recorded yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Method</th><th>Target</th><th>Status</th><th>Attempts</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($outboxItems as $item): ?>
                <?php
                    $target = (string) ($item['chat_id'] ?? '');
                    if (!empty($item['message_thread_id'])) {
                        $target .= ':' . (int) $item['message_thread_id'];
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) $item['method']) ?></td>
                    <td><?= htmlspecialchars(admin_mask($target)) ?></td>
                    <td><?= htmlspecialchars((string) $item['status']) ?></td>
                    <td><?= (int) $item['attempts'] ?></td>
                    <td><?= htmlspecialchars((string) $item['updated_at']) ?></td>
                </tr>
                <?php if (!empty($item['last_error'])): ?>
                    <tr><td colspan="5" class="muted"><?= htmlspecialchars((string) $item['last_error']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6">
    <h2>Alert Decisions</h2>
    <?php if ($alertDecisions === []): ?>
        <p class="muted">No alert decisions recorded yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Decision</th><th>Type</th><th>Sport</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($alertDecisions as $decision): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $decision['decision']) ?></td>
                    <td><?= htmlspecialchars((string) $decision['alert_type']) ?></td>
                    <td><?= htmlspecialchars((string) $decision['sport']) ?></td>
                    <td><?= htmlspecialchars((string) $decision['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6" id="activity">
    <h2>Card Activity</h2>
    <?php if ($cardJobs === []): ?>
        <p class="muted">No card jobs yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Type</th><th>Route</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($cardJobs, 0, 8) as $job): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $job['card_type']) ?></td>
                    <td><?= htmlspecialchars((string) ($job['sport'] ?: $job['route_key'])) ?></td>
                    <td><?= htmlspecialchars((string) $job['status']) ?> <?= !empty($job['failed_dispatches']) ? '(' . (int) $job['failed_dispatches'] . ' failed)' : '' ?></td>
                    <td><?= htmlspecialchars((string) $job['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6">
    <h2>Recent Alerts</h2>
    <?php if ($recentAlerts === []): ?>
        <p class="muted">No alerts sent yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Type</th><th>Sport</th><th>Event</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($recentAlerts as $alert): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $alert['alert_type']) ?></td>
                    <td><?= htmlspecialchars((string) ($alert['sport'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) $alert['event_id']) ?></td>
                    <td><?= htmlspecialchars((string) $alert['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6">
    <h2>Recent Match State</h2>
    <?php if ($recentMatches === []): ?>
        <p class="muted">No stored match state yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Event</th><th>Sport</th><th>Status</th><th>Score</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($recentMatches as $match): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $match['event_id']) ?></td>
                    <td><?= htmlspecialchars((string) ($match['sport'] ?? '')) ?></td>
                    <td><?= htmlspecialchars(trim((string) $match['status'] . ' ' . (string) $match['progress'])) ?></td>
                    <td><?= (int) $match['home_score'] ?>-<?= (int) $match['away_score'] ?></td>
                    <td><?= htmlspecialchars((string) $match['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6">
    <h2>Alert Breakdown</h2>
    <?php if ($stateCounts['alert_types'] === []): ?>
        <p class="muted">No alerts sent yet.</p>
    <?php else: ?>
        <div class="status-list">
            <?php foreach ($stateCounts['alert_types'] as $type => $count): ?>
                <div><b><?= htmlspecialchars((string) $type) ?></b> <span class="muted">x<?= (int) $count ?></span></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>