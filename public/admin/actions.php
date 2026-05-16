<?php

declare(strict_types=1);

/**
 * POST action handler for the admin panel.
 *
 * Expects these globals to be available from the router:
 *   $env    – array from admin_read_env_file()
 *   $action – string from $_POST['action']
 *
 * On success most actions call admin_redirect() and exit.
 * On failure the exception is caught in the caller.
 */

$action = (string) ($_POST['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_require_csrf();

        if ($action === 'bootstrap') {
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if (strlen($password) < 12) {
                throw new RuntimeException('Use an admin password of at least 12 characters.');
            }

            if ($password !== $confirm) {
                throw new RuntimeException('Password confirmation did not match.');
            }

            $env['BOT_ADMIN_PASSWORD_HASH'] = password_hash($password, PASSWORD_DEFAULT);
            $env['BOT_TIMEZONE'] = $env['BOT_TIMEZONE'] ?? 'Europe/London';
            admin_write_env_file($env);
            $_SESSION['admin_authenticated'] = true;
            admin_flash('success', 'Admin password created. You are signed in.');
            admin_redirect('dashboard');
        }

        if ($action === 'login') {
            $hash = (string) ($env['BOT_ADMIN_PASSWORD_HASH'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if ($hash === '' || !password_verify($password, $hash)) {
                throw new RuntimeException('Invalid admin password.');
            }

            $_SESSION['admin_authenticated'] = true;
            admin_flash('success', 'Signed in.');
            admin_redirect('dashboard');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            admin_redirect('dashboard');
        }

        if (!admin_is_logged_in($env)) {
            throw new RuntimeException('Please sign in first.');
        }

        $config = fb_config(true);
        fb_ensure_directories($config);

        if ($action === 'save_telegram_topic_routes') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $saved = admin_save_telegram_topic_labels($config, $_POST);
            admin_flash('success', sprintf('Saved %d topic label(s).', $saved));
            admin_redirect('settings');
        }

        if ($action === 'save_settings') {
            foreach ([
                'TELEGRAM_BOT_TOKEN',
                'TELEGRAM_CHAT_ID',
                'TELEGRAM_MESSAGE_THREAD_ID',
                'TELEGRAM_ERROR_CHAT_ID',
                'TELEGRAM_EXTRA_CHAT_IDS',
                'BOT_TELEGRAM_ROUTES_JSON',
                'TELEGRAM_UPDATES_ENABLED',
                'THESPORTSDB_API_KEY',
                'BOT_TIMEZONE',
                'BOT_COVERAGE_PRESET',
                'BOT_COVERAGE_COUNTRIES',
                'BOT_MAX_SCHEDULE_LEAGUES',
                'BOT_KICKOFF_PROGRESS_MAX',
                'BOT_PREVIEW_HOURS_AHEAD',
                'BOT_DAILY_CARD_TIME',
                'BOT_CARD_ROUTE_MODE',
                'BOT_CARD_TYPES_ENABLED',
                'BOT_CARD_BURST_MIN_FIXTURES',
                'BOT_CARD_BURST_MIN_LIVE',
                'BOT_CARD_BURST_MIN_RESULTS',
                'BOT_CARD_BURST_COOLDOWN_MINUTES',
                'BOT_CARD_MAX_ITEMS_PER_TYPE',
                'BOT_CARD_MAX_PAGES_PER_RUN',
                'BOT_CARD_MAX_SENDS_PER_RUN',
                'BOT_CONTENT_PACKS_ENABLED',
                'BOT_CUSTOMER_GUIDE_TIME',
                'BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS',
                'BOT_TEAM_WATCHLIST',
                'BOT_PLAYER_WATCHLIST',
                'BOT_MAX_FOLLOW_BUTTONS',
                'BOT_KICKOFF_REMINDER_MINUTES',
                'BOT_API_MIN_INTERVAL_MS',
                'BOT_LIVESCORE_CACHE_TTL',
                'BOT_TIMELINE_CACHE_TTL',
                'BOT_LOOKUP_CACHE_TTL',
                'BOT_MAX_LIVE_MATCHES_PER_RUN',
                'BOT_MAX_LIVE_MATCHES_PER_SPORT',
                'BOT_RENDER_ENGINE',
                'BOT_RENDER_CHROME_PATH',
                'BOT_RENDER_USER_DATA_DIR',
                'BOT_RENDER_EXTRA_ARGS',
                'BOT_FONT_REGULAR',
                'BOT_FONT_BOLD',
                'BOT_IMAGE_QUALITY',
                'BOT_IMAGE_CLEANUP_SECONDS',
                'BOT_SPORT_PROFILES_JSON',
                'BOT_HEALTH_ALERT_TIME',
                'BOT_TV_SPORTS',
                'BOT_TV_DISCOVERY_COUNTRIES',
                'BOT_TV_DISCOVERY_DAYS_AHEAD',
                'BOT_TV_DAILY_ALERT_TIME',
                'BOT_TV_LOOKAHEAD_HOURS',
                'BOT_TV_CACHE_TTL',
                'BOT_TV_MAX_EVENTS_PER_CHANNEL',
            ] as $key) {
                $env[$key] = trim((string) ($_POST[$key] ?? ''));
            }

            $advancedRoutes = admin_decode_telegram_routes_json($env['BOT_TELEGRAM_ROUTES_JSON']);

            if (!empty($_POST['BOT_TELEGRAM_ROUTES_USE_ADVANCED'])) {
                $routes = $advancedRoutes;
            } else {
                $routes = [];

                if (array_key_exists('default', $advancedRoutes)) {
                    $routes['default'] = $advancedRoutes['default'];
                }

                foreach (admin_collect_telegram_route_rows($_POST) as $sport => $targets) {
                    $routes[$sport] = $targets;
                }
            }

            $env['BOT_TELEGRAM_ROUTES_JSON'] = $routes === []
                ? ''
                : (json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

            $savedTopicLabels = admin_save_telegram_topic_labels($config, $_POST);
            if ($savedTopicLabels > 0) {
                admin_flash('success', sprintf('Saved %d Telegram topic label(s).', $savedTopicLabels));
            }

            $env['BOT_RENDER_ENGINE'] = strtolower($env['BOT_RENDER_ENGINE'] ?: 'auto');
            if (!in_array($env['BOT_RENDER_ENGINE'], ['auto', 'puppeteer', 'gd'], true)) {
                throw new RuntimeException('Render engine must be auto, puppeteer, or gd.');
            }

            if ($env['BOT_SPORT_PROFILES_JSON'] !== '') {
                $profiles = json_decode($env['BOT_SPORT_PROFILES_JSON'], true);

                if (!is_array($profiles)) {
                    throw new RuntimeException('Sport profiles JSON must be an object keyed by sport name.');
                }
            }

            $allowedLeagueIds = $_POST['BOT_ALLOWED_LEAGUE_IDS'] ?? [];
            $allowedLeagueIds = is_array($allowedLeagueIds) ? array_values(array_filter(array_map('strval', $allowedLeagueIds))) : [];
            $validLeagueIds = array_keys(fb_config(true)['leagues']['available'] ?? []);
            $allowedLeagueIds = array_values(array_intersect($allowedLeagueIds, $validLeagueIds));

            if ($allowedLeagueIds === []) {
                throw new RuntimeException('Select at least one enabled league.');
            }

            $env['BOT_ALLOWED_LEAGUE_IDS'] = implode(',', $allowedLeagueIds);

            $enabledSports = $_POST['BOT_ENABLED_SPORTS'] ?? [];
            $enabledSports = is_array($enabledSports) ? array_values(array_filter(array_map('strval', $enabledSports))) : [];
            $validSports = fb_config(true)['sports']['available'] ?? [];
            $enabledSports = array_values(array_intersect($enabledSports, $validSports));

            if ($enabledSports === []) {
                throw new RuntimeException('Select at least one enabled sport.');
            }

            $env['BOT_ENABLED_SPORTS'] = implode(',', $enabledSports);

            $selectedCoverageLeagues = $_POST['BOT_ENABLED_LEAGUE_IDS_SELECTED'] ?? [];
            $selectedCoverageLeagues = is_array($selectedCoverageLeagues) ? array_values(array_filter(array_map('strval', $selectedCoverageLeagues))) : [];
            $manualCoverageLeagues = preg_split('/[\r\n,]+/', (string) ($_POST['BOT_ENABLED_LEAGUE_IDS'] ?? '')) ?: [];
            $manualCoverageLeagues = array_values(array_filter(array_map('trim', $manualCoverageLeagues)));
            $env['BOT_ENABLED_LEAGUE_IDS'] = implode(',', array_values(array_unique(array_merge($selectedCoverageLeagues, $manualCoverageLeagues))));

            $selectedTvChannels = $_POST['BOT_TV_SELECTED_CHANNELS'] ?? [];
            $selectedTvChannels = is_array($selectedTvChannels) ? array_values(array_filter(array_map('fb_tv_channel_slug', $selectedTvChannels))) : [];
            $manualTvChannels = preg_split('/[\r\n,]+/', (string) ($_POST['BOT_TV_CHANNELS'] ?? '')) ?: [];
            $manualTvChannels = array_values(array_filter(array_map('fb_tv_channel_slug', $manualTvChannels)));
            $env['BOT_TV_CHANNELS'] = implode(',', array_values(array_unique(array_merge($selectedTvChannels, $manualTvChannels))));

            foreach ([
                'BOT_TELEGRAM_DISABLE_NOTIFICATION',
                'TELEGRAM_UPDATES_ENABLED',
                'BOT_SEND_RED_CARDS',
                'BOT_SEND_YELLOW_CARDS',
                'BOT_SEND_SUBSTITUTIONS',
                'BOT_SEND_MATCH_STARTS',
                'BOT_SEND_SCORE_UPDATES',
                'BOT_SEND_PERIOD_CHANGES',
                'BOT_SEND_MATCH_PREVIEWS',
                'BOT_SEND_DAILY_CARD',
                'BOT_DAILY_CARD_SEND_IMAGE',
                'BOT_CARD_BURSTS_ENABLED',
                'BOT_CUSTOMER_GUIDE_ENABLED',
                'BOT_FOLLOW_BUTTONS_ENABLED',
                'BOT_SEND_KICKOFF_REMINDER',
                'BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS',
                'BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS',
                'BOT_IMAGE_PRESERVE_SAMPLE_IMAGES',
                'BOT_HEALTH_ALERTS_ENABLED',
                'BOT_AUTO_ENABLE_DISCOVERED_LEAGUES',
                'BOT_TV_ENABLED',
                'BOT_TV_DAILY_ALERTS',
                'BOT_TV_SEND_IMAGE',
                'BOT_TV_INCLUDE_IN_PREVIEWS',
                'BOT_TV_PREVIEW_REQUIRE_TV',
                'BOT_TV_FOOTBALL_ONLY',
            ] as $key) {
                $env[$key] = isset($_POST[$key]) ? 'true' : 'false';
            }

            if (!empty($_POST['new_password'])) {
                $newPassword = (string) $_POST['new_password'];
                $confirm = (string) ($_POST['confirm_password'] ?? '');

                if (strlen($newPassword) < 12) {
                    throw new RuntimeException('Use an admin password of at least 12 characters.');
                }

                if ($newPassword !== $confirm) {
                    throw new RuntimeException('Password confirmation did not match.');
                }

                $env['BOT_ADMIN_PASSWORD_HASH'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            admin_write_env_file($env);
            fb_config(true);
            admin_flash('success', 'Settings saved.');
            admin_redirect('settings');
        }

        if ($action === 'test_telegram') {
            fb_require_env($config, true);

            // Always regenerate samples to ensure latest template is used
            foreach (fb_sample_alerts() as $alert) {
                $path = fb_generate_alert_image($config, $alert);
                rename($path, $config['paths']['generated'] . '/sample_' . basename($path));
            }
            $image = (glob($config['paths']['generated'] . '/sample_*goal*.png') ?: [$config['paths']['generated'] . '/sample_goal.png'])[0];

            fb_telegram_send_photo($config, $image, 'Test alert from football bot');
            admin_flash('success', 'Telegram test image sent.');
            admin_redirect('system');
        }

        if ($action === 'send_manual_message') {
            fb_require_env($config, true);
            $message = (string) ($_POST['manual_message'] ?? '');
            fb_telegram_send_message($config, $message);
            admin_flash('success', 'Message sent to Telegram.');
            admin_redirect('system');
        }

        if ($action === 'generate_samples') {
            $count = 0;

            foreach (fb_sample_alerts() as $alert) {
                $path = fb_generate_alert_image($config, $alert);
                rename($path, $config['paths']['generated'] . '/sample_' . basename($path));
                $count++;
            }

            admin_flash('success', sprintf('Generated %d sample image(s).', $count));
            admin_redirect('system');
        }

        if ($action === 'generate_last_match') {
            $result = fb_generate_last_english_match_test_image($config);
            $match = $result['match'];
            admin_flash('success', sprintf(
                'Generated last English match test: %s %d-%d %s.',
                $match['home_team'],
                $match['home_score'],
                $match['away_score'],
                $match['away_team']
            ));
            admin_flash('info', 'Image: ' . basename((string) $result['image']));
            admin_redirect('system');
        }

        if ($action === 'dry_run') {
            $summary = fb_run_live_check($config, true);
            admin_flash('success', sprintf(
                'Dry run complete: %d live scores, %d allowed matches, %d generated alert(s).',
                $summary['total_live_scores'],
                $summary['allowed_matches'],
                $summary['generated_alerts']
            ));

            foreach (array_slice($summary['messages'], 0, 6) as $message) {
                admin_flash('info', $message);
            }

            admin_redirect('dashboard');
        }

        if ($action === 'generate_card_preview') {
            fb_require_env($config, false);
            $db = fb_open_db($config);
            $liveRows = fb_filter_allowed_matches($config, fb_fetch_live_scores($config, $db), $db);
            $jobs = fb_schedule_matchday_card_jobs($config, $db, $liveRows, false);
            $wantedType = trim((string) ($_POST['preview_card_type'] ?? ''));

            if ($wantedType !== '') {
                $jobs = array_values(array_filter($jobs, static fn (array $job): bool => (string) ($job['card_type'] ?? '') === $wantedType));
            }

            if ($jobs === []) {
                admin_flash('info', 'No card jobs are due for that preview window right now.');
            } else {
                $page = $jobs[0]['payload']['pages'][0] ?? null;

                if (!is_array($page)) {
                    throw new RuntimeException('The selected card job has no renderable pages.');
                }

                $imagePath = fb_generate_matchday_card_image($config, $page);
                admin_flash('success', 'Generated card preview: ' . basename($imagePath));
            }

            admin_redirect('publishing');
        }

        if ($action === 'send_cards_now') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $liveRows = fb_filter_allowed_matches($config, fb_fetch_live_scores($config, $db), $db);
            fb_schedule_matchday_card_jobs($config, $db, $liveRows, true);
            $cardSummary = fb_dispatch_matchday_card_jobs($config, $db, false);
            admin_flash('success', sprintf(
                'Card dispatch complete: %d job(s), %d page(s), %d sent, %d failed.',
                $cardSummary['jobs'],
                $cardSummary['pages'],
                $cardSummary['sent'],
                $cardSummary['failed']
            ));

            admin_redirect('publishing');
        }

        if ($action === 'retry_failed_cards') {
            fb_require_env($config, true);
            $cardSummary = fb_dispatch_matchday_card_jobs($config, fb_open_db($config), false);
            admin_flash('success', sprintf(
                'Retried cards: %d job(s), %d sent dispatch(es), %d failed dispatch(es).',
                $cardSummary['jobs'],
                $cardSummary['sent'],
                $cardSummary['failed']
            ));

            admin_redirect('activity');
        }

        if ($action === 'send_tv_schedule_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $events = fb_fetch_tv_events($config, $db);
            $message = fb_format_tv_schedule_message(
                $config,
                $events,
                (int) ($config['tv']['lookahead_hours'] ?? 24)
            );
            if (!empty($config['tv']['send_image'])) {
                $imagePath = fb_generate_tv_schedule_image(
                    $config,
                    fb_tv_events_in_window($config, $events, (int) ($config['tv']['lookahead_hours'] ?? 24)),
                    (int) ($config['tv']['lookahead_hours'] ?? 24)
                );
                fb_telegram_send_photo_all_groups($config, $imagePath, strtok($message, "\n") ?: 'TV Sports Guide');
            } else {
                fb_telegram_send_message_all_groups($config, $message);
            }
            admin_flash('success', sprintf(
                'TV schedule test sent with %d listed event(s).',
                count(fb_tv_events_in_window($config, $events, (int) ($config['tv']['lookahead_hours'] ?? 24)))
            ));
            admin_redirect('data');
        }

        if ($action === 'send_daily_card_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $alerts = fb_detect_daily_card_alerts($config, $db);
            if ($alerts === []) {
                admin_flash('info', 'No matches found for today\'s daily card.');
            } else {
                $alert = $alerts[0];
                $caption = $alert['text'] ?? fb_format_daily_card_message($config, $alert['leagues'] ?? []);
                $sportRoute = (string) ($alert['meta']['sport'] ?? '');
                if (!empty($config['alerts']['daily_card_send_image'])) {
                    $imagePath = fb_generate_daily_card_image($config, $alert['leagues'] ?? []);
                    if ($sportRoute !== '') {
                        fb_telegram_send_photo_route($config, $imagePath, strtok($caption, "\n") ?: "Today's Matches", $sportRoute);
                    } else {
                        fb_telegram_send_photo_all_groups($config, $imagePath, strtok($caption, "\n") ?: "Today's Matches");
                    }
                } else {
                    if ($sportRoute !== '') {
                        fb_telegram_send_message_route($config, $caption, $sportRoute);
                    } else {
                        fb_telegram_send_message_all_groups($config, $caption);
                    }
                }
                admin_flash('success', sprintf('Daily card test sent with %d match(es).', $alert['meta']['match_count'] ?? 0));
            }
            admin_redirect('publishing');
        }

        if ($action === 'send_customer_guide_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $guide = fb_format_customer_guide_message($config, $db);
            $options = !empty($guide['reply_markup']) ? ['reply_markup' => $guide['reply_markup']] : [];
            fb_telegram_send_message_all_groups($config, $guide['text'], $options);
            admin_flash('success', sprintf(
                'Customer guide test sent: %d live, %d fixtures, %d TV listings.',
                (int) ($guide['meta']['live_count'] ?? 0),
                (int) ($guide['meta']['fixture_count'] ?? 0),
                (int) ($guide['meta']['tv_count'] ?? 0)
            ));
            admin_redirect('publishing');
        }

        if ($action === 'send_kickoff_reminder_test') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $alerts = fb_detect_kickoff_reminder_alerts($config, $db);
            if ($alerts === []) {
                admin_flash('info', 'No matches kicking off within the reminder window right now.');
            } else {
                $alert = $alerts[0];
                try {
                    $alert = fb_enrich_alert_assets($config, $db, $alert);
                } catch (Throwable $e) {
                    // Continue without enrichment
                }
                $caption = fb_caption_for_alert($alert);
                $imagePath = fb_generate_alert_image($config, $alert);
                fb_telegram_send_photo_route($config, $imagePath, $caption, $alert['match']['sport'] ?? null);
                admin_flash('success', sprintf('Kickoff reminder test sent for %s vs %s.', $alert['match']['home_team'] ?? 'Home', $alert['match']['away_team'] ?? 'Away'));
            }
            admin_redirect('publishing');
        }

        if ($action === 'discover_coverage') {
            fb_require_env($config, false);
            $result = fb_discover_coverage($config, fb_open_db($config));
            admin_flash('success', sprintf(
                'Coverage discovery found %d sport(s), %d league(s), and auto-enabled %d league(s).',
                $result['sports'],
                $result['leagues'],
                $result['enabled_leagues']
            ));

            foreach (array_slice($result['errors'], 0, 3) as $error) {
                admin_flash('error', $error);
            }

            admin_redirect('data');
        }

        if ($action === 'test_telegram_routes') {
            fb_require_env($config, true);
            fb_telegram_send_message_all_groups($config, 'Route test: default sports digest');

            foreach (array_values(fb_configured_telegram_route_sports($config)) as $sport) {
                fb_telegram_send_message_route($config, 'Route test: ' . $sport, $sport);
            }

            admin_flash('success', 'Telegram route test sent.');
            admin_redirect('settings');
        }

        if ($action === 'process_telegram_updates') {
            fb_require_env($config, true);
            $summary = fb_process_telegram_updates($config, fb_open_db($config));
            admin_flash('success', sprintf(
                'Telegram update sync complete: %d update(s), %d message(s), %d callback(s), %d topic touch(es), %d menu action(s).',
                (int) ($summary['updates'] ?? 0),
                (int) ($summary['messages'] ?? 0),
                (int) ($summary['callbacks'] ?? 0),
                (int) ($summary['topics'] ?? 0),
                (int) ($summary['menus'] ?? 0)
            ));

            foreach (array_slice($summary['errors'] ?? [], 0, 3) as $error) {
                admin_flash('error', (string) $error);
            }

            admin_redirect('settings');
        }

        if ($action === 'run_render_health') {
            $result = fb_run_render_health_check($config, fb_open_db($config));
            $type = $result['status'] === 'ok' ? 'success' : 'error';
            admin_flash($type, sprintf(
                'Render health %s via %s in %dms: %s',
                $result['status'],
                $result['engine'],
                $result['duration_ms'],
                $result['message']
            ));
            admin_redirect('health');
        }

        if ($action === 'send_health_summary') {
            fb_require_env($config, true);
            $db = fb_open_db($config);
            $checks = fb_system_health($config, $db);
            $failed = array_values(array_filter($checks, static fn(array $check): bool => empty($check['ok'])));
            $lines = ['Bot health summary', count($failed) === 0 ? 'All checks passed.' : count($failed) . ' check(s) need attention.', ''];
            foreach ($checks as $check) {
                $lines[] = sprintf('%s: %s (%s)', $check['label'], $check['status'], $check['detail']);
            }
            $errorChatId = trim((string) ($config['alerts']['error_alert_chat_id'] ?? ''));
            if ($errorChatId === '') {
                throw new RuntimeException('Configure TELEGRAM_ERROR_CHAT_ID before sending a health summary.');
            }
            $result = fb_telegram_send_message_to_outbox($config, $db, 'admin-health:' . date('YmdHis'), $errorChatId, implode("\n", $lines), 'admin-health');
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['error'] ?? 'Health summary could not be sent.'));
            }
            admin_flash('success', 'Health summary sent to the configured error chat.');
            admin_redirect('health');
        }

        if ($action === 'discover_tv_channels') {
            fb_require_env($config, false);
            $result = fb_discover_tv_channels($config, fb_open_db($config));
            admin_flash('success', sprintf(
                'TV discovery scanned %d listing row(s) across %d endpoint(s) and found %d channel(s).',
                $result['rows'],
                $result['paths'],
                $result['channels']
            ));

            foreach (array_slice($result['errors'], 0, 3) as $error) {
                admin_flash('error', $error);
            }

            admin_redirect('data');
        }

        if ($action === 'debug_tv_for_event') {
            fb_require_env($config, false);
            $db = fb_open_db($config);
            $eventId = trim((string) ($_POST['DEBUG_TV_EVENT_ID'] ?? ''));

            if ($eventId === '') {
                admin_flash('error', 'Provide an event id to debug.');
                admin_redirect('data');
            }

            try {
                $rows = fb_lookup_event_tv($config, $db, $eventId);
                $channels = array_values(array_unique(array_filter(array_map(static fn($r) => trim((string) ($r['strChannel'] ?? $r['strChannelName'] ?? '')), $rows))));
                $normalized = array_map(static fn($r) => [
                    'name' => trim((string) ($r['strChannel'] ?? $r['strChannelName'] ?? '')),
                    'id' => trim((string) ($r['idChannel'] ?? '')),
                    'logo' => trim((string) ($r['strLogo'] ?? '')),
                    'country' => trim((string) ($r['strCountry'] ?? '')),
                    'raw' => is_array($r) ? $r : [],
                ], $rows);

                admin_flash('success', sprintf('Found %d lookup row(s) and %d normalized channel(s).', count($rows), count($channels)));
                admin_flash('success', 'Channels: ' . (count($channels) > 0 ? implode(', ', $channels) : 'none'));

                // Pretty-print JSON payload inside a scrollable <pre> and add a copy button.
                $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $preId = 'debug-json-' . preg_replace('/[^a-z0-9_-]/i', '_', $eventId ?: bin2hex(random_bytes(4)));

                // Try to find an event name from cached TV events as a best-effort
                $cachedEvents = fb_fetch_tv_events($config, $db);
                $matches = array_values(array_filter($cachedEvents, static fn($e) => isset($e['event_id']) && (string)$e['event_id'] === $eventId));
                $eventName = '';
                if ($matches !== []) {
                    $eventName = (string) ($matches[0]['event'] ?? $matches[0]['event_name'] ?? '');
                } else {
                    // Fallback: try to extract from the lookup rows themselves
                    foreach ($rows as $r) {
                        if (is_array($r) && !empty($r['strEvent'])) {
                            $eventName = (string) $r['strEvent'];
                            break;
                        }
                    }
                }

                $channelsList = count($channels) > 0 ? implode(', ', $channels) : 'none';

                $html = '';
                $html .= '<div class="admin-debug-tv">';
                $html .= '<div style="margin-bottom:6px;"><strong>Event ID:</strong> ' . htmlspecialchars($eventId) . ' &nbsp; <strong>Event name:</strong> ' . htmlspecialchars($eventName) . '</div>';
                $html .= '<div style="margin-bottom:8px;"><strong>Channel count:</strong> ' . count($rows) . ' &nbsp; <strong>Extracted channels:</strong> ' . htmlspecialchars($channelsList) . '</div>';
                $html .= '<div style="margin-bottom:8px;"><button type="button" class="btn btn-sm btn-light" onclick="(function(){var t=document.getElementById(\'' . $preId . '\'); if(!t) return; navigator.clipboard.writeText(t.innerText).then(function(){alert(' . json_encode('JSON copied to clipboard') . ');}).catch(function(){alert(' . json_encode('Copy failed') . ');}); })()">Copy JSON</button></div>';
                $html .= '<pre id="' . $preId . '" style="max-height:380px;overflow:auto;padding:12px;border-radius:6px;background:var(--surface-2);color:var(--text);">' . htmlspecialchars(substr($json, 0, 30000)) . '</pre>';
                $html .= '</div>';

                admin_flash('html', $html);
            } catch (Throwable $e) {
                admin_flash('error', 'TV debug failed: ' . $e->getMessage());
            }

            admin_redirect('data');
        }

        if ($action === 'clear_api_cache') {
            $db = fb_open_db($config);
            $db->exec('DELETE FROM api_cache');
            admin_flash('success', 'API cache cleared.');
            admin_redirect('data');
        }

        if ($action === 'reset_state') {
            $lock = fb_acquire_run_lock($config);

            if ($lock === null) {
                throw new RuntimeException('A live check is running. Try resetting state again in a moment.');
            }

            try {
                admin_delete_state_database($config);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            admin_flash('success', 'Match state and sent-alert history reset.');
            admin_redirect('system');
        }
    }
} catch (Throwable $error) {
    admin_flash('error', $error->getMessage());
    admin_redirect(admin_action_view($action));
}