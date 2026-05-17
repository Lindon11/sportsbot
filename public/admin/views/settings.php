<?php

declare(strict_types=1);

/**
 * Admin settings view.
 *
 * Expected variables: $csrf, $env, $config, $telegramRouteRows, $telegramRouteSports,
 *   $routeMatrix, $telegramTopics, $allowedLeagueIds, $availableLeagues,
 *   $coverageLeagues, $configuredCoverageLeagueIds, $manualCoverageLeagueIds,
 *   $availableSports, $enabledSportKeys, $tvChannelRegistry, $configuredTvSlugs,
 *   $manualTvSlugs, $tvChannels
 */
?>
<div class="card page-wide" id="settings">
    <h2>Control Panel</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_settings">

        <div class="form-section">
            <div class="section-title"><h3>Connections</h3></div>
            <div class="field-grid">
                <div class="field-full">
                    <label for="TELEGRAM_BOT_TOKEN">Telegram Bot Token</label>
                    <input id="TELEGRAM_BOT_TOKEN" name="TELEGRAM_BOT_TOKEN" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_BOT_TOKEN')) ?>" autocomplete="off">
                </div>
                <div>
                    <label for="TELEGRAM_CHAT_ID">Primary Chat ID</label>
                    <input id="TELEGRAM_CHAT_ID" name="TELEGRAM_CHAT_ID" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_CHAT_ID')) ?>" autocomplete="off">
                </div>
                <div>
                    <label for="TELEGRAM_MESSAGE_THREAD_ID">Default Topic ID</label>
                    <input id="TELEGRAM_MESSAGE_THREAD_ID" name="TELEGRAM_MESSAGE_THREAD_ID" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_MESSAGE_THREAD_ID')) ?>" autocomplete="off" inputmode="numeric">
                </div>
                <div>
                    <label for="TELEGRAM_ERROR_CHAT_ID">Error Chat ID</label>
                    <input id="TELEGRAM_ERROR_CHAT_ID" name="TELEGRAM_ERROR_CHAT_ID" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_ERROR_CHAT_ID')) ?>" autocomplete="off">
                </div>
                <div class="field-full">
                    <label for="TELEGRAM_EXTRA_CHAT_IDS">Extra Chat IDs</label>
                    <input id="TELEGRAM_EXTRA_CHAT_IDS" name="TELEGRAM_EXTRA_CHAT_IDS" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_EXTRA_CHAT_IDS')) ?>" autocomplete="off">
                </div>
                <div class="field-full">
                    <label for="TELEGRAM_WEBHOOK_SECRET_TOKEN">Webhook Secret Token</label>
                    <input id="TELEGRAM_WEBHOOK_SECRET_TOKEN" name="TELEGRAM_WEBHOOK_SECRET_TOKEN" value="<?= htmlspecialchars(admin_env_value($env, 'TELEGRAM_WEBHOOK_SECRET_TOKEN')) ?>" autocomplete="off">
                </div>
                <div class="field-full">
                    <label for="THESPORTSDB_API_KEY">TheSportsDB API Key</label>
                    <input id="THESPORTSDB_API_KEY" name="THESPORTSDB_API_KEY" value="<?= htmlspecialchars(admin_env_value($env, 'THESPORTSDB_API_KEY')) ?>" autocomplete="off">
                </div>
                <div>
                    <label for="BOT_TIMEZONE">Timezone</label>
                    <input id="BOT_TIMEZONE" name="BOT_TIMEZONE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TIMEZONE', 'Europe/London')) ?>">
                </div>
            </div>
            <div class="toggle-grid">
                <label class="toggle">
                    <input name="BOT_TELEGRAM_DISABLE_NOTIFICATION" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TELEGRAM_DISABLE_NOTIFICATION', false) ? 'checked' : '' ?>>
                    <b>Quiet Telegram delivery</b>
                </label>
                <label class="toggle">
                    <input name="TELEGRAM_UPDATES_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'TELEGRAM_UPDATES_ENABLED', true) ? 'checked' : '' ?>>
                    <b>Process follow buttons<span>Cron polls Telegram callbacks for team/player follows.</span></b>
                </label>
                <label class="toggle">
                    <input name="TELEGRAM_WEBHOOK_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'TELEGRAM_WEBHOOK_ENABLED', false) ? 'checked' : '' ?>>
                    <b>Enable Telegram webhook mode<span>When enabled, callbacks/messages are processed by <code>public/telegram_webhook.php</code> instead of getUpdates polling.</span></b>
                </label>
            </div>
        </div>

        <div class="form-section" id="routes">
            <div class="section-title"><h3>Telegram Routes</h3><span class="muted">Per sport</span></div>
            <div class="route-builder">
                <?php foreach ($telegramRouteRows as $idx => $routeRow): ?>
                    <?php
                        $rowSport = (string) ($routeRow['sport'] ?? '');
                        $sportOptions = $telegramRouteSports;

                        if ($rowSport !== '' && !in_array($rowSport, $sportOptions, true)) {
                            $sportOptions[] = $rowSport;
                            natcasesort($sportOptions);
                        }
                    ?>
                    <div class="route-builder-row">
                        <div>
                            <label for="BOT_TELEGRAM_ROUTE_SPORT_<?= (int) $idx ?>">Sport</label>
                            <select id="BOT_TELEGRAM_ROUTE_SPORT_<?= (int) $idx ?>" name="BOT_TELEGRAM_ROUTE_SPORT[]">
                                <option value="">Select sport</option>
                                <?php foreach ($sportOptions as $sportOption): ?>
                                    <option value="<?= htmlspecialchars((string) $sportOption) ?>" <?= $rowSport === (string) $sportOption ? 'selected' : '' ?>><?= htmlspecialchars((string) $sportOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="BOT_TELEGRAM_ROUTE_CHAT_ID_<?= (int) $idx ?>">Chat ID</label>
                            <input id="BOT_TELEGRAM_ROUTE_CHAT_ID_<?= (int) $idx ?>" name="BOT_TELEGRAM_ROUTE_CHAT_ID[]" value="<?= htmlspecialchars((string) ($routeRow['chat_id'] ?? '')) ?>" autocomplete="off">
                        </div>
                        <div>
                            <label for="BOT_TELEGRAM_ROUTE_TOPIC_ID_<?= (int) $idx ?>">Topic ID</label>
                            <input id="BOT_TELEGRAM_ROUTE_TOPIC_ID_<?= (int) $idx ?>" name="BOT_TELEGRAM_ROUTE_TOPIC_ID[]" value="<?= htmlspecialchars((string) ($routeRow['topic_id'] ?? '')) ?>" autocomplete="off" inputmode="numeric">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="field-grid">
                <div class="field-full">
                    <label class="toggle" style="margin-top:14px">
                        <input name="BOT_TELEGRAM_ROUTES_USE_ADVANCED" type="checkbox" value="1">
                        <b>Use advanced routes JSON<span>Skips the sport/topic fields on this save.</span></b>
                    </label>
                    <label for="BOT_TELEGRAM_ROUTES_JSON">Advanced Routes JSON</label>
                    <textarea id="BOT_TELEGRAM_ROUTES_JSON" name="BOT_TELEGRAM_ROUTES_JSON" placeholder='{"Rugby":[{"chat_id":"-100123","thread_id":12}],"Soccer":["-100456"]}'><?= htmlspecialchars(admin_env_value($env, 'BOT_TELEGRAM_ROUTES_JSON')) ?></textarea>
                </div>
            </div>
            <?php if ($routeMatrix !== []): ?>
                <table class="table" style="margin-top:14px">
                    <thead><tr><th>Sport</th><th>Route</th><th>Targets</th><th>Topics</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($routeMatrix, 0, 20) as $route): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $route['sport']) ?></td>
                            <td><?= htmlspecialchars((string) $route['route']) ?></td>
                            <td><?= htmlspecialchars(implode(', ', array_map('admin_mask', $route['chats']))) ?></td>
                            <td><?= (int) ($route['topics'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="actions" style="margin-top:14px">
                <button class="secondary" name="action" value="process_telegram_updates" type="submit">Process Telegram Updates Now</button>
            </div>
        </form>

        <form method="post" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_telegram_topic_routes">
            <?php if ($telegramTopics !== []): ?>
                <table class="table" style="margin-top:14px">
                    <thead><tr><th>Topic Name</th><th>Assign Route</th><th>Full Target</th><th>Topic ID</th><th>Source</th><th>Link</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($telegramTopics, 0, 24) as $topic): ?>
                        <?php
                            $topicChatId = (string) ($topic['chat_id'] ?? '');
                            $topicId = (int) ($topic['message_thread_id'] ?? 0);
                            $topicUrl = fb_telegram_topic_url($topicChatId, $topicId);
                            $topicName = trim((string) ($topic['name'] ?? ''));
                            $topicFallback = 'Topic ' . $topicId;
                            $topicRoute = trim((string) ($topic['route'] ?? ''));
                            $routeOptions = ['' => '— None —', 'LIVE_NOW' => 'LIVE_NOW', 'FIXTURES_TODAY' => 'FIXTURES_TODAY', 'TV_GUIDE' => 'TV_GUIDE', 'LEAGUE_TABLES' => 'LEAGUE_TABLES', 'FOOTBALL' => 'FOOTBALL', 'BASKETBALL' => 'BASKETBALL', 'MMA' => 'MMA'];
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="BOT_TELEGRAM_TOPIC_CHAT_ID[]" value="<?= htmlspecialchars($topicChatId) ?>">
                                <input type="hidden" name="BOT_TELEGRAM_TOPIC_ID[]" value="<?= $topicId ?>">
                                <input name="BOT_TELEGRAM_TOPIC_NAME[]" value="<?= htmlspecialchars($topicName) ?>" placeholder="<?= htmlspecialchars($topicFallback) ?>">
                                <?php if ($topicName === ''): ?>
                                    <small class="muted">Name unknown; label it here or send /topic Name inside the topic.</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="BOT_TELEGRAM_TOPIC_ROUTE[]">
                                    <?php foreach ($routeOptions as $val => $label): ?>
                                        <option value="<?= htmlspecialchars($val) ?>" <?= $topicRoute === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><code><?= htmlspecialchars($topicChatId . ':' . $topicId) ?></code></td>
                            <td><?= $topicId ?></td>
                            <td><?= htmlspecialchars((string) ($topic['source'] ?? 'update')) ?></td>
                            <td><?= $topicUrl !== '' ? '<a href="' . htmlspecialchars($topicUrl) . '" target="_blank" rel="noreferrer">Open</a>' : '<span class="muted">Unavailable</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="muted" style="margin-top:10px">The usable Telegram topic target is <code>chat_id:topic_id</code>. Topic IDs are not hashed; only unknown names fall back to "Topic 5".</p>
            <?php else: ?>
                <p class="muted" style="margin-top:12px">No Telegram topics discovered yet. Send a message or /menu inside each group topic, then sync updates.</p>
            <?php endif; ?>

            <div class="actions" style="margin-top:8px">
                <button type="submit" name="action" value="save_telegram_topic_routes">Save Topic Labels & Routes</button>
            </div>
        </form>
        </div>

        <div class="form-section">
            <div class="section-title"><h3>Leagues</h3><span class="muted"><?= count($allowedLeagueIds) ?> enabled</span></div>
            <div class="league-grid">
                <?php foreach ($availableLeagues as $id => $league): ?>
                    <label class="league-option">
                        <input name="BOT_ALLOWED_LEAGUE_IDS[]" type="checkbox" value="<?= htmlspecialchars((string) $id) ?>" <?= in_array((string) $id, $allowedLeagueIds, true) ? 'checked' : '' ?>>
                        <b><?= htmlspecialchars((string) $league['name']) ?><span>ID <?= htmlspecialchars((string) $id) ?></span></b>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-section" id="coverage">
            <div class="section-title"><h3>Coverage</h3><span class="muted"><?= count($coverageLeagues) ?> discovered leagues</span></div>
            <div class="field-grid three">
                <div>
                    <label for="BOT_COVERAGE_PRESET">Coverage Preset</label>
                    <input id="BOT_COVERAGE_PRESET" name="BOT_COVERAGE_PRESET" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_COVERAGE_PRESET', 'uk_sports')) ?>">
                </div>
                <div>
                    <label for="BOT_COVERAGE_COUNTRIES">Coverage Countries</label>
                    <input id="BOT_COVERAGE_COUNTRIES" name="BOT_COVERAGE_COUNTRIES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_COVERAGE_COUNTRIES', implode(',', $config['coverage']['countries'] ?? []))) ?>">
                </div>
                <div>
                    <label for="BOT_MAX_SCHEDULE_LEAGUES">Max Schedule Leagues</label>
                    <input id="BOT_MAX_SCHEDULE_LEAGUES" name="BOT_MAX_SCHEDULE_LEAGUES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_SCHEDULE_LEAGUES', (string) ($config['coverage']['max_schedule_leagues'] ?? 80))) ?>" inputmode="numeric">
                </div>
            </div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_AUTO_ENABLE_DISCOVERED_LEAGUES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_AUTO_ENABLE_DISCOVERED_LEAGUES', true) ? 'checked' : '' ?>><b>Auto-enable discovered leagues<span>Uses enabled sports and coverage countries.</span></b></label>
            </div>
            <label>Enabled Sports</label>
            <div class="league-grid">
                <?php foreach ($availableSports as $sport): ?>
                    <?php $sportKey = fb_sport_key((string) $sport); ?>
                    <label class="league-option">
                        <input name="BOT_ENABLED_SPORTS[]" type="checkbox" value="<?= htmlspecialchars((string) $sport) ?>" <?= isset($enabledSportKeys[$sportKey]) ? 'checked' : '' ?>>
                        <b><?= htmlspecialchars((string) $sport) ?></b>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($coverageLeagues !== []): ?>
                <label>Enabled Coverage Leagues</label>
                <div class="league-grid">
                    <?php foreach ($coverageLeagues as $league): ?>
                        <?php
                            $leagueId = (string) $league['league_id'];
                            $checked = in_array($leagueId, array_map('strval', $configuredCoverageLeagueIds), true) || ((int) ($league['enabled'] ?? 0) === 1 && $configuredCoverageLeagueIds === []);
                        ?>
                        <label class="league-option">
                            <input name="BOT_ENABLED_LEAGUE_IDS_SELECTED[]" type="checkbox" value="<?= htmlspecialchars($leagueId) ?>" <?= $checked ? 'checked' : '' ?>>
                            <b><?= htmlspecialchars((string) $league['league_name']) ?><span><?= htmlspecialchars((string) $league['sport']) ?> - <?= htmlspecialchars((string) $league['country']) ?> - ID <?= htmlspecialchars($leagueId) ?><?= !empty($league['live_available']) ? ' - live' : '' ?></span></b>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="field-grid">
                <div class="field-full">
                    <label for="BOT_ENABLED_LEAGUE_IDS">Manual Extra League IDs</label>
                    <textarea id="BOT_ENABLED_LEAGUE_IDS" name="BOT_ENABLED_LEAGUE_IDS" placeholder="4328&#10;4387"><?= htmlspecialchars(implode("\n", $manualCoverageLeagueIds)) ?></textarea>
                </div>
            </div>
        </div>

        <div class="form-section" id="alerts">
            <div class="section-title"><h3>Alert Rules</h3></div>
            <div class="field-grid three">
                <div>
                    <label for="BOT_KICKOFF_PROGRESS_MAX">Kick-off Window Minute</label>
                    <input id="BOT_KICKOFF_PROGRESS_MAX" name="BOT_KICKOFF_PROGRESS_MAX" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_KICKOFF_PROGRESS_MAX', '3')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_PREVIEW_HOURS_AHEAD">Preview Hours Ahead</label>
                    <input id="BOT_PREVIEW_HOURS_AHEAD" name="BOT_PREVIEW_HOURS_AHEAD" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_PREVIEW_HOURS_AHEAD', '4')) ?>" inputmode="numeric">
                </div>
            </div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_SEND_RED_CARDS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_RED_CARDS', true) ? 'checked' : '' ?>><b>Red cards</b></label>
                <label class="toggle"><input name="BOT_SEND_YELLOW_CARDS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_YELLOW_CARDS', true) ? 'checked' : '' ?>><b>Yellow cards</b></label>
                <label class="toggle"><input name="BOT_SEND_SUBSTITUTIONS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_SUBSTITUTIONS', true) ? 'checked' : '' ?>><b>Substitutions</b></label>
                <label class="toggle"><input name="BOT_SEND_MATCH_STARTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_MATCH_STARTS', false) ? 'checked' : '' ?>><b>Match starts<span>Can be noisy across multi-sport coverage.</span></b></label>
                <label class="toggle"><input name="BOT_SEND_SCORE_UPDATES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_SCORE_UPDATES', false) ? 'checked' : '' ?>><b>Generic score updates<span>Best kept off unless a topic is dedicated to live scores.</span></b></label>
                <label class="toggle"><input name="BOT_SEND_PERIOD_CHANGES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_PERIOD_CHANGES', false) ? 'checked' : '' ?>><b>Period changes<span>Quarters, innings, breaks and other status ticks.</span></b></label>
                <label class="toggle"><input name="BOT_SEND_MATCH_PREVIEWS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_MATCH_PREVIEWS', true) ? 'checked' : '' ?>><b>Match previews</b></label>
                <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_GOAL_ALERTS', false) ? 'checked' : '' ?>><b>First-seen goals<span>Can post mid-match goals after fresh state.</span></b></label>
                <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_FULL_TIME_ALERTS', false) ? 'checked' : '' ?>><b>First-seen full-time</b></label>
                <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_RED_CARD_ALERTS', false) ? 'checked' : '' ?>><b>First-seen red cards</b></label>
                <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_YELLOW_CARD_ALERTS', false) ? 'checked' : '' ?>><b>First-seen yellow cards</b></label>
                <label class="toggle"><input name="BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_ALLOW_FIRST_SEEN_SUBSTITUTION_ALERTS', false) ? 'checked' : '' ?>><b>First-seen substitutions</b></label>
            </div>
        </div>

        <div class="form-section">
            <div class="section-title"><h3>Daily Match Card</h3></div>
            <div class="field-grid three">
                <div>
                    <label for="BOT_DAILY_CARD_TIME">Card Time</label>
                    <input id="BOT_DAILY_CARD_TIME" name="BOT_DAILY_CARD_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_DAILY_CARD_TIME', '08:00')) ?>">
                </div>
            </div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_SEND_DAILY_CARD" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_DAILY_CARD', true) ? 'checked' : '' ?>><b>Daily match card<span>Send a summary card of all matches for the day.</span></b></label>
                <label class="toggle"><input name="BOT_DAILY_CARD_SEND_IMAGE" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_DAILY_CARD_SEND_IMAGE', true) ? 'checked' : '' ?>><b>Card as image<span>Generate an image card; otherwise text only.</span></b></label>
            </div>
        </div>

        <div class="form-section" id="customer-guide-settings">
            <div class="section-title"><h3>Customer Guide</h3><span class="muted">Teams, players, TV</span></div>
            <div class="field-grid three">
                <div>
                    <label for="BOT_CUSTOMER_GUIDE_TIME">Guide Time</label>
                    <input id="BOT_CUSTOMER_GUIDE_TIME" name="BOT_CUSTOMER_GUIDE_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CUSTOMER_GUIDE_TIME', '09:00')) ?>">
                </div>
                <div>
                    <label for="BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS">Lookahead Hours</label>
                    <input id="BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS" name="BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS', '24')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_MAX_FOLLOW_BUTTONS">Max Follow Buttons</label>
                    <input id="BOT_MAX_FOLLOW_BUTTONS" name="BOT_MAX_FOLLOW_BUTTONS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_FOLLOW_BUTTONS', '8')) ?>" inputmode="numeric">
                </div>
                <div class="field-full">
                    <label for="BOT_TEAM_WATCHLIST">Team Watchlist</label>
                    <textarea id="BOT_TEAM_WATCHLIST" name="BOT_TEAM_WATCHLIST" placeholder="Arsenal&#10;England&#10;Boston Celtics"><?= htmlspecialchars(admin_env_value($env, 'BOT_TEAM_WATCHLIST')) ?></textarea>
                </div>
                <div class="field-full">
                    <label for="BOT_PLAYER_WATCHLIST">Player Watchlist</label>
                    <textarea id="BOT_PLAYER_WATCHLIST" name="BOT_PLAYER_WATCHLIST" placeholder="Bukayo Saka&#10;Jude Bellingham"><?= htmlspecialchars(admin_env_value($env, 'BOT_PLAYER_WATCHLIST')) ?></textarea>
                </div>
            </div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_CUSTOMER_GUIDE_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_CUSTOMER_GUIDE_ENABLED', true) ? 'checked' : '' ?>><b>Daily customer guide<span>Scores, fixtures, channels and followed-team highlights.</span></b></label>
                <label class="toggle"><input name="BOT_FOLLOW_BUTTONS_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_FOLLOW_BUTTONS_ENABLED', true) ? 'checked' : '' ?>><b>Follow buttons<span>Add inline buttons under customer guide messages.</span></b></label>
            </div>
        </div>

        <div class="form-section" id="scheduler-settings">
            <div class="section-title"><h3>Burst Card Scheduler</h3><span class="muted">Multiple cards</span></div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_CARD_BURSTS_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_CARD_BURSTS_ENABLED', true) ? 'checked' : '' ?>><b>Smart burst cards<span>Use the card queue instead of one daily digest.</span></b></label>
            </div>
            <div class="field-grid three">
                <div>
                    <label for="BOT_CARD_ROUTE_MODE">Route Mode</label>
                    <input id="BOT_CARD_ROUTE_MODE" name="BOT_CARD_ROUTE_MODE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_ROUTE_MODE', 'smart')) ?>">
                </div>
                <div>
                    <label for="BOT_CARD_TYPES_ENABLED">Enabled Card Types</label>
                    <input id="BOT_CARD_TYPES_ENABLED" name="BOT_CARD_TYPES_ENABLED" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_TYPES_ENABLED', 'kickoff_soon,live_now,results,tv_now')) ?>">
                </div>
                <div class="field-full">
                    <label for="BOT_CONTENT_PACKS_ENABLED">Enabled Content Packs</label>
                    <input id="BOT_CONTENT_PACKS_ENABLED" name="BOT_CONTENT_PACKS_ENABLED" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CONTENT_PACKS_ENABLED', 'live_now,kickoff_soon,results,tv_now')) ?>">
                </div>
                <div>
                    <label for="BOT_CARD_BURST_COOLDOWN_MINUTES">Cooldown Minutes</label>
                    <input id="BOT_CARD_BURST_COOLDOWN_MINUTES" name="BOT_CARD_BURST_COOLDOWN_MINUTES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_COOLDOWN_MINUTES', '60')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_CARD_BURST_MIN_FIXTURES">Min Fixtures</label>
                    <input id="BOT_CARD_BURST_MIN_FIXTURES" name="BOT_CARD_BURST_MIN_FIXTURES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_MIN_FIXTURES', '3')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_CARD_BURST_MIN_LIVE">Min Live</label>
                    <input id="BOT_CARD_BURST_MIN_LIVE" name="BOT_CARD_BURST_MIN_LIVE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_MIN_LIVE', '2')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_CARD_BURST_MIN_RESULTS">Min Results</label>
                    <input id="BOT_CARD_BURST_MIN_RESULTS" name="BOT_CARD_BURST_MIN_RESULTS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_BURST_MIN_RESULTS', '3')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_CARD_MAX_ITEMS_PER_TYPE">Public Cards Per Type</label>
                    <input id="BOT_CARD_MAX_ITEMS_PER_TYPE" name="BOT_CARD_MAX_ITEMS_PER_TYPE" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_MAX_ITEMS_PER_TYPE', '4')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_CARD_MAX_PAGES_PER_RUN">Max Pages Per Run</label>
                    <input id="BOT_CARD_MAX_PAGES_PER_RUN" name="BOT_CARD_MAX_PAGES_PER_RUN" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_MAX_PAGES_PER_RUN', '12')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_CARD_MAX_SENDS_PER_RUN">Max Jobs Per Run</label>
                    <input id="BOT_CARD_MAX_SENDS_PER_RUN" name="BOT_CARD_MAX_SENDS_PER_RUN" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_CARD_MAX_SENDS_PER_RUN', '12')) ?>" inputmode="numeric">
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-title"><h3>Kick-off Reminder</h3></div>
            <div class="field-grid three">
                <div>
                    <label for="BOT_KICKOFF_REMINDER_MINUTES">Minutes Before Kick-off</label>
                    <input id="BOT_KICKOFF_REMINDER_MINUTES" name="BOT_KICKOFF_REMINDER_MINUTES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_KICKOFF_REMINDER_MINUTES', '10')) ?>" inputmode="numeric">
                </div>
            </div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_SEND_KICKOFF_REMINDER" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_SEND_KICKOFF_REMINDER', true) ? 'checked' : '' ?>><b>Kick-off reminders<span>Send a reminder alert before each match kicks off.</span></b></label>
            </div>
        </div>

        <div class="form-section" id="tv">
            <div class="section-title"><h3>TV Listings</h3><span class="muted"><?= count($tvChannels) ?> channels</span></div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_TV_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_ENABLED', true) ? 'checked' : '' ?>><b>Enable TV listings</b></label>
                <label class="toggle"><input name="BOT_TV_DAILY_ALERTS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_DAILY_ALERTS', true) ? 'checked' : '' ?>><b>Daily TV guide</b></label>
                <label class="toggle"><input name="BOT_TV_SEND_IMAGE" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_SEND_IMAGE', true) ? 'checked' : '' ?>><b>TV guide image<span>Uses channel logos when available.</span></b></label>
                <label class="toggle"><input name="BOT_TV_INCLUDE_IN_PREVIEWS" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_INCLUDE_IN_PREVIEWS', true) ? 'checked' : '' ?>><b>TV info on previews</b></label>
                <label class="toggle"><input name="BOT_TV_PREVIEW_REQUIRE_TV" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_PREVIEW_REQUIRE_TV', false) ? 'checked' : '' ?>><b>Only televised previews</b></label>
                <label class="toggle"><input name="BOT_TV_FOOTBALL_ONLY" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_TV_FOOTBALL_ONLY', false) ? 'checked' : '' ?>><b>Football-only TV guide</b></label>
            </div>
            <div class="field-grid three">
                <?php if ($tvChannelRegistry !== []): ?>
                    <div class="field-full">
                        <label>Selected TV Channels</label>
                        <div class="league-grid">
                            <?php foreach ($tvChannelRegistry as $channel): ?>
                                <?php $slug = (string) $channel['channel_slug']; ?>
                                <label class="league-option">
                                    <input name="BOT_TV_SELECTED_CHANNELS[]" type="checkbox" value="<?= htmlspecialchars($slug) ?>" <?= in_array($slug, $configuredTvSlugs, true) ? 'checked' : '' ?>>
                                    <b><?= htmlspecialchars((string) $channel['channel_name']) ?><span><?= htmlspecialchars($slug) ?></span></b>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="field-full">
                    <label for="BOT_TV_CHANNELS">Manual Extra Channel Slugs</label>
                    <textarea id="BOT_TV_CHANNELS" name="BOT_TV_CHANNELS" placeholder="sky_sports_main_event&#10;sky_sports_premier_league"><?= htmlspecialchars(implode("\n", $manualTvSlugs)) ?></textarea>
                </div>
                <div class="field-full">
                    <label for="BOT_TV_SPORTS">TV Sports Filter</label>
                    <textarea id="BOT_TV_SPORTS" name="BOT_TV_SPORTS" placeholder="Soccer&#10;Darts&#10;Rugby&#10;Snooker"><?= htmlspecialchars(admin_env_value($env, 'BOT_TV_SPORTS')) ?></textarea>
                </div>
                <div class="field-full">
                    <label for="BOT_TV_DISCOVERY_COUNTRIES">Discovery Countries</label>
                    <input id="BOT_TV_DISCOVERY_COUNTRIES" name="BOT_TV_DISCOVERY_COUNTRIES" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_DISCOVERY_COUNTRIES', 'united_kingdom,ireland')) ?>" placeholder="united_kingdom,ireland">
                </div>
                <div>
                    <label for="BOT_TV_DAILY_ALERT_TIME">Guide Time</label>
                    <input id="BOT_TV_DAILY_ALERT_TIME" name="BOT_TV_DAILY_ALERT_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_DAILY_ALERT_TIME', '08:00')) ?>">
                </div>
                <div>
                    <label for="BOT_TV_DISCOVERY_DAYS_AHEAD">Discovery Days Ahead</label>
                    <input id="BOT_TV_DISCOVERY_DAYS_AHEAD" name="BOT_TV_DISCOVERY_DAYS_AHEAD" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_DISCOVERY_DAYS_AHEAD', '7')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_TV_LOOKAHEAD_HOURS">Lookahead Hours</label>
                    <input id="BOT_TV_LOOKAHEAD_HOURS" name="BOT_TV_LOOKAHEAD_HOURS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_LOOKAHEAD_HOURS', '24')) ?>" inputmode="numeric">
                </div>
                <div>
                    <label for="BOT_TV_MAX_EVENTS_PER_CHANNEL">Max Events Per Channel</label>
                    <input id="BOT_TV_MAX_EVENTS_PER_CHANNEL" name="BOT_TV_MAX_EVENTS_PER_CHANNEL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_MAX_EVENTS_PER_CHANNEL', '20')) ?>" inputmode="numeric">
                </div>
                <div class="field-full">
                    <label for="DEBUG_TV_EVENT_ID">Debug TV for Event ID</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input id="DEBUG_TV_EVENT_ID" name="DEBUG_TV_EVENT_ID" placeholder="Enter TheSportsDB idEvent" style="flex:1">
                        <button class="secondary" name="action" value="debug_tv_for_event" type="submit">Debug TV for Event ID</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-title"><h3>API And Rendering</h3></div>
            <div class="field-grid three">
                <div><label for="BOT_API_MIN_INTERVAL_MS">API Min Interval Ms</label><input id="BOT_API_MIN_INTERVAL_MS" name="BOT_API_MIN_INTERVAL_MS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_API_MIN_INTERVAL_MS', '350')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_LIVESCORE_CACHE_TTL">Livescore TTL</label><input id="BOT_LIVESCORE_CACHE_TTL" name="BOT_LIVESCORE_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_LIVESCORE_CACHE_TTL', '75')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_TIMELINE_CACHE_TTL">Timeline TTL</label><input id="BOT_TIMELINE_CACHE_TTL" name="BOT_TIMELINE_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TIMELINE_CACHE_TTL', '45')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_LOOKUP_CACHE_TTL">Lookup TTL</label><input id="BOT_LOOKUP_CACHE_TTL" name="BOT_LOOKUP_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_LOOKUP_CACHE_TTL', '604800')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_TV_CACHE_TTL">TV TTL</label><input id="BOT_TV_CACHE_TTL" name="BOT_TV_CACHE_TTL" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_TV_CACHE_TTL', '900')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_MAX_LIVE_MATCHES_PER_RUN">Max Live Matches</label><input id="BOT_MAX_LIVE_MATCHES_PER_RUN" name="BOT_MAX_LIVE_MATCHES_PER_RUN" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_LIVE_MATCHES_PER_RUN', '25')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_MAX_LIVE_MATCHES_PER_SPORT">Max Live Per Sport</label><input id="BOT_MAX_LIVE_MATCHES_PER_SPORT" name="BOT_MAX_LIVE_MATCHES_PER_SPORT" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_MAX_LIVE_MATCHES_PER_SPORT', '8')) ?>" inputmode="numeric"></div>
                <div>
                    <label for="BOT_RENDER_ENGINE">Render Engine</label>
                    <select id="BOT_RENDER_ENGINE" name="BOT_RENDER_ENGINE">
                        <?php foreach (['auto', 'puppeteer', 'gd'] as $engine): ?>
                            <option value="<?= htmlspecialchars($engine) ?>" <?= admin_env_value($env, 'BOT_RENDER_ENGINE', 'auto') === $engine ? 'selected' : '' ?>><?= htmlspecialchars($engine) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="BOT_IMAGE_QUALITY">PNG Quality</label><input id="BOT_IMAGE_QUALITY" name="BOT_IMAGE_QUALITY" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_IMAGE_QUALITY', '9')) ?>" inputmode="numeric"></div>
                <div><label for="BOT_IMAGE_CLEANUP_SECONDS">Image Cleanup Seconds</label><input id="BOT_IMAGE_CLEANUP_SECONDS" name="BOT_IMAGE_CLEANUP_SECONDS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_IMAGE_CLEANUP_SECONDS', '86400')) ?>" inputmode="numeric"></div>
                <div class="field-full"><label for="BOT_RENDER_CHROME_PATH">Chrome Path</label><input id="BOT_RENDER_CHROME_PATH" name="BOT_RENDER_CHROME_PATH" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_RENDER_CHROME_PATH')) ?>"></div>
                <div class="field-full"><label for="BOT_RENDER_USER_DATA_DIR">Chrome User Data Dir</label><input id="BOT_RENDER_USER_DATA_DIR" name="BOT_RENDER_USER_DATA_DIR" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_RENDER_USER_DATA_DIR', $config['paths']['cache'] . '/chrome')) ?>"></div>
                <div class="field-full"><label for="BOT_RENDER_EXTRA_ARGS">Extra Chrome Args</label><input id="BOT_RENDER_EXTRA_ARGS" name="BOT_RENDER_EXTRA_ARGS" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_RENDER_EXTRA_ARGS')) ?>" placeholder="--disable-gpu,--single-process"></div>
            </div>
            <div class="toggle-grid">
                <label class="toggle"><input name="BOT_IMAGE_PRESERVE_SAMPLE_IMAGES" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_IMAGE_PRESERVE_SAMPLE_IMAGES', true) ? 'checked' : '' ?>><b>Preserve sample images</b></label>
                <label class="toggle"><input name="BOT_HEALTH_ALERTS_ENABLED" type="checkbox" value="1" <?= admin_env_bool($env, 'BOT_HEALTH_ALERTS_ENABLED', true) ? 'checked' : '' ?>><b>Health summaries<span>Send daily operator health summaries to the error chat.</span></b></label>
            </div>
            <div class="field-grid">
                <div>
                    <label for="BOT_FONT_REGULAR">Regular Font Path</label>
                    <input id="BOT_FONT_REGULAR" name="BOT_FONT_REGULAR" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_FONT_REGULAR', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')) ?>">
                </div>
                <div>
                    <label for="BOT_FONT_BOLD">Bold Font Path</label>
                    <input id="BOT_FONT_BOLD" name="BOT_FONT_BOLD" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_FONT_BOLD', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf')) ?>">
                </div>
                <div>
                    <label for="BOT_HEALTH_ALERT_TIME">Health Alert Time</label>
                    <input id="BOT_HEALTH_ALERT_TIME" name="BOT_HEALTH_ALERT_TIME" value="<?= htmlspecialchars(admin_env_value($env, 'BOT_HEALTH_ALERT_TIME', '07:30')) ?>">
                </div>
                <div class="field-full" id="profile-editor">
                    <label for="BOT_SPORT_PROFILES_JSON">Sport Profile Overrides JSON</label>
                    <textarea id="BOT_SPORT_PROFILES_JSON" name="BOT_SPORT_PROFILES_JSON" placeholder='{"Basketball":{"start_label":"Tip-off","period_label":"Quarter"}}'><?= htmlspecialchars(admin_env_value($env, 'BOT_SPORT_PROFILES_JSON')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-title"><h3>Admin Access</h3></div>
            <div class="field-grid">
                <div><label for="new_password">New Password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password"></div>
                <div><label for="confirm_password">Confirm Password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password"></div>
            </div>
        </div>

        <div class="actions sticky-actions">
            <button type="submit">Save Settings</button>
        </div>
    </form>
</div>
