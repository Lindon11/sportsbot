<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/generate_image.php';
require_once __DIR__ . '/telegram.php';

function fb_run_live_check(array $config, bool $dryRun = false): array
{
    $summary = [
        'dry_run' => $dryRun,
        'total_live_scores' => 0,
        'allowed_matches' => 0,
        'generated_alerts' => 0,
        'sent_alerts' => 0,
        'images' => [],
        'messages' => [],
    ];

    date_default_timezone_set((string) $config['app']['timezone']);
    fb_ensure_directories($config);
    fb_require_extensions(['curl', 'json', 'sqlite3']);
    fb_require_env($config, !$dryRun);

    $lockHandle = fb_acquire_run_lock($config);

    if ($lockHandle === null) {
        fb_log('info', 'Previous check_live.php run still active; skipping this run');
        $summary['messages'][] = 'Previous live check is still running; skipped this run.';
        return $summary;
    }

    try {
        $db = fb_open_db($config);
        fb_cleanup_generated_images($config);
        if (!$dryRun) {
            fb_maybe_send_daily_health_summary($config, $db);
            $updateSummary = fb_process_telegram_updates($config, $db);
            if ((int) ($updateSummary['follows'] ?? 0) > 0) {
                $summary['messages'][] = sprintf('Telegram follows processed: %d', (int) $updateSummary['follows']);
            }
            if ((int) ($updateSummary['topics'] ?? 0) > 0) {
                $summary['messages'][] = sprintf('Telegram topics discovered: %d', (int) $updateSummary['topics']);
            }
            if ((int) ($updateSummary['menus'] ?? 0) > 0) {
                $summary['messages'][] = sprintf('Telegram bot menus handled: %d', (int) $updateSummary['menus']);
            }
        }

        $allLiveScores = fb_fetch_live_scores($config, $db);
        $matches = fb_filter_allowed_matches($config, $allLiveScores, $db);

        $summary['total_live_scores'] = count($allLiveScores);
        $summary['allowed_matches'] = count($matches);
        $summary['messages'][] = sprintf('Allowed live matches: %d', count($matches));

        fb_log('info', 'Fetched live scores', [
            'total' => count($allLiveScores),
            'allowed_matches' => count($matches),
            'dry_run' => $dryRun,
        ]);

        foreach ($matches as $rawMatch) {
            if (!$dryRun) {
                fb_mark_live_coverage_seen($db, $rawMatch);
            }
            $match = fb_normalize_match($rawMatch);
            $previous = fb_get_event_state($db, $match['event_id']);
            $timeline = [];

            try {
                if (($match['sport'] ?? 'Soccer') === 'Soccer' && (fb_is_live_status($match['status']) || fb_is_half_time_status($match['status']) || fb_is_full_time_status($match['status']))) {
                    $timeline = fb_fetch_event_timeline($config, $db, $match['event_id']);
                }
            } catch (Throwable $error) {
                fb_log('warning', 'Could not fetch event timeline', [
                    'event_id' => $match['event_id'],
                    'error' => $error->getMessage(),
                ]);
                $summary['messages'][] = sprintf('Timeline unavailable for %s: %s', $match['event_id'], $error->getMessage());
            }

            $alerts = ($match['sport'] ?? 'Soccer') === 'Soccer'
                ? fb_detect_alerts($config, $db, $match, $previous, $timeline)
                : fb_detect_generic_alerts($config, $db, $match, $previous);

            if (!$dryRun && ($match['sport'] ?? 'Soccer') === 'Soccer') {
                fb_suppress_first_seen_timeline_backlog($config, $db, $match, $previous, $timeline);
            }

            foreach ($alerts as $alert) {
                if (fb_was_alert_sent($db, $alert['key'])) {
                    fb_record_alert_decision($db, 'skipped', 'Duplicate alert key already marked sent.', $alert);
                    continue;
                }

                try {
                    $alert = fb_enrich_alert_assets($config, $db, $alert);
                } catch (Throwable $error) {
                    fb_log('warning', 'Could not enrich alert artwork', [
                        'alert_key' => $alert['key'],
                        'error' => $error->getMessage(),
                    ]);
                    $summary['messages'][] = sprintf('Artwork lookup failed for %s: %s', $alert['key'], $error->getMessage());
                }

                $imagePath = fb_generate_alert_image($config, $alert);
                $caption = fb_caption_for_alert($alert);
                $summary['generated_alerts']++;
                $summary['images'][] = $imagePath;

                if ($dryRun) {
                    $summary['messages'][] = sprintf('[%s] %s -> %s', $alert['type'], str_replace("\n", ' / ', $caption), $imagePath);
                    continue;
                }

                if (fb_send_alert_photo_route_and_record($config, $db, $alert, $imagePath, $caption)) {
                    $summary['sent_alerts']++;
                } else {
                    $summary['messages'][] = sprintf('[%s] %s queued with one or more failed chat deliveries.', $alert['type'], $alert['key']);
                    continue;
                }

                fb_log('info', 'Alert sent', [
                    'alert_key' => $alert['key'],
                    'type' => $alert['type'],
                    'event_id' => $match['event_id'],
                    'image' => basename($imagePath),
                ]);
            }

            if (!$dryRun) {
                fb_save_event_state($db, $match);
            }
        }

        // Check for upcoming match previews
        $previewAlerts = fb_detect_preview_alerts($config, $db);

        foreach ($previewAlerts as $alert) {
            if (fb_was_alert_sent($db, $alert['key'])) {
                fb_record_alert_decision($db, 'skipped', 'Duplicate preview key already marked sent.', $alert);
                continue;
            }

            try {
                $alert = fb_enrich_alert_assets($config, $db, $alert);
            } catch (Throwable $error) {
                fb_log('warning', 'Could not enrich preview alert artwork', [
                    'alert_key' => $alert['key'],
                    'error' => $error->getMessage(),
                ]);
            }

            $imagePath = fb_generate_alert_image($config, $alert);
            $caption = fb_caption_for_alert($alert);
            $summary['generated_alerts']++;
            $summary['images'][] = $imagePath;

            if ($dryRun) {
                $summary['messages'][] = sprintf('[%s] %s -> %s', $alert['type'], str_replace("\n", ' / ', $caption), $imagePath);
                continue;
            }

            if (fb_send_alert_photo_route_and_record($config, $db, $alert, $imagePath, $caption)) {
                $summary['sent_alerts']++;
            } else {
                $summary['messages'][] = sprintf('[%s] %s queued with one or more failed chat deliveries.', $alert['type'], $alert['key']);
                continue;
            }

            fb_log('info', 'Preview alert sent', [
                'alert_key' => $alert['key'],
                'type' => $alert['type'],
                'event_id' => $alert['match']['event_id'],
                'image' => basename($imagePath),
            ]);
        }

        foreach (fb_detect_customer_guide_alerts($config, $db) as $alert) {
            if (fb_was_alert_sent($db, $alert['key'])) {
                fb_record_alert_decision($db, 'skipped', 'Duplicate customer guide key already marked sent.', $alert);
                continue;
            }

            $summary['generated_alerts']++;

            if ($dryRun) {
                $summary['messages'][] = sprintf(
                    '[%s] %s',
                    $alert['type'],
                    str_replace("\n", ' / ', strtok($alert['text'], "\n") ?: 'Customer sports guide')
                );
                continue;
            }

            $options = !empty($alert['reply_markup']) ? ['reply_markup' => $alert['reply_markup']] : [];
            if (fb_send_simple_alert_message_all_and_record($config, $db, $alert, $alert['text'], $options)) {
                $summary['sent_alerts']++;
            } else {
                $summary['messages'][] = sprintf('[%s] %s queued with one or more failed chat deliveries.', $alert['type'], $alert['key']);
                continue;
            }

            fb_log('info', 'Customer guide sent', [
                'alert_key' => $alert['key'],
                'live_count' => $alert['meta']['live_count'] ?? 0,
                'fixture_count' => $alert['meta']['fixture_count'] ?? 0,
                'tv_count' => $alert['meta']['tv_count'] ?? 0,
            ]);
        }

        if (!empty($config['cards']['bursts_enabled'])) {
            try {
                $cardJobs = fb_schedule_matchday_card_jobs($config, $db, $matches, !$dryRun);
                $cardSummary = fb_dispatch_matchday_card_jobs($config, $db, $dryRun, $cardJobs);
                $summary['generated_alerts'] += (int) $cardSummary['jobs'];
                $summary['sent_alerts'] += (int) $cardSummary['sent'];
                $summary['images'] = array_merge($summary['images'], $cardSummary['images']);
                $summary['messages'][] = sprintf(
                    'Matchday cards: %d job(s), %d page(s), %d sent, %d failed.',
                    (int) $cardSummary['jobs'],
                    (int) $cardSummary['pages'],
                    (int) $cardSummary['sent'],
                    (int) $cardSummary['failed']
                );
                $summary['messages'] = array_merge($summary['messages'], $cardSummary['messages']);
            } catch (Throwable $error) {
                fb_log('warning', 'Matchday card scheduler failed', [
                    'error' => $error->getMessage(),
                ]);
                $summary['messages'][] = 'Matchday card scheduler failed: ' . $error->getMessage();
            }
        } else {
            foreach (fb_detect_tv_schedule_alerts($config, $db) as $alert) {
                if (fb_was_alert_sent($db, $alert['key'])) {
                    fb_record_alert_decision($db, 'skipped', 'Duplicate TV schedule key already marked sent.', $alert);
                    continue;
                }

                $summary['generated_alerts']++;

                if ($dryRun) {
                    $summary['messages'][] = sprintf(
                        '[%s] %s',
                        $alert['type'],
                        str_replace("\n", ' / ', strtok($alert['text'], "\n") ?: 'TV sports guide')
                    );
                    continue;
                }

                if (!empty($config['tv']['send_image'])) {
                    $imagePath = fb_generate_tv_schedule_image(
                        $config,
                        $alert['events'] ?? [],
                        (int) ($alert['hours_ahead'] ?? ($config['tv']['lookahead_hours'] ?? 24))
                    );
                    $summary['images'][] = $imagePath;
                    $sent = fb_send_simple_alert_photo_all_and_record($config, $db, $alert, $imagePath, strtok($alert['text'], "\n") ?: 'TV Sports Guide');
                } else {
                    $sent = fb_send_simple_alert_message_all_and_record($config, $db, $alert, $alert['text']);
                }
                if ($sent) {
                    $summary['sent_alerts']++;
                } else {
                    $summary['messages'][] = sprintf('[%s] %s queued with one or more failed chat deliveries.', $alert['type'], $alert['key']);
                    continue;
                }

                fb_log('info', 'TV schedule alert sent', [
                    'alert_key' => $alert['key'],
                    'event_count' => $alert['meta']['event_count'] ?? 0,
                ]);
            }

            // Check for daily match cards
            foreach (fb_detect_daily_card_alerts($config, $db) as $alert) {
                if (fb_was_alert_sent($db, $alert['key'])) {
                    fb_record_alert_decision($db, 'skipped', 'Duplicate daily card key already marked sent.', $alert);
                    continue;
                }

                $summary['generated_alerts']++;

                if ($dryRun) {
                    $summary['messages'][] = sprintf(
                        '[%s] %s',
                        $alert['type'],
                        str_replace("\n", ' / ', strtok($alert['text'], "\n") ?: 'Daily match card')
                    );
                    continue;
                }

                $sportRoute = (string) ($alert['meta']['sport'] ?? '');
                if (!empty($config['alerts']['daily_card_send_image'])) {
                    $imagePath = fb_generate_daily_card_image($config, $alert['leagues']);
                    $summary['images'][] = $imagePath;
                    if ($sportRoute !== '') {
                        $sent = fb_send_simple_alert_photo_route_and_record($config, $db, $alert, $imagePath, strtok($alert['text'], "\n") ?: "Today's Matches", $sportRoute);
                    } else {
                        $sent = fb_send_simple_alert_photo_all_and_record($config, $db, $alert, $imagePath, strtok($alert['text'], "\n") ?: "Today's Matches");
                    }
                } else {
                    if ($sportRoute !== '') {
                        $sent = fb_send_simple_alert_message_route_and_record($config, $db, $alert, $alert['text'], $sportRoute);
                    } else {
                        $sent = fb_send_simple_alert_message_all_and_record($config, $db, $alert, $alert['text']);
                    }
                }
                if ($sent) {
                    $summary['sent_alerts']++;
                } else {
                    $summary['messages'][] = sprintf('[%s] %s queued with one or more failed chat deliveries.', $alert['type'], $alert['key']);
                    continue;
                }

                fb_log('info', 'Daily match card sent', [
                    'alert_key' => $alert['key'],
                    'league_count' => $alert['meta']['league_count'] ?? 0,
                    'match_count' => $alert['meta']['match_count'] ?? 0,
                ]);
            }
        }

        // Check for kick-off reminder alerts
        foreach (fb_detect_kickoff_reminder_alerts($config, $db) as $alert) {
            if (fb_was_alert_sent($db, $alert['key'])) {
                fb_record_alert_decision($db, 'skipped', 'Duplicate kickoff reminder key already marked sent.', $alert);
                continue;
            }

            try {
                $alert = fb_enrich_alert_assets($config, $db, $alert);
            } catch (Throwable $error) {
                fb_log('warning', 'Could not enrich kickoff reminder artwork', [
                    'alert_key' => $alert['key'],
                    'error' => $error->getMessage(),
                ]);
            }

            $imagePath = fb_generate_alert_image($config, $alert);
            $caption = fb_caption_for_alert($alert);
            $summary['generated_alerts']++;
            $summary['images'][] = $imagePath;

            if ($dryRun) {
                $summary['messages'][] = sprintf('[%s] %s -> %s', $alert['type'], str_replace("\n", ' / ', $caption), $imagePath);
                continue;
            }

            if (fb_send_alert_photo_route_and_record($config, $db, $alert, $imagePath, $caption)) {
                $summary['sent_alerts']++;
            } else {
                $summary['messages'][] = sprintf('[%s] %s queued with one or more failed chat deliveries.', $alert['type'], $alert['key']);
                continue;
            }

            fb_log('info', 'Kickoff reminder sent', [
                'alert_key' => $alert['key'],
                'event_id' => $alert['match']['event_id'],
                'minutes_until' => $alert['meta']['minutes_until'] ?? 0,
            ]);
        }
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    return $summary;
}
