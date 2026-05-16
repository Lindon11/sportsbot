<?php

declare(strict_types=1);

/**
 * Admin health view.
 *
 * Expected variables: $healthChecks, $renderHealthChecks, $csrf
 */
?>
<div class="card page-wide" id="health">
    <h2>Health</h2>
    <div class="status-list">
        <?php foreach ($healthChecks as $check): ?>
            <div>
                <b><?= htmlspecialchars((string) $check['label']) ?></b>
                <span class="badge <?= !empty($check['ok']) ? '' : 'danger' ?>"><?= htmlspecialchars((string) $check['status']) ?></span>
                <span class="muted"><?= htmlspecialchars((string) $check['detail']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <form class="actions" method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button name="action" value="run_render_health" type="submit">Run Render Check</button>
        <button class="secondary" name="action" value="send_health_summary" type="submit">Send Health Summary</button>
    </form>
    <?php if ($renderHealthChecks !== []): ?>
        <table class="table" style="margin-top:14px">
            <thead><tr><th>Engine</th><th>Status</th><th>Duration</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($renderHealthChecks as $check): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $check['engine']) ?></td>
                    <td><?= htmlspecialchars((string) $check['status']) ?></td>
                    <td><?= (int) $check['duration_ms'] ?>ms</td>
                    <td><?= htmlspecialchars((string) $check['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>