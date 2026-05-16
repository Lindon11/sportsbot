<?php

declare(strict_types=1);

/**
 * Admin data view.
 *
 * Expected variables: $rateLimitInfo, $config, $cacheEntries, $tvChannels, $coverageSports, $coverageLeagues, $tvChannelRegistry
 */
?>
<div class="card span-6">
    <h2>Cache And Rate Limit</h2>
    <div class="status-list">
        <div>Min interval: <b><?= (int) ($rateLimitInfo['min_interval_ms'] ?? $config['thesportsdb']['min_request_interval_ms']) ?>ms</b></div>
        <div>Livescore TTL: <b><?= (int) ($rateLimitInfo['livescore_cache_ttl'] ?? 0) ?>s</b></div>
        <div>Timeline TTL: <b><?= (int) ($rateLimitInfo['timeline_cache_ttl'] ?? 0) ?>s</b></div>
        <div>Lookup TTL: <b><?= (int) ($rateLimitInfo['lookup_cache_ttl'] ?? 0) ?>s</b></div>
        <div>TV TTL: <b><?= (int) ($rateLimitInfo['tv_cache_ttl'] ?? 0) ?>s</b></div>
    </div>
    <?php if ($cacheEntries !== []): ?>
        <table class="table" style="margin-top:14px">
            <thead><tr><th>Cache Key</th><th>Status</th><th>Expires</th></tr></thead>
            <tbody>
            <?php foreach ($cacheEntries as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars(substr((string) $entry['cache_key'], 0, 22)) ?></td>
                    <td><?= (int) $entry['status_code'] ?></td>
                    <td><?= date('H:i:s', (int) $entry['expires_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card span-6">
    <h2>Football Leagues</h2>
    <div class="status-list">
        <?php foreach ($config['leagues']['allowed'] as $id => $league): ?>
            <div><b><?= htmlspecialchars((string) $league['name']) ?></b> <span class="muted">ID <?= htmlspecialchars((string) $id) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card span-6">
    <h2>Enabled TV Channels</h2>
    <?php if ($tvChannels === []): ?>
        <p class="muted">No TV channels configured.</p>
    <?php else: ?>
        <div class="status-list">
            <?php foreach ($tvChannels as $channel): ?>
                <div><b><?= htmlspecialchars((string) $channel['label']) ?></b> <span class="muted"><?= htmlspecialchars((string) $channel['slug']) ?></span></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Coverage Registry</h2>
    <?php if ($coverageSports === [] && $coverageLeagues === []): ?>
        <p class="muted">No coverage registry yet. Run Discover Coverage from Operations.</p>
    <?php else: ?>
        <div class="status-list">
            <?php foreach (array_slice($coverageSports, 0, 18) as $sport): ?>
                <div><b><?= htmlspecialchars((string) $sport['sport_name']) ?></b> <span class="muted"><?= !empty($sport['enabled']) ? 'enabled' : 'off' ?><?= !empty($sport['live_available']) ? ' - live seen' : '' ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php if ($coverageLeagues !== []): ?>
            <table class="table" style="margin-top:14px">
                <thead><tr><th>League</th><th>Sport</th><th>Country</th><th>State</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($coverageLeagues, 0, 60) as $league): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $league['league_name']) ?></td>
                        <td><?= htmlspecialchars((string) $league['sport']) ?></td>
                        <td><?= htmlspecialchars((string) $league['country']) ?></td>
                        <td><?= !empty($league['enabled']) ? 'Enabled' : 'Off' ?><?= !empty($league['live_available']) ? ' / Live' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Discovered TV Channel Registry</h2>
    <?php if ($tvChannelRegistry === []): ?>
        <p class="muted">No discovered channels yet. Run Discover TV Channels from Operations.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Channel</th><th>Slug</th><th>Sports</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($tvChannelRegistry, 0, 40) as $channel): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $channel['channel_name']) ?></td>
                    <td><?= htmlspecialchars((string) $channel['channel_slug']) ?></td>
                    <td><?= htmlspecialchars(implode(', ', array_slice($channel['sports'], 0, 4))) ?></td>
                    <td><?= htmlspecialchars((string) $channel['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>