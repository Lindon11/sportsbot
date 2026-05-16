<?php

declare(strict_types=1);

/**
 * Admin navigation sidebar partial.
 *
 * Expected variables: $activeView, $stateCounts, $healthChecks, $cardJobs,
 *   $latestImages, $config, $env, $routeMatrix, $coverageLeagues,
 *   $customerFollows, $rateLimitInfo, $csrf
 */
?>
<aside class="sidebar-card">
    <div class="sidebar-head">
        <div>
            <h2>Control Centre</h2>
            <p class="muted">Private group operations</p>
        </div>
        <span class="badge <?= (int) ($stateCounts['outbox_failed'] ?? 0) > 0 ? 'danger' : '' ?>"><?= (int) ($stateCounts['outbox_failed'] ?? 0) ?> fail</span>
    </div>
    <nav class="nav nav-tree" aria-label="Admin sections">
        <details class="nav-group" <?= in_array($activeView, ['dashboard', 'health', 'logs'], true) ? 'open' : '' ?>>
            <summary>Overview</summary>
            <?= admin_nav_link($activeView, 'dashboard', 'Dashboard', (int) ($stateCounts['matches'] ?? 0)) ?>
            <?= admin_nav_link($activeView, 'health', 'Health', count($healthChecks)) ?>
            <?= admin_nav_link($activeView, 'logs', 'Logs', 2) ?>
        </details>
        <details class="nav-group" <?= in_array($activeView, ['publishing', 'activity', 'media'], true) ? 'open' : '' ?>>
            <summary>Publishing</summary>
            <?= admin_nav_link($activeView, 'publishing', 'Studio', count($cardJobs)) ?>
            <?= admin_nav_link($activeView, 'activity', 'Activity', (int) (($stateCounts['outbox_pending'] ?? 0) + ($stateCounts['outbox_failed'] ?? 0))) ?>
            <?= admin_nav_link($activeView, 'media', 'Images', count($latestImages)) ?>
        </details>
        <details class="nav-group" <?= in_array($activeView, ['routing', 'settings'], true) ? 'open' : '' ?>>
            <summary>Routing</summary>
            <?= admin_nav_link($activeView, 'routing', 'Profiles', count($config['coverage']['enabled_sports'] ?? [])) ?>
            <?= admin_nav_link($activeView, 'settings', 'Routes And Rules', count($routeMatrix)) ?>
        </details>
        <details class="nav-group" <?= in_array($activeView, ['data', 'system'], true) ? 'open' : '' ?>>
            <summary>Data Sources</summary>
            <?= admin_nav_link($activeView, 'data', 'Coverage And TV', count($coverageLeagues)) ?>
            <?= admin_nav_link($activeView, 'system', 'Operations', (int) ($stateCounts['cache'] ?? 0)) ?>
            <?= admin_nav_link($activeView, 'settings', 'Settings', '>') ?>
        </details>
    </nav>

    <div class="sidebar-widgets">
        <section class="side-widget">
            <div class="widget-title">System</div>
            <div class="widget-row"><span>Telegram</span><b><?= !empty($env['TELEGRAM_BOT_TOKEN']) ? 'Ready' : 'Missing' ?></b></div>
            <div class="widget-row"><span>TheSportsDB</span><b><?= !empty($env['THESPORTSDB_API_KEY']) ? 'Ready' : 'Missing' ?></b></div>
            <div class="widget-row"><span>Renderer</span><b><?= htmlspecialchars((string) ($config['images']['render_engine'] ?? 'auto')) ?></b></div>
        </section>
        <section class="side-widget">
            <div class="widget-title">Queue</div>
            <div class="widget-meter"><span style="width: <?= min(100, max(4, ((int) ($stateCounts['cards_pending'] ?? 0)) * 12)) ?>%"></span></div>
            <div class="widget-row"><span>Pending cards</span><b><?= (int) ($stateCounts['cards_pending'] ?? 0) ?></b></div>
            <div class="widget-row"><span>Outbox pending</span><b><?= (int) ($stateCounts['outbox_pending'] ?? 0) ?></b></div>
            <div class="widget-row"><span>Follows</span><b><?= (int) ($customerFollows['counts']['total'] ?? 0) ?></b></div>
        </section>
        <section class="side-widget">
            <div class="widget-title">Runtime</div>
            <div class="widget-row"><span>Cards</span><b><?= !empty($config['cards']['bursts_enabled']) ? 'Burst' : 'Daily' ?></b></div>
            <div class="widget-row"><span>Timezone</span><b><?= htmlspecialchars((string) $config['app']['timezone']) ?></b></div>
            <div class="widget-row"><span>Last API</span><b><?= isset($rateLimitInfo['last_request_ago_ms']) ? (int) $rateLimitInfo['last_request_ago_ms'] . 'ms' : 'None' ?></b></div>
        </section>
    </div>

    <form class="mini-actions" method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button name="action" value="dry_run" type="submit">Dry Check</button>
        <button class="secondary" name="action" value="generate_card_preview" type="submit">Preview Card</button>
        <button class="secondary" name="action" value="send_cards_now" type="submit">Send Cards Now</button>
        <button class="warning" name="action" value="retry_failed_cards" type="submit">Retry Failed Cards</button>
    </form>
</aside>