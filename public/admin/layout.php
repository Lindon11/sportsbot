<?php

declare(strict_types=1);

/**
 * Admin layout shell — HTML head, CSS, and page structure.
 *
 * Expected variables (set in the router before including this file):
 *   $loggedIn, $hasPassword, $csrf, $flash, $activeView, $activeViewMeta,
 *   $env, $config, $stateCounts, $rateLimitInfo, $tvChannels, $tvChannelRegistry,
 *   $coverageSports, $coverageLeagues, $sportProfiles, $routeMatrix,
 *   $healthChecks, $outboxItems, $customerFollows, $alertDecisions,
 *   $renderHealthChecks, $recentAlerts, $recentMatches, $cacheEntries,
 *   $cardJobs, $cardDispatches, $latestImages, $botLog, $cronLog,
 *   $availableLeagues, $allowedLeagueIds, $availableSports, $enabledSportKeys,
 *   $telegramRouteSports, $telegramRouteRows, $telegramTopics,
 *   $configuredCoverageLeagueIds, $manualCoverageLeagueIds,
 *   $registrySlugs, $configuredTvSlugs, $manualTvSlugs,
 *   $adminViews, $manualCoverageLeagueIds
 */

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sports Alert Bot Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ── Navy Dark Theme — easy on the eyes, Telegram-style ── */
        :root {
            color-scheme: dark;
            --bg: #0a0e1a;
            --surface: #0f1628;
            --surface-2: #141e35;
            --surface-3: #1a2742;
            --field: #0b1020;
            --line: rgba(148, 163, 194, 0.12);
            --line-strong: rgba(148, 163, 194, 0.22);
            --text: #e8edf5;
            --muted: #8b9dc3;
            --soft: #6b7fa3;
            --accent: #4a90d9;
            --accent-2: #3d7fc4;
            --danger: #ff4a5c;
            --warn: #ffbe4b;
            --info: #83a7ff;
            --shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
        }

        /* ── Bootstrap dark overrides — kill all white backgrounds ── */
        .card, .badge, .table, .table th, .table td,
        .form-control, .form-select, .form-check-input,
        .list-group-item, .alert, .modal-content, .dropdown-menu,
        .btn-light, .btn-outline-light, .navbar, .offcanvas,
        .popover, .toast, .progress, .accordion-item,
        .accordion-body, .accordion-button, .page-link,
        .breadcrumb-item, .list-group, .panel, .well {
            background-color: var(--surface) !important;
            color: var(--text) !important;
            border-color: var(--line) !important;
        }

        .table th { background-color: var(--surface-2) !important; }
        .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(148, 163, 194, 0.04) !important; }
        .table-hover tbody tr:hover { background-color: rgba(74, 144, 217, 0.08) !important; }
        .form-control, .form-select { background-color: var(--field) !important; color: var(--text) !important; border-color: var(--line) !important; }
        .form-control:focus, .form-select:focus { border-color: var(--accent) !important; box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15) !important; }
        .form-check-input { background-color: var(--field) !important; border-color: var(--line) !important; }
        .form-check-input:checked { background-color: var(--accent) !important; border-color: var(--accent) !important; }
        .form-check-input:focus { box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15) !important; }
        .modal-backdrop { background-color: rgba(0, 0, 0, 0.7) !important; }
        .text-muted { color: var(--muted) !important; }
        .text-dark { color: var(--text) !important; }
        a { color: var(--accent); }
        a:hover { color: var(--accent-2); }
        ::selection { background: rgba(74, 144, 217, 0.35); color: #fff; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 194, 0.2); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 194, 0.35); }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        a {
            color: inherit;
        }

        .shell {
            width: min(1440px, calc(100% - 28px));
            margin: 0 auto;
            padding: 18px 0 40px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin: 0 0 14px;
            padding: 14px 18px;
            background: rgba(10, 14, 26, 0.88);
            border: 1px solid var(--line);
            border-radius: 10px;
            backdrop-filter: blur(12px);
        }

        header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        header .muted {
            margin: 2px 0 0;
            font-size: 12px;
        }

        .flash {
            margin: 0 0 14px;
        }

        .flash .notice {
            padding: 10px 14px;
            margin: 0 0 6px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .flash .notice.success {
            border-color: rgba(74, 222, 128, 0.3);
            background: rgba(74, 222, 128, 0.08);
            color: #4ade80;
        }

        .flash .notice.error {
            border-color: rgba(255, 74, 92, 0.3);
            background: rgba(255, 74, 92, 0.08);
            color: #ff4a5c;
        }

        .flash .notice.info {
            border-color: rgba(131, 167, 255, 0.3);
            background: rgba(131, 167, 255, 0.08);
            color: #83a7ff;
        }

        .flash .notice.html {
            border-color: rgba(148, 163, 194, 0.2);
            background: var(--surface-2);
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 18px;
        }

        .card h2, .card h3 {
            margin: 0 0 10px;
        }

        .card h2 {
            font-size: 16px;
            font-weight: 600;
        }

        .card h3 {
            font-size: 14px;
            font-weight: 600;
        }

        .muted {
            color: var(--muted);
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: var(--surface-2);
            color: var(--muted);
            border: 1px solid var(--line);
        }

        .badge.danger {
            background: rgba(255, 74, 92, 0.12);
            color: var(--danger);
            border-color: rgba(255, 74, 92, 0.3);
        }

        .badge.warn {
            background: rgba(255, 190, 75, 0.12);
            color: var(--warn);
            border-color: rgba(255, 190, 75, 0.3);
        }

        button, .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }

        button:hover, .button:hover {
            background: var(--accent-2);
            border-color: var(--accent-2);
        }

        button.secondary, .button.secondary {
            background: var(--surface-2);
            color: var(--text);
            border-color: var(--line-strong);
        }

        button.secondary:hover, .button.secondary:hover {
            background: var(--surface-3);
        }

        button.warning, .button.warning {
            background: rgba(255, 190, 75, 0.12);
            color: var(--warn);
            border-color: rgba(255, 190, 75, 0.3);
        }

        button.warning:hover, .button.warning:hover {
            background: rgba(255, 190, 75, 0.2);
        }

        button.danger, .button.danger {
            background: rgba(255, 74, 92, 0.12);
            color: var(--danger);
            border-color: rgba(255, 74, 92, 0.3);
        }

        button.danger:hover, .button.danger:hover {
            background: rgba(255, 74, 92, 0.2);
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            margin: 0 0 4px;
            color: var(--muted);
        }

        input[type="text"],
        input[type="password"],
        input[type="number"],
        input[type="email"],
        input[type="url"],
        input[type="search"],
        input[type="tel"],
        select,
        textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--field);
            color: var(--text);
            font-size: 13px;
            font-family: inherit;
            margin: 0 0 10px;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        input[type="checkbox"] {
            margin-right: 6px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0 0;
        }

        .login {
            max-width: 400px;
            margin: 60px auto;
        }

        .login h2 {
            text-align: center;
        }

        /* ── App layout ── */
        .app-layout {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }

        .sidebar-card {
            position: sticky;
            top: 80px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px;
            max-height: calc(100dvh - 100px);
            overflow-y: auto;
        }

        .sidebar-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin: 0 0 10px;
        }

        .sidebar-head h2 {
            margin: 0;
            font-size: 15px;
        }

        .sidebar-head .muted {
            margin: 2px 0 0;
        }

        .nav {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .nav a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            border-radius: 7px;
            font-size: 13px;
            color: var(--muted);
            text-decoration: none;
            transition: background 0.12s, color 0.12s;
        }

        .nav a:hover {
            background: var(--surface-2);
            color: var(--text);
        }

        .nav a.active {
            background: rgba(74, 144, 217, 0.12);
            color: var(--accent);
        }

        .nav a .badge {
            margin-left: auto;
        }

        .nav-group {
            margin: 0 0 4px;
        }

        .nav-group summary {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--soft);
            padding: 6px 10px 2px;
            cursor: pointer;
        }

        .nav-group summary:hover {
            color: var(--text);
        }

        .nav-tree {
            padding-top: 4px;
        }

        .sidebar-widgets {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 12px 0 0;
            padding: 10px 0 0;
            border-top: 1px solid var(--line);
        }

        .side-widget {
            padding: 8px;
            border-radius: 8px;
            background: var(--surface-2);
        }

        .widget-title {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--soft);
            margin: 0 0 6px;
        }

        .widget-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            min-height: 24px;
            align-items: center;
        }

        .widget-row span {
            color: var(--muted);
        }

        .widget-row b {
            font-weight: 500;
        }

        .widget-meter {
            height: 4px;
            border-radius: 2px;
            background: var(--surface-3);
            margin: 0 0 6px;
            overflow: hidden;
        }

        .widget-meter span {
            display: block;
            height: 100%;
            border-radius: 2px;
            background: var(--accent);
        }

        .mini-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            margin: 10px 0 0;
            padding: 10px 0 0;
            border-top: 1px solid var(--line);
        }

        .mini-actions button {
            font-size: 12px;
            padding: 6px 10px;
        }

        /* ── Main pane ── */
        .main-pane {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin: 0 0 4px;
        }

        .page-title span {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--soft);
        }

        .page-title h2 {
            margin: 2px 0 2px;
            font-size: 20px;
            font-weight: 700;
        }

        .page-title .muted {
            margin: 0;
        }

        /* ── Grid helpers ── */
        .grid {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .span-6 {
            /* half-width when side-by-side, full on mobile */
        }

        .page-wide {
            /* full width card */
        }

        .ops-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .ops-hero {
            background: linear-gradient(135deg, var(--surface) 0%, var(--surface-2) 100%);
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .metric {
            padding: 10px;
            border-radius: 8px;
            background: var(--surface-2);
        }

        .metric span {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin: 0 0 2px;
        }

        .metric strong {
            display: block;
            font-size: 28px;
            font-weight: 700;
        }

        .topline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 14px 0 0;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            background: var(--surface-2);
            border: 1px solid var(--line);
        }

        .pill strong {
            font-weight: 500;
        }

        .queue-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .queue-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            border-radius: 7px;
            background: var(--surface-2);
        }

        .queue-item b {
            font-size: 13px;
        }

        .queue-item p {
            margin: 0;
            font-size: 11px;
        }

        .status-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .status-list > div {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid var(--line);
            font-size: 13px;
        }

        .status-list > div:last-child {
            border-bottom: none;
        }

        .status-list b {
            font-weight: 500;
        }

        /* ── Form sections ── */
        .form-section {
            margin: 0 0 20px;
            padding: 16px 0;
            border-top: 1px solid var(--line);
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0 0 12px;
        }

        .section-title h3 {
            margin: 0;
        }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .field-grid.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .field-full {
            grid-column: 1 / -1;
        }

        .toggle-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin: 10px 0;
        }

        .toggle {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 8px;
            background: var(--surface-2);
            cursor: pointer;
            font-size: 13px;
        }

        .toggle b {
            display: block;
            font-weight: 500;
        }

        .toggle span {
            display: block;
            font-size: 11px;
            color: var(--muted);
        }

        .league-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
            margin: 8px 0;
        }

        .league-option {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 6px 8px;
            border-radius: 6px;
            background: var(--surface-2);
            font-size: 12px;
            cursor: pointer;
        }

        .league-option input {
            margin-top: 2px;
        }

        .league-option b {
            font-weight: 500;
        }

        .league-option span {
            display: block;
            font-size: 10px;
            color: var(--muted);
        }

        .route-builder {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 8px 0;
        }

        .route-builder-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .images {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }

        .images img {
            width: 100%;
            border-radius: 8px;
            border: 1px solid var(--line);
        }

        pre {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            overflow: auto;
            font-size: 12px;
            max-height: 400px;
        }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .app-layout {
                grid-template-columns: 1fr;
            }

            .sidebar-card {
                position: static;
                max-height: none;
            }

            .sidebar-head .muted {
                display: none;
            }

            .sidebar-card .nav {
                margin: 10px 0;
            }

            .nav-tree {
                padding-top: 8px;
            }

            .nav-group {
                padding-bottom: 5px;
            }

            .nav-group summary {
                min-height: 34px;
            }

            .sidebar-card .nav a {
                min-height: 34px;
                padding: 8px 10px;
                font-size: 13px;
            }

            .sidebar-widgets {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .side-widget {
                padding: 9px;
            }

            .side-widget:last-child {
                grid-column: 1 / -1;
            }

            .widget-title {
                margin-bottom: 5px;
            }

            .widget-row {
                min-height: 22px;
            }

            .mini-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .mini-actions button {
                min-width: 0;
                padding: 0 9px;
                font-size: 12px;
            }

            .metric-grid, .field-grid, .field-grid.three, .toggle-grid, .league-grid, .route-builder-row, .split, .app-layout, .ops-grid {
                grid-template-columns: 1fr;
            }

            .table {
                display: block;
                overflow-x: auto;
            }

            .status-list > div {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 520px) {
            h1 {
                font-size: 18px;
            }

            .sidebar-card {
                max-height: 64dvh;
            }

            .sidebar-widgets, .mini-actions {
                grid-template-columns: 1fr;
            }

            .metric strong {
                font-size: 26px;
            }

            .card {
                padding: 14px;
            }
        }

    </style>
</head>
<body>
<main class="shell">
<?php include __DIR__ . '/partials/header.php'; ?>
<?php include __DIR__ . '/partials/flash.php'; ?>

<?php if (!$hasPassword): ?>
    <section class="card login">
        <h2>Create Admin Password</h2>
        <p class="muted">This locks the setup panel before you add API keys.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="bootstrap">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
            <label for="confirm_password">Confirm Password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="12">
            <div class="actions">
                <button type="submit">Create Password</button>
            </div>
        </form>
    </section>
<?php elseif (!$loggedIn): ?>
    <section class="card login">
        <h2>Sign In</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="login">
            <label for="password">Admin Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <div class="actions">
                <button type="submit">Sign In</button>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="app-layout">
        <?php include __DIR__ . '/partials/nav.php'; ?>
        <section class="main-pane grid">
            <div class="page-title">
                <div>
                    <span><?= htmlspecialchars((string) $activeViewMeta['section']) ?></span>
                    <h2><?= htmlspecialchars((string) $activeViewMeta['title']) ?></h2>
                    <p class="muted"><?= htmlspecialchars((string) $activeViewMeta['description']) ?></p>
                </div>
                <span class="badge"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $activeView))) ?></span>
            </div>
            <?php include __DIR__ . '/views/' . $activeView . '.php'; ?>
        </section>
    </section>
<?php endif; ?>
</main>
</body>
</html>