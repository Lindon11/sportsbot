<?php

declare(strict_types=1);

/**
 * Admin routing/profiles view.
 *
 * Expected variables: $config
 */
?>
<div class="card page-wide" id="profiles">
    <h2>Sport Profiles</h2>
    <table class="table">
        <thead><tr><th>Sport</th><th>Score</th><th>Start</th><th>Period</th><th>Final</th></tr></thead>
        <tbody>
        <?php foreach ($config['coverage']['enabled_sports'] ?? [] as $sport): ?>
            <?php $profile = fb_sport_profile($config, (string) $sport); ?>
            <tr>
                <td><?= htmlspecialchars((string) ($profile['label'] ?? $sport)) ?></td>
                <td><?= htmlspecialchars((string) ($profile['score_label'] ?? 'Score')) ?></td>
                <td><?= htmlspecialchars((string) ($profile['start_label'] ?? 'Started')) ?></td>
                <td><?= htmlspecialchars((string) ($profile['period_label'] ?? 'Status')) ?></td>
                <td><?= htmlspecialchars((string) ($profile['final_label'] ?? 'Final')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>