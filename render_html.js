#!/usr/bin/env node

/**
 * Puppeteer-based HTML-to-PNG renderer for football alert images.
 * Reads alert JSON from stdin, renders the HTML template, outputs PNG.
 *
 * Usage: echo '{"alert":{...},"outputPath":"..."}' | node render_html.js
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

async function main() {
    let input;
    try {
        input = JSON.parse(fs.readFileSync('/dev/stdin', 'utf8'));
    } catch (e) {
        console.error('Failed to parse stdin JSON:', e.message);
        process.exit(1);
    }

    const alert = input.alert || {};
    const match = alert.match || {};
    const meta = alert.meta || {};
    const outputPath = input.outputPath;
    const assetsDir = input.assetsDir || path.join(__dirname, 'assets');
    const cacheDir = input.cacheDir || path.join(__dirname, 'cache', 'images');
    const fontsDir = input.fontsDir || path.join(__dirname, 'fonts');
    const render = input.render || {};
    const renderUserDataDir = render.userDataDir || path.join(__dirname, 'cache', 'chrome');
    const renderChromePath = render.chromePath || process.env.PUPPETEER_EXECUTABLE_PATH || '';
    const renderExtraArgs = Array.isArray(render.extraArgs) ? render.extraArgs.filter(Boolean) : [];

    if (!outputPath) {
        console.error('No outputPath provided');
        process.exit(1);
    }

    // Check render type
    const isTvSchedule = (input.type === 'TV_SCHEDULE') || (alert.type === 'TV_SCHEDULE');
    const isDailyCard = (input.type === 'DAILY_CARD');
    const isMatchdayCard = (input.type === 'MATCHDAY_CARD');

    // Theme colors per alert type
    const themes = {
        GOAL:              { headline: 'GOAL',              accent: '#12f2a3', accentMid: '#1ec878', accentDark: '#08794f' },
        KICK_OFF:          { headline: 'KICK-OFF',           accent: '#40adff', accentMid: '#1e78dc', accentDark: '#145fb5' },
        MATCH_START:       { headline: 'STARTED',            accent: '#40adff', accentMid: '#1e78dc', accentDark: '#145fb5' },
        SCORE_UPDATE:      { headline: 'SCORE UPDATE',       accent: '#12f2a3', accentMid: '#1ec878', accentDark: '#08794f' },
        PERIOD_CHANGE:     { headline: 'UPDATE',             accent: '#64c8ff', accentMid: '#288cdc', accentDark: '#1e78b4' },
        HALF_TIME:         { headline: 'HALF-TIME',          accent: '#ffbe4b', accentMid: '#c88c1e', accentDark: '#bf6e19' },
        FULL_TIME:         { headline: 'FULL-TIME',          accent: '#ffffff', accentMid: '#b4bed2', accentDark: '#5f7696' },
        RED_CARD:          { headline: 'RED CARD',           accent: '#ff4a5c', accentMid: '#c82837', accentDark: '#a81628' },
        YELLOW_CARD:       { headline: 'YELLOW CARD',       accent: '#ffc832', accentMid: '#c89b14', accentDark: '#bf910a' },
        SUBSTITUTION:      { headline: 'SUBSTITUTION',       accent: '#64c8ff', accentMid: '#288cdc', accentDark: '#1e78b4' },
        MATCH_PREVIEW:     { headline: 'UPCOMING',            accent: '#a08cff', accentMid: '#6e50dc', accentDark: '#5a3cb4' },
        KICKOFF_REMINDER:  { headline: 'KICK-OFF SOON',      accent: '#ffc83c', accentMid: '#dca014', accentDark: '#b4820a' },
    };
    const theme = themes[alert.type] || themes.GOAL;

    // Build detail lines
    let detailLines = [];
    switch (alert.type) {
        case 'GOAL':
            detailLines.push((meta.scorer || 'Scorer unavailable') + ' scores for ' + (meta.team || 'their team'));
            if (meta.assist) detailLines.push('Assist: ' + meta.assist);
            break;
        case 'KICK_OFF':
            detailLines.push('Kick-off ' + (meta.event_time || 'now'));
            break;
        case 'MATCH_START':
            detailLines.push(meta.start_label || ((match.sport || 'Match') + ' started'));
            detailLines.push(meta.event_time || 'Live now');
            break;
        case 'SCORE_UPDATE':
            detailLines.push(meta.update_label || 'Score update');
            if (meta.previous_score) detailLines.push('Previously ' + meta.previous_score);
            if (match.league_name) detailLines.push(match.league_name);
            break;
        case 'PERIOD_CHANGE':
            detailLines.push((meta.period_label || 'Status') + ': ' + (meta.status_label || meta.status || match.status || 'Live'));
            if (match.league_name) detailLines.push(match.league_name);
            break;
        case 'HALF_TIME':
            detailLines.push('Half-time at the interval');
            break;
        case 'FULL_TIME':
            detailLines.push(meta.final_label || 'Final score');
            if (meta.scorers && meta.scorers.home && meta.scorers.home.length)
                detailLines.push(match.home_team + ': ' + meta.scorers.home.slice(0, 4).join(', '));
            if (meta.scorers && meta.scorers.away && meta.scorers.away.length)
                detailLines.push(match.away_team + ': ' + meta.scorers.away.slice(0, 4).join(', '));
            break;
        case 'RED_CARD':
            detailLines.push((meta.player || 'Player unavailable') + ' sent off');
            if (meta.team) detailLines.push(meta.team);
            break;
        case 'YELLOW_CARD':
            detailLines.push((meta.player || 'Player unavailable') + ' booked');
            if (meta.team) detailLines.push(meta.team);
            break;
        case 'SUBSTITUTION':
            detailLines.push((meta.player_on || 'Player on') + ' comes on');
            if (meta.player_off) detailLines.push('Replacing ' + meta.player_off);
            if (meta.team) detailLines.push(meta.team);
            break;
        case 'MATCH_PREVIEW':
            detailLines.push(meta.event_time || 'Kick-off time TBC');
            if (meta.tv_channels && meta.tv_channels.length) {
                detailLines.push('TV: ' + meta.tv_channels.slice(0, 3).join(', '));
            }
            if (meta.league_name) detailLines.push(meta.league_name);
            break;
        case 'KICKOFF_REMINDER':
            detailLines.push('Kick-off in ' + (meta.minutes_until || 10) + ' minutes');
            if (meta.event_time) detailLines.push(meta.event_time);
            if (meta.tv_channels && meta.tv_channels.length) {
                detailLines.push('TV: ' + meta.tv_channels.slice(0, 3).join(', '));
            }
            if (meta.league_name) detailLines.push(meta.league_name);
            break;
    }

    // Convert URL to data URI for badges
    function toDataUri(url) {
        if (!url) return '';
        try {
            const hash = crypto.createHash('sha1').update(url).digest('hex');
            const ext = (url.split('.').pop() || 'png').split('?')[0];
            const cached = path.join(cacheDir, hash + '.' + ext);
            if (fs.existsSync(cached)) {
                const data = fs.readFileSync(cached);
                return 'data:image/png;base64,' + data.toString('base64');
            }
        } catch (e) { /* fall through to URL */ }
        return url;
    }

    // Build badge content
    function badgeContent(url, initials) {
        const src = toDataUri(url);
        if (src) {
            return `<img src="${src}" alt="">`;
        }
        return `<span class="badge-initials">${initials || ''}</span>`;
    }

    const homeInitials = (match.home_team || '').split(' ').map(w => w[0]).join('').substring(0, 3);
    const awayInitials = (match.away_team || '').split(' ').map(w => w[0]).join('').substring(0, 3);

    // Build detail HTML
    const detailHtml = detailLines.map(l => `<div class="info-line">${escHtml(l)}</div>`).join('\n');

    // Player thumbnail
    let playerThumbHtml = '';
    if (meta.player_image) {
        const src = toDataUri(meta.player_image);
        playerThumbHtml = `<img class="player-thumb" src="${src}" alt="">`;
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Helper: convert asset file:// URLs to data URIs
    function convertAssetUrls(html) {
        html = html.replace(/\{\{ASSETS_DIR\}\}/g, assetsDir.replace(/\\/g, '/'));
        const assetUrlRegex = /url\(\s*'file:\/\/([^']+)'\s*\)/g;
        return html.replace(assetUrlRegex, (match, filePath) => {
            const absPath = decodeURIComponent(filePath);
            try {
                if (fs.existsSync(absPath)) {
                    const data = fs.readFileSync(absPath);
                    const ext = path.extname(absPath).toLowerCase().replace('.', '');
                    const mime = ext === 'png' ? 'image/png' : ext === 'jpg' || ext === 'jpeg' ? 'image/jpeg' : 'image/png';
                    return `url('data:${mime};base64,${data.toString('base64')}')`;
                }
            } catch (e) { /* fall through to original */ }
            return match;
        });
    }

    let html;

    if (isMatchdayCard) {
        // === MATCHDAY SINGLE-FIXTURE CARD RENDERING ===
        const card = input.matchdayCard || {};
        const sections = Array.isArray(card.sections) ? card.sections : [];
        const kind = card.kind || 'match';
        const cardType = card.card_type || 'FIXTURES_BURST';
        const sequence = Math.max(1, Number(card.sequence || card.page || 1));
        const sequenceCount = Math.max(1, Number(card.sequence_count || card.total_count || card.page_count || 1));
        const themesByType = {
            FIXTURES_BURST: { label: 'FIXTURE', accent: '#58b8ff', soft: 'rgba(88,184,255,0.18)' },
            KICKOFF_SOON: { label: 'KICK-OFF SOON', accent: '#ffb32c', soft: 'rgba(255,179,44,0.20)' },
            LIVE_NOW: { label: 'LIVE', accent: '#ff3f4c', soft: 'rgba(255,63,76,0.22)' },
            TV_GUIDE: { label: 'TV GUIDE', accent: '#42d3ff', soft: 'rgba(66,211,255,0.18)' },
            TV_NOW: { label: 'WATCH LIVE', accent: '#42d3ff', soft: 'rgba(66,211,255,0.18)' },
            RESULTS_ROUNDUP: { label: 'FULL-TIME', accent: '#19d46f', soft: 'rgba(25,212,111,0.20)' },
            TOMORROW_LOOKAHEAD: { label: 'TOMORROW', accent: '#9bd56f', soft: 'rgba(155,213,111,0.18)' },
            MORNING_PLANNER: { label: 'PLANNER', accent: '#58b8ff', soft: 'rgba(88,184,255,0.18)' },
            WEEKEND_PLANNER: { label: 'WEEKEND', accent: '#ffb32c', soft: 'rgba(255,179,44,0.20)' },
        };
        const themeForCard = themesByType[cardType] || themesByType.FIXTURES_BURST;

        function initials(name, length = 3) {
            const parts = String(name || '').split(/\s+/).filter(Boolean);
            const text = (parts.length > 1 ? parts.map(w => w[0]).join('') : String(name || '').substring(0, length)).toUpperCase();
            return escHtml(text.substring(0, length));
        }

        function logoHtml(url, name, className) {
            const src = toDataUri(url || '');
            if (src) {
                return `<img class="${className}" src="${escHtml(src)}" alt="" onerror="this.nextElementSibling.classList.remove('logo-hidden');this.remove()"><span class="${className} fallback logo-hidden">${initials(name)}</span>`;
            }
            return `<div class="${className} fallback">${initials(name)}</div>`;
        }

        function firstMatch() {
            if (card.match) return card.match;
            for (const section of sections) {
                if (Array.isArray(section.matches) && section.matches.length) return section.matches[0];
            }
            return null;
        }

        function firstTvEvent() {
            if (card.event) return card.event;
            for (const section of sections) {
                if (Array.isArray(section.events) && section.events.length) return section.events[0];
            }
            return null;
        }

        function formatDateLabel(value, fallback = '') {
            const raw = String(value || '').trim();
            const dateMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);

            if (dateMatch) {
                const date = new Date(`${dateMatch[1]}-${dateMatch[2]}-${dateMatch[3]}T12:00:00`);
                return new Intl.DateTimeFormat('en-GB', {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                }).format(date);
            }

            if (raw) {
                const parsed = new Date(raw);
                if (!Number.isNaN(parsed.getTime())) {
                    return new Intl.DateTimeFormat('en-GB', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short',
                    }).format(parsed);
                }
            }

            return fallback || 'Date TBC';
        }

        function formatTimeLabel(...values) {
            for (const value of values) {
                const raw = String(value || '').trim();
                if (!raw) continue;
                const match = raw.match(/\b([01]?\d|2[0-3]):([0-5]\d)\b/);
                if (match) return `${match[1].padStart(2, '0')}:${match[2]}`;
                const parsed = new Date(raw);
                if (!Number.isNaN(parsed.getTime())) {
                    return new Intl.DateTimeFormat('en-GB', {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: false,
                    }).format(parsed);
                }
            }

            return 'TBC';
        }

        function tvChannelDetails(match) {
            const details = Array.isArray(match.tv_channel_details) ? match.tv_channel_details : [];
            if (details.length) return details;

            const names = Array.isArray(match.tv_channels) ? match.tv_channels : [];
            return names.map(name => ({ name: String(name), logo: '' }));
        }

        function tvChips(details, emptyLabel = 'TV TBC') {
            const items = Array.isArray(details) ? details.filter(Boolean).slice(0, 4) : [];
            if (!items.length) {
                return `<div class="tv-channel-chip empty"><span>${escHtml(emptyLabel)}</span></div>`;
            }

            return items.map(item => {
                const name = typeof item === 'string' ? item : (item.name || item.channel || 'TV');
                const logo = typeof item === 'string' ? '' : (item.logo || '');
                const icon = logo ? logoHtml(logo, name, 'tv-logo') : '<span class="tv-dot"></span>';
                return `<div class="tv-channel-chip">${icon}<span>${escHtml(name)}</span></div>`;
            }).join('');
        }

        function scoreValue(value) {
            return value === null || value === undefined || value === '' ? '0' : String(value);
        }

        function statusUpper(value) {
            return String(value || '').trim().toUpperCase();
        }

        function isLiveStatus(value) {
            const status = statusUpper(value);
            return ['LIVE', '1H', '2H', 'ET', 'P', 'PEN', 'AET', 'IN PLAY', 'HT', 'HALF TIME', 'HALFTIME', 'Q1', 'Q2', 'Q3', 'Q4', '1Q', '2Q', '3Q', '4Q', 'OT', 'SO'].includes(status)
                || /^\d+/.test(status);
        }

        function isFullTimeStatus(value) {
            const status = statusUpper(value);
            return ['FT', 'FULLTIME', 'FULL TIME', 'FINAL', 'FINAL/OT', 'AET', 'PEN'].includes(status);
        }

        function minuteLabel(match) {
            const progress = match.progress === null || match.progress === undefined ? '' : String(match.progress).trim();
            if (progress !== '' && /^\d+/.test(progress)) return `${parseInt(progress, 10)}'`;

            const status = String(match.status || '').trim();
            if (/^\d+/.test(status)) return `${parseInt(status, 10)}'`;
            if (isFullTimeStatus(status)) return 'FT';
            if (statusUpper(status) === 'HT' || statusUpper(status).includes('HALF')) return 'HT';
            if (isLiveStatus(status)) return 'LIVE';
            return '';
        }

        function minutesUntilStart(match) {
            const raw = String(match.starts_at || '').trim();
            if (!raw) return null;

            const parsed = new Date(raw);
            if (Number.isNaN(parsed.getTime())) return null;

            const diff = parsed.getTime() - Date.now();
            if (diff < -60000 || diff > 6 * 60 * 60000) return null;

            return Math.max(0, Math.ceil(diff / 60000));
        }

        function compactText(value, fallback = '') {
            const text = String(value || '').trim();
            return text === '' ? fallback : text;
        }

        function tvText(details, emptyLabel = 'TV TBC') {
            const items = Array.isArray(details) ? details.filter(Boolean).slice(0, 2) : [];
            if (!items.length) return emptyLabel;

            return items.map(item => typeof item === 'string' ? item : (item.name || item.channel || 'TV')).filter(Boolean).join(', ');
        }

        function hashtag(value) {
            const tag = String(value || '').replace(/&/g, 'and').replace(/[^A-Za-z0-9]/g, '');
            return tag ? '#' + tag.substring(0, 24) : '';
        }

        function hashtags(...values) {
            const tags = [];
            for (const value of values) {
                const tag = hashtag(value);
                if (tag && !tags.includes(tag)) tags.push(tag);
                if (tags.length >= 3) break;
            }
            return tags.join(' ');
        }

        function metaItem(label, value) {
            const clean = compactText(value);
            if (!clean) return '';
            return `<div class="meta-item"><span>${escHtml(label)}</span><strong>${escHtml(clean)}</strong></div>`;
        }

        function liveStatusLabel(match) {
            const status = compactText(match.status, '');
            const minute = minuteLabel(match);
            if (minute && minute !== 'LIVE' && minute !== 'FT') return minute;
            if (status) return status;
            return 'Live';
        }

        function renderMatchPoster(match) {
            const section = card.section || sections[0] || {};
            const home = match.home_team || 'Home';
            const away = match.away_team || 'Away';
            const league = match.league_name || section.title || 'Match';
            const sport = match.sport || section.sport || section.subtitle || card.sport || 'Sport';
            const time = formatTimeLabel(match.event_time, match.starts_at);
            const homeScore = scoreValue(match.home_score);
            const awayScore = scoreValue(match.away_score);
            const live = cardType === 'LIVE_NOW' || isLiveStatus(match.status || match.progress);
            const result = cardType === 'RESULTS_ROUNDUP' || isFullTimeStatus(match.status);
            const kickoffSoon = cardType === 'KICKOFF_SOON';
            const showScore = live || result;
            const minsUntil = minutesUntilStart(match);
            const topLabel = result ? 'FULL-TIME' : (live ? 'LIVE' : themeForCard.label);
            const topClass = result ? 'is-result' : (live ? 'is-live' : (kickoffSoon ? 'is-soon' : ''));
            const timeChip = result ? 'FT' : (live ? (minuteLabel(match) || 'LIVE') : (minsUntil !== null ? `${String(minsUntil).padStart(2, '0')}'` : time));
            const centre = showScore
                ? `<div class="scoreline"><span>${escHtml(homeScore)}</span><i>-</i><span>${escHtml(awayScore)}</span></div>`
                : `<div class="versus-mark">VS</div><div class="kickoff-time">${escHtml(time)}</div>`;
            const centreLabel = result ? 'FULL-TIME' : (live ? 'LIVE' : (kickoffSoon && minsUntil !== null ? 'TO KICK-OFF' : 'KICK-OFF'));

            return `<section class="telegram-card match-poster ${topClass}">
                <div class="hero">
                    <div class="hero-badge">${escHtml(topLabel)}</div>
                    <div class="hero-league">${escHtml(league)}</div>
                    <div class="hero-clock">${escHtml(timeChip)}</div>
                    <div class="teams-row">
                        <div class="team-block">
                            ${logoHtml(match.home_badge || match.league_logo || '', home, 'club-logo')}
                            <h2>${escHtml(home)}</h2>
                        </div>
                        <div class="score-block">
                            ${centre}
                            <span>${escHtml(centreLabel)}</span>
                        </div>
                        <div class="team-block">
                            ${logoHtml(match.away_badge || match.league_logo || '', away, 'club-logo')}
                            <h2>${escHtml(away)}</h2>
                        </div>
                    </div>
                </div>
            </section>`;
        }

        function renderTvEventPoster(event) {
            const home = event.home_team || '';
            const away = event.away_team || '';
            const hasTeams = home !== '' && away !== '';
            const channel = event.configured_channel_label || event.channel || 'TV';
            const time = formatTimeLabel(event.time, event.time_label, event.starts_at);
            const sport = compactText(event.sport, 'Sport');
            const league = compactText(event.league, 'TV Sports');
            const name = hasTeams ? `${home} vs ${away}` : (event.event || 'TV event');

            if (hasTeams) {
                return `<section class="telegram-card match-poster tv-card">
                    <div class="hero">
                        <div class="hero-badge">${escHtml(themeForCard.label)}</div>
                        <div class="hero-league">${escHtml(league)}</div>
                        <div class="hero-clock">${escHtml(time)}</div>
                        <div class="teams-row">
                            <div class="team-block">
                                ${logoHtml(event.home_badge || event.channel_logo || '', home, 'club-logo')}
                                <h2>${escHtml(home)}</h2>
                            </div>
                            <div class="score-block">
                                <div class="versus-mark">VS</div>
                                <div class="kickoff-time">${escHtml(time)}</div>
                                <span>ON TV</span>
                            </div>
                            <div class="team-block">
                                ${logoHtml(event.away_badge || event.channel_logo || '', away, 'club-logo')}
                                <h2>${escHtml(away)}</h2>
                            </div>
                        </div>
                    </div>
                </section>`;
            }

            return `<section class="telegram-card match-poster tv-card solo-event">
                <div class="hero solo-hero">
                    <div class="hero-badge">${escHtml(themeForCard.label)}</div>
                    <div class="hero-league">${escHtml(channel)}</div>
                    <div class="hero-clock">${escHtml(time)}</div>
                    <div class="solo-feature">
                        ${logoHtml(event.channel_logo || '', channel, 'club-logo')}
                        <div>
                            <h2>${escHtml(name)}</h2>
                            <p>${escHtml([sport, league].filter(Boolean).join(' - '))}</p>
                        </div>
                    </div>
                </div>
            </section>`;
        }

        const matchForCard = firstMatch();
        const eventForCard = firstTvEvent();
        let rowsHtml = matchForCard && kind !== 'tv_event'
            ? renderMatchPoster(matchForCard)
            : (eventForCard ? renderTvEventPoster(eventForCard) : '');

        if (!rowsHtml) {
            rowsHtml = '<div class="empty-card">No events in this window</div>';
        }

        const matchdayTemplatePath = path.join(__dirname, 'matchday_card_template.html');
        html = fs.readFileSync(matchdayTemplatePath, 'utf8');
        html = convertAssetUrls(html);
        html = html.replace(/\{\{ACCENT\}\}/g, themeForCard.accent);
        html = html.replace(/\{\{ACCENT_SOFT\}\}/g, themeForCard.soft);
        html = html.replace(/\{\{TYPE_LABEL\}\}/g, escHtml(themeForCard.label));
        html = html.replace(/\{\{TITLE\}\}/g, escHtml(card.title || 'Sports Card'));
        html = html.replace(/\{\{SUBTITLE\}\}/g, escHtml(card.subtitle || ''));
        html = html.replace(/\{\{TIMEZONE\}\}/g, escHtml(card.timezone_label || 'UK time'));
        html = html.replace(/\{\{UPDATED\}\}/g, escHtml(card.generated_at || ''));
        html = html.replace(/\{\{PAGE_LABEL\}\}/g, sequenceCount > 1 ? `Card ${sequence}/${sequenceCount}` : '');
        html = html.replace(/\{\{COUNT_LABEL\}\}/g, sequenceCount > 1 ? `${sequence}/${sequenceCount}` : '1');
        html = html.replace(/\{\{COUNT_CAPTION\}\}/g, kind === 'tv_event' ? 'listing' : 'match card');
        html = html.replace(/\{\{ROWS\}\}/g, rowsHtml);
    } else if (isDailyCard) {
        // === DAILY MATCH CARD RENDERING ===
        const cardData = input.dailyCard || {};
        const leagues = cardData.leagues || [];
        const dateLabel = escHtml(cardData.dateLabel || 'Today');
        const totalMatches = leagues.reduce((sum, l) => sum + (l.matches || []).length, 0);
        const dailySports = Array.from(new Set(leagues.map(l => l.sport || '').filter(Boolean)));

        // Build league rows HTML
        let leagueRowsHtml = '';
        const maxLeagues = 4;
        const maxMatchesPerLeague = 3;
        let shownLeagues = 0;

        for (const league of leagues) {
            if (shownLeagues >= maxLeagues) break;
            const matches = (league.matches || []).slice(0, maxMatchesPerLeague);
            const leagueLogo = league.league_logo || '';
            const leagueLogoSrc = toDataUri(leagueLogo);
            const leagueInitials = escHtml((league.league_name || '').split(' ').map(w => w[0]).join('').substring(0, 3).toUpperCase());
            const leagueLabel = dailySports.length > 1 && league.sport
                ? `${league.sport} - ${league.league_name || 'Unknown League'}`
                : (league.league_name || 'Unknown League');
            const logoHtml = leagueLogoSrc
                ? `<img class="league-logo" src="${leagueLogoSrc}" alt="">`
                : `<div class="league-logo-placeholder">${leagueInitials}</div>`;

            let matchesHtml;
            if (matches.length === 0) {
                matchesHtml = '<div class="no-matches">No matches today</div>';
            } else {
                matchesHtml = matches.map(m => {
                    const time = escHtml((m.event_time || '').substring(0, 5) || 'TBC');
                    const homeTeam = escHtml(m.home_team || 'Home');
                    const awayTeam = escHtml(m.away_team || 'Away');
                    const homeBadge = m.home_badge || '';
                    const awayBadge = m.away_badge || '';

                    const homeBadgeHtml = homeBadge
                        ? `<img class="team-badge" src="${toDataUri(homeBadge)}" alt="">`
                        : `<span class="team-badge-placeholder">${escHtml(homeTeam.substring(0, 2).toUpperCase())}</span>`;
                    const awayBadgeHtml = awayBadge
                        ? `<img class="team-badge" src="${toDataUri(awayBadge)}" alt="">`
                        : `<span class="team-badge-placeholder">${escHtml(awayTeam.substring(0, 2).toUpperCase())}</span>`;

                    const tvChannels = m.tv_channels || [];
                    const tvHtml = tvChannels.length > 0
                        ? `<span class="match-tv">${tvChannels.map(ch => `<span class="tv-badge">${escHtml(ch)}</span>`).join('')}</span>`
                        : '';

                    return `<div class="match-row">
                        <span class="match-time">${time}</span>
                        <span class="match-teams">
                            <span class="match-team">
                                ${homeBadgeHtml}
                                <span class="team-name">${homeTeam}</span>
                            </span>
                            <span class="match-vs">vs</span>
                            <span class="match-team">
                                ${awayBadgeHtml}
                                <span class="team-name">${awayTeam}</span>
                            </span>
                        </span>
                        ${tvHtml}
                    </div>`;
                }).join('\n');
            }

            leagueRowsHtml += `<div class="league-row">
                <div class="league-name">
                    ${logoHtml}
                    <div class="league-label">${escHtml(leagueLabel)}</div>
                </div>
                <div class="league-matches">
                    ${matchesHtml}
                </div>
            </div>\n`;
            shownLeagues++;
        }

        if (leagues.length === 0) {
            leagueRowsHtml = '<div class="no-matches">No matches today</div>';
        }

        const cardTemplatePath = path.join(__dirname, 'daily_card_template.html');
        html = fs.readFileSync(cardTemplatePath, 'utf8');
        html = convertAssetUrls(html);
        html = html.replace(/\{\{DATE_LABEL\}\}/g, dateLabel);
        html = html.replace(/\{\{MATCH_COUNT\}\}/g, String(totalMatches));
        html = html.replace(/\{\{LEAGUE_COUNT\}\}/g, String(leagues.length));
        html = html.replace(/\{\{LEAGUE_ROWS\}\}/g, leagueRowsHtml);
    } else if (isTvSchedule) {
        // === TV SCHEDULE RENDERING ===
        const tvData = input.tvSchedule || {};
        const channels = tvData.channels || [];
        const events = tvData.events || [];
        const dateLabel = escHtml(tvData.dateLabel || 'TV Sports Guide');
        const sportLabel = escHtml(tvData.sportLabel || 'All Sports');
        const hoursAhead = tvData.hoursAhead || 24;

        // Group events by channel slug
        const byChannel = {};
        for (const ev of events) {
            const slug = ev.configured_channel_slug || ev.channel_slug || 'other';
            if (!byChannel[slug]) byChannel[slug] = [];
            byChannel[slug].push(ev);
        }

        // Build channel rows HTML
        let channelRowsHtml = '';
        const maxChannels = 4;
        const maxEventsPerChannel = 3;
        let shownChannels = 0;

        for (const ch of channels) {
            if (shownChannels >= maxChannels) break;
            const chEvents = (byChannel[ch.slug] || []).slice(0, maxEventsPerChannel);
            const logoUrl = ch.logo || (chEvents.length > 0 ? chEvents[0].channel_logo : '');
            const logoSrc = toDataUri(logoUrl);
            const logoHtml = logoSrc
                ? `<img class="channel-logo" src="${logoSrc}" alt="">`
                : `<div class="channel-logo-placeholder">${escHtml((ch.label || '').substring(0, 2).toUpperCase())}</div>`;

            let eventsHtml;
            if (chEvents.length === 0) {
                eventsHtml = '<div class="no-events">No listed events</div>';
            } else {
                eventsHtml = chEvents.map(ev => {
                    const time = escHtml(ev.time_label || '');
                    const homeTeam = escHtml(ev.home_team || '');
                    const awayTeam = escHtml(ev.away_team || '');
                    const eventName = escHtml(ev.event || '');
                    const league = escHtml(ev.league || '');
                    const homeBadge = ev.home_badge || '';
                    const awayBadge = ev.away_badge || '';

                    // Use fixture-style display when team data is available
                    if (homeTeam && awayTeam) {
                        const homeBadgeHtml = homeBadge
                            ? `<img class="team-badge" src="${toDataUri(homeBadge)}" alt="">`
                            : `<span class="team-badge-placeholder">${escHtml(homeTeam.substring(0, 2).toUpperCase())}</span>`;
                        const awayBadgeHtml = awayBadge
                            ? `<img class="team-badge" src="${toDataUri(awayBadge)}" alt="">`
                            : `<span class="team-badge-placeholder">${escHtml(awayTeam.substring(0, 2).toUpperCase())}</span>`;

                        return `<div class="event-row fixture-row">
                            <span class="event-time">${time}</span>
                            <span class="fixture-team home">
                                ${homeBadgeHtml}
                                <span class="team-name">${homeTeam}</span>
                            </span>
                            <span class="fixture-vs">vs</span>
                            <span class="fixture-team away">
                                ${awayBadgeHtml}
                                <span class="team-name">${awayTeam}</span>
                            </span>
                            ${league ? `<span class="event-league">${league}</span>` : ''}
                        </div>`;
                    }

                    return `<div class="event-row">
                        <span class="event-time">${time}</span>
                        <span class="event-teams">${eventName}</span>
                        ${league ? `<span class="event-league">${league}</span>` : ''}
                    </div>`;
                }).join('\n');
            }

            channelRowsHtml += `<div class="channel-row">
                <div class="channel-name">
                    ${logoHtml}
                    <div class="channel-label">${escHtml(ch.label || ch.slug)}</div>
                </div>
                <div class="channel-events">
                    ${eventsHtml}
                </div>
            </div>\n`;
            shownChannels++;
        }

        if (channels.length === 0) {
            channelRowsHtml = '<div class="no-events">No TV channels configured</div>';
        }

        const totalEvents = events.length;

        const tvTemplatePath = path.join(__dirname, 'tv_guide_template.html');
        html = fs.readFileSync(tvTemplatePath, 'utf8');
        html = convertAssetUrls(html);
        html = html.replace(/\{\{DATE_LABEL\}\}/g, dateLabel);
        html = html.replace(/\{\{SPORT_LABEL\}\}/g, sportLabel);
        html = html.replace(/\{\{CHANNEL_ROWS\}\}/g, channelRowsHtml);
        html = html.replace(/\{\{EVENT_COUNT\}\}/g, String(totalEvents));
        html = html.replace(/\{\{CHANNEL_COUNT\}\}/g, String(channels.length));
    } else {
        // === MATCH ALERT RENDERING ===
        const templatePath = path.join(__dirname, 'alert_template.html');
        html = fs.readFileSync(templatePath, 'utf8');
        html = convertAssetUrls(html);
        html = html.replace(/\{\{ACCENT\}\}/g, theme.accent);
        html = html.replace(/\{\{ACCENT_MID\}\}/g, theme.accentMid);
        html = html.replace(/\{\{ACCENT_DARK\}\}/g, theme.accentDark);
        html = html.replace(/\{\{HEADLINE\}\}/g, escHtml(theme.headline));
        html = html.replace(/\{\{HEADLINE_CLASS\}\}/g, theme.headline.length > 7 ? 'long' : '');
        html = html.replace(/\{\{LEAGUE_NAME\}\}/g, escHtml(match.league_name || ''));
        html = html.replace(/\{\{MINUTE\}\}/g, escHtml(meta.minute != null ? meta.minute + "'" : ''));
        html = html.replace(/\{\{HOME_TEAM\}\}/g, escHtml(match.home_team || ''));
        html = html.replace(/\{\{AWAY_TEAM\}\}/g, escHtml(match.away_team || ''));
        html = html.replace(/\{\{HOME_SCORE\}\}/g, escHtml(String(match.home_score != null ? match.home_score : 0)));
        html = html.replace(/\{\{AWAY_SCORE\}\}/g, escHtml(String(match.away_score != null ? match.away_score : 0)));
        html = html.replace(/\{\{LIVE_LABEL\}\}/g, escHtml('Live ' + (match.sport || 'Sport') + ' Alert'));
        html = html.replace(/\{\{HOME_BADGE_CONTENT\}\}/g, badgeContent(match.home_badge, homeInitials));
        html = html.replace(/\{\{AWAY_BADGE_CONTENT\}\}/g, badgeContent(match.away_badge, awayInitials));
        html = html.replace(/\{\{DETAIL_LINES\}\}/g, detailHtml);

        // Add player thumb to info card if present
        if (playerThumbHtml) {
            html = html.replace('</div>\n        </div>\n    </div>\n</div>', playerThumbHtml + '\n        </div>\n    </div>\n</div>');
        }
    }

    // Ensure output directory exists
    const outputDir = path.dirname(outputPath);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }
    if (renderUserDataDir && !fs.existsSync(renderUserDataDir)) {
        fs.mkdirSync(renderUserDataDir, { recursive: true });
    }

    // Render with Puppeteer
    const launchArgs = [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-crash-reporter',
        '--disable-breakpad',
        '--no-first-run',
        '--no-default-browser-check',
        `--user-data-dir=${renderUserDataDir}`,
        `--crash-dumps-dir=${path.join(renderUserDataDir, 'crashpad')}`,
        ...renderExtraArgs,
    ];
    const launchOptions = {
        headless: 'new',
        args: launchArgs,
        userDataDir: renderUserDataDir,
    };
    if (renderChromePath) {
        launchOptions.executablePath = renderChromePath;
    }
    process.env.PUPPETEER_CACHE_DIR = path.dirname(renderUserDataDir);
    process.env.XDG_CONFIG_HOME = renderUserDataDir;
    process.env.XDG_CACHE_HOME = renderUserDataDir;

    const browser = await puppeteer.launch(launchOptions);

    try {
        const page = await browser.newPage();
        const outputHeight = isMatchdayCard ? 430 : 720;
        await page.setViewport({ width: 1280, height: outputHeight, deviceScaleFactor: 1 });
        await page.setContent(html, { waitUntil: 'networkidle0', timeout: 15000 });
        await page.screenshot({ path: outputPath, type: 'png', clip: { x: 0, y: 0, width: 1280, height: outputHeight } });
        console.log('OK:' + outputPath);
    } finally {
        await browser.close();
    }
}

main().catch(e => {
    console.error('Render error:', e.message);
    process.exit(1);
});
