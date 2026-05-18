#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

let puppeteer;
try {
  puppeteer = require('puppeteer');
} catch (error) {
  try {
    puppeteer = require('puppeteer-core');
  } catch {
    console.error(`Puppeteer is not installed: ${error.message}`);
    process.exit(2);
  }
}

const inputPath = process.argv[2];
if (!inputPath) {
  console.error('Usage: node v3-card-renderer.cjs input.json');
  process.exit(1);
}

function readInput(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function readOptionalFile(file) {
  if (!file || !fs.existsSync(file)) return '';
  return fs.readFileSync(file, 'utf8');
}

function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function clean(value, fallback = '') {
  return String(value ?? fallback).replace(/\s+/g, ' ').trim();
}

function initials(value, fallback = 'TV') {
  const words = clean(value).replace(/[^A-Za-z0-9 ]+/g, ' ').split(/\s+/).filter(Boolean);
  const text = words.length > 1 ? words.map((word) => word[0]).join('') : clean(value).slice(0, 3);
  return (text || fallback).slice(0, 3).toUpperCase();
}

function titleCase(value) {
  return clean(value).toLowerCase().replace(/\b[a-z]/g, (letter) => letter.toUpperCase());
}

function teamLines(value) {
  const team = clean(value).toUpperCase();
  const suffixes = [' UNITED', ' CITY', ' WANDERERS', ' ROVERS', ' ATHLETIC', ' LAKERS', ' CELTICS'];
  for (const suffix of suffixes) {
    if (team.endsWith(suffix) && team.length - suffix.length > 4) {
      return [team.slice(0, -suffix.length).trim(), suffix.trim()];
    }
  }
  const parts = team.split(/\s+/);
  if (parts.length >= 3) {
    return [parts.slice(0, -1).join(' '), parts.at(-1)];
  }
  return [team, ''];
}

function shortLeague(value) {
  const league = clean(value, 'Sports');
  const map = {
    'English Premier League': 'Premier League',
    'Scottish Premiership': 'Scottish Premiership',
    'UEFA Champions League': 'UEFA Champions League',
    'Formula 1': 'Formula 1',
  };
  return map[league] || league;
}

function sportKey(value) {
  const key = clean(value).toLowerCase().replace(/[_-]/g, ' ');
  if (['soccer', 'football'].includes(key)) return 'football';
  if (key.includes('rugby')) return 'rugby';
  if (['fighting', 'fights', 'mma', 'ufc', 'boxing'].includes(key)) return 'fights';
  if (['formula 1', 'f1', 'motorsport', 'racing'].includes(key)) return 'motorsport';
  if (key.includes('basketball')) return 'basketball';
  if (key.includes('baseball')) return 'baseball';
  if (key.includes('american football')) return 'american_football';
  if (key.includes('ice hockey') || key === 'hockey') return 'ice_hockey';
  if (key.includes('tennis')) return 'tennis';
  if (key.includes('cricket')) return 'cricket';
  if (key.includes('golf')) return 'golf';
  if (key.includes('other sports')) return 'other_sports';
  return key.replace(/\s+/g, '_') || 'sports';
}

function accentFor(key) {
  return {
    football: '#b444ff',
    rugby: '#20d77a',
    cricket: '#20d77a',
    tennis: '#24c46d',
    fights: '#ff3030',
    motorsport: '#ff1f1f',
    basketball: '#ff9f0a',
    baseball: '#38bdf8',
    american_football: '#fb923c',
    ice_hockey: '#60a5fa',
    golf: '#22c55e',
    other_sports: '#a855f7',
  }[key] || '#14b8a6';
}

function className(value) {
  return clean(value).toLowerCase().replace(/[^a-z0-9_-]+/g, '-');
}

function logoHtml(url, label, className) {
  const src = clean(url);
  if (src) {
    return `<img class="${className}" src="${esc(src)}" alt="">`;
  }
  return `<div class="${className} fallback">${esc(initials(label))}</div>`;
}

function enhanceHtml(html, input) {
  const themeCss = readOptionalFile(input.themePath);
  const templateClass = input.template ? ` template-${className(input.template)}` : '';
  const themeClass = input.theme ? ` theme-${className(input.theme)}` : '';
  const branded = input.branding?.watermark ? `<div class="watermark">${esc(input.branding.watermark)}</div>` : '';
  const css = `
  :root { --accent: ${esc(input.accent || '#14b8a6')}; }
  .watermark { position: absolute; z-index: 4; right: 56px; bottom: 14px; color: rgba(255,255,255,.44); font-size: 12px; font-weight: 850; text-transform: uppercase; }
  ${themeCss}
  `;

  return html
    .replace('<style>', `<style>\n${css}\n`)
    .replace('<body>', `<body class="sports-card${templateClass}${themeClass}" data-template="${esc(input.template || '')}" data-theme="${esc(input.theme || '')}">`)
    .replace('</div>\n</body>', `${branded}</div>\n</body>`);
}

function subhead(fixture, key) {
  const round = clean(fixture.round ?? fixture.strRound ?? fixture.season);
  if (round) return round;
  if (key === 'motorsport') return 'Race event';
  if (key === 'fights') return 'Fight card';
  if (['basketball', 'baseball', 'american_football', 'ice_hockey'].includes(key)) return 'USA sports';
  return 'Fixture';
}

function timePrefix(key) {
  if (key === 'motorsport') return 'Race start';
  if (key === 'fights') return 'Main card';
  if (key === 'basketball') return 'Tip-off';
  return 'Kick-off';
}

function venueLabel(key) {
  if (key === 'motorsport') return 'Circuit';
  if (['basketball', 'ice_hockey'].includes(key)) return 'Arena';
  return 'Venue';
}

function infoLabel(key) {
  if (key === 'motorsport') return 'Race';
  if (key === 'fights') return 'Event';
  return 'Fixture';
}

function buildHtml(input) {
  const fixture = input.fixture || {};
  const width = Number(input.width || 1200);
  const height = Number(input.height || 675);
  const key = sportKey(fixture.sport || fixture.strSport || fixture.sport_key);
  const accent = input.accent || accentFor(key);
  const league = shortLeague(fixture.league || fixture.strLeague || 'Sports');
  const date = clean(fixture.date_label || fixture.dateEvent || 'Date TBC').toUpperCase();
  const time = clean(fixture.kickoff_label || fixture.time || fixture.strTime || 'Time TBC').toUpperCase();
  const home = clean(fixture.home_team || fixture.strHomeTeam);
  const away = clean(fixture.away_team || fixture.strAwayTeam);
  const eventName = clean(fixture.event_name || fixture.strEvent);
  const hasMatchup = home && away;
  const title = clean(fixture.manual_text_override) || eventName || (hasMatchup ? `${home} vs ${away}` : 'Fixture TBC');
  const venue = clean(fixture.venue || fixture.strVenue || 'Venue TBC');
  const tv = clean(fixture.tv_channel || 'Not listed');
  const homeLines = teamLines(home || title);
  const awayLines = teamLines(away || league);
  const leagueLogo = clean(fixture.league_logo || fixture.league_badge || fixture.strLeagueLogo || fixture.strLeagueBadge);
  const homeLogo = clean(fixture.home_badge || fixture.strHomeTeamBadge || fixture.home_logo);
  const awayLogo = clean(fixture.away_badge || fixture.strAwayTeamBadge || fixture.away_logo);
  const backgroundImage = clean(fixture.background_image);
  const backgroundLayer = backgroundImage ? `linear-gradient(rgba(2,4,9,.70), rgba(2,4,9,.88)), url("${esc(backgroundImage)}"),` : '';

  const matchupHtml = hasMatchup
    ? `<div class="stage matchup">
        <div class="side side-home">
          ${logoHtml(homeLogo, home, 'team-logo')}
          <div class="team-name">${esc(homeLines[0])}</div>
          ${homeLines[1] ? `<div class="team-sub">${esc(homeLines[1])}</div>` : ''}
        </div>
        <div class="versus">VS</div>
        <div class="side side-away">
          ${logoHtml(awayLogo, away, 'team-logo')}
          <div class="team-name">${esc(awayLines[0])}</div>
          ${awayLines[1] ? `<div class="team-sub">${esc(awayLines[1])}</div>` : ''}
        </div>
      </div>`
    : `<div class="stage event-only">
        ${logoHtml(leagueLogo, league, 'event-logo')}
        <div class="event-title">${esc(titleCase(title).toUpperCase())}</div>
      </div>`;

  return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; width: ${width}px; height: ${height}px; background: #020409; font-family: "Inter", "Arial Narrow", "Arial", sans-serif; overflow: hidden; }
  .card {
    position: relative; width: ${width}px; height: ${height}px; color: #f8fafc; overflow: hidden;
    background:
      ${backgroundLayer}
      radial-gradient(ellipse at center, color-mix(in srgb, ${accent} 24%, transparent) 0%, rgba(0,0,0,0) 42%),
      radial-gradient(ellipse at 50% 58%, color-mix(in srgb, ${accent} 20%, transparent) 0%, rgba(0,0,0,0) 38%),
      linear-gradient(180deg, #05070c 0%, #020409 100%);
  }
  .card::before {
    content: ""; position: absolute; inset: 18px; border: 1.5px solid color-mix(in srgb, ${accent} 72%, transparent);
    border-radius: 22px; box-shadow: inset 0 0 40px rgba(255,255,255,0.025), 0 0 22px color-mix(in srgb, ${accent} 34%, transparent);
  }
  .card::after {
    content: ""; position: absolute; left: 0; right: 0; top: 128px; height: 392px;
    background:
      radial-gradient(ellipse at center, rgba(255,255,255,.08), transparent 22%),
      repeating-linear-gradient(100deg, transparent 0 78px, rgba(255,255,255,.025) 80px 81px),
      linear-gradient(180deg, transparent 0%, rgba(0,0,0,.72) 100%);
    opacity: .95;
  }
  .light-row { position: absolute; left: 40px; right: 40px; top: 138px; display: flex; justify-content: space-between; opacity: .36; z-index: 1; }
  .light-row span { width: 4px; height: 4px; border-radius: 50%; background: #fff; box-shadow: 0 0 10px #fff; }
  .league { position: absolute; z-index: 3; left: 52px; top: 28px; display: grid; grid-template-columns: 112px auto; column-gap: 24px; align-items: center; }
  .league-mark { width: 112px; height: 112px; object-fit: contain; filter: drop-shadow(0 0 14px color-mix(in srgb, ${accent} 45%, transparent)); }
  .league-mark.fallback { display: grid; place-items: center; border-radius: 50%; background: rgba(255,255,255,.08); color: ${accent}; font-weight: 950; font-size: 34px; }
  .league-title { font-size: 30px; line-height: .94; font-weight: 950; text-transform: uppercase; letter-spacing: .2px; max-width: 410px; }
  .league-sub { margin-top: 8px; color: ${accent}; font-size: 18px; font-weight: 850; text-transform: uppercase; }
  .date { position: absolute; z-index: 3; right: 66px; top: 48px; width: 420px; text-align: right; text-transform: uppercase; }
  .date .line1 { font-size: 25px; line-height: 1; font-weight: 950; letter-spacing: .5px; }
  .date .line2 { margin-top: 10px; color: ${accent}; font-size: 25px; line-height: 1; font-weight: 950; }
  .date-icon { position: absolute; z-index: 3; right: 506px; top: 49px; width: 34px; height: 34px; border: 3px solid ${accent}; border-radius: 4px; box-shadow: 0 0 12px color-mix(in srgb, ${accent} 48%, transparent); }
  .date-icon::before { content: ""; position: absolute; left: -3px; right: -3px; top: 8px; border-top: 3px solid ${accent}; }
  .date-icon::after { content: ""; position: absolute; left: 7px; top: -8px; width: 14px; height: 10px; border-left: 3px solid ${accent}; border-right: 3px solid ${accent}; }
  .stage { position: absolute; z-index: 3; left: 58px; right: 58px; top: 148px; height: 342px; }
  .matchup { display: grid; grid-template-columns: 1fr 210px 1fr; align-items: center; }
  .side { min-width: 0; text-align: center; }
  .team-logo { width: 188px; height: 188px; object-fit: contain; margin: 0 auto 22px; filter: drop-shadow(0 0 20px rgba(255,255,255,.26)); }
  .team-logo.fallback { display: grid; place-items: center; border-radius: 50%; background: ${accent}; color: #fff; font-size: 52px; font-weight: 950; box-shadow: 0 0 54px color-mix(in srgb, ${accent} 45%, transparent); }
  .team-name { font-size: 40px; line-height: .96; font-weight: 950; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 3px 14px #000; }
  .team-sub { margin-top: 6px; color: #cbd5e1; font-size: 25px; font-weight: 900; text-transform: uppercase; }
  .versus { color: #fff; text-align: center; font-size: 72px; font-style: italic; font-weight: 950; text-shadow: 0 0 22px ${accent}, 0 0 42px ${accent}; }
  .event-only { display: grid; align-content: center; justify-items: center; gap: 24px; }
  .event-logo { width: 132px; height: 132px; object-fit: contain; filter: drop-shadow(0 0 22px color-mix(in srgb, ${accent} 40%, transparent)); }
  .event-logo.fallback { display: grid; place-items: center; border-radius: 50%; background: ${accent}; font-size: 42px; font-weight: 950; }
  .event-title { max-width: 820px; text-align: center; font-size: 44px; line-height: 1.04; font-weight: 950; text-shadow: 0 3px 18px #000; }
  .footer { position: absolute; z-index: 3; left: 36px; right: 36px; bottom: 36px; height: 122px; border: 1px solid color-mix(in srgb, ${accent} 56%, transparent); border-radius: 12px; background: rgba(7,11,20,.82); display: grid; grid-template-columns: 1fr 1.05fr 1.05fr; }
  .cell { position: relative; padding: 28px 26px 18px 86px; min-width: 0; }
  .cell + .cell { border-left: 1px solid rgba(255,255,255,.16); }
  .cell-icon { position: absolute; left: 28px; top: 38px; color: ${accent}; font-size: 34px; }
  .label { color: #9ca3af; font-size: 13px; line-height: 1; font-weight: 850; text-transform: uppercase; letter-spacing: .4px; }
  .value { margin-top: 11px; color: #fff; font-size: 23px; line-height: 1.05; font-weight: 950; text-transform: uppercase; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
</head>
<body>
<div class="card">
  <div class="light-row">${Array.from({ length: 36 }).map(() => '<span></span>').join('')}</div>
  <div class="league">${logoHtml(leagueLogo, league, 'league-mark')}<div><div class="league-title">${esc(shortLeague(league).toUpperCase())}</div><div class="league-sub">${esc(subhead(fixture, key).toUpperCase())}</div></div></div>
  <div class="date-icon"></div>
  <div class="date"><div class="line1">${esc(date)}</div><div class="line2">${esc(timePrefix(key).toUpperCase())} ${esc(time)}</div></div>
  ${matchupHtml}
  <div class="footer">
    <div class="cell"><div class="cell-icon">▱</div><div class="label">TV Broadcast</div><div class="value">${esc(tv)}</div></div>
    <div class="cell"><div class="cell-icon">◉</div><div class="label">${esc(venueLabel(key))}</div><div class="value">${esc(titleCase(venue))}</div></div>
    <div class="cell"><div class="cell-icon">⚑</div><div class="label">${esc(infoLabel(key))}</div><div class="value">${esc(titleCase(title))}</div></div>
  </div>
</div>
</body>
</html>`;
}

function buildNoFixturesHtml(input) {
  const summary = input.summary || input.fixture || {};
  const width = Number(input.width || 1200);
  const height = Number(input.height || 675);
  const key = sportKey(summary.sport || summary.sport_key || summary.route_key || summary.title || 'sports');
  const accent = input.accent || accentFor(key);
  const title = clean(summary.title || summary.label || `${titleCase(summary.sport || 'Sports')} Fixtures TV`, 'Sports Fixtures TV');
  const date = clean(summary.date || summary.date_label || new Date().toISOString().slice(0, 10)).toUpperCase();
  const route = clean(summary.route_key || summary.topic_key || 'SPORTS');
  const label = clean(summary.sport_label || summary.sport || title);

  return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; width: ${width}px; height: ${height}px; background: #020409; font-family: "Inter", "Arial Narrow", "Arial", sans-serif; overflow: hidden; }
  .card {
    position: relative; width: ${width}px; height: ${height}px; color: #f8fafc; overflow: hidden;
    background:
      radial-gradient(ellipse at 50% 46%, color-mix(in srgb, ${accent} 28%, transparent) 0%, rgba(0,0,0,0) 38%),
      radial-gradient(ellipse at 82% 18%, color-mix(in srgb, ${accent} 16%, transparent) 0%, rgba(0,0,0,0) 30%),
      linear-gradient(180deg, #05070c 0%, #020409 100%);
  }
  .card::before {
    content: ""; position: absolute; inset: 18px; border: 1.5px solid color-mix(in srgb, ${accent} 72%, transparent);
    border-radius: 22px; box-shadow: inset 0 0 40px rgba(255,255,255,0.025), 0 0 22px color-mix(in srgb, ${accent} 34%, transparent);
  }
  .card::after {
    content: ""; position: absolute; left: 0; right: 0; top: 130px; height: 390px;
    background:
      radial-gradient(ellipse at center, rgba(255,255,255,.08), transparent 22%),
      repeating-linear-gradient(100deg, transparent 0 78px, rgba(255,255,255,.025) 80px 81px),
      linear-gradient(180deg, transparent 0%, rgba(0,0,0,.74) 100%);
    opacity: .95;
  }
  .light-row { position: absolute; left: 40px; right: 40px; top: 138px; display: flex; justify-content: space-between; opacity: .36; z-index: 1; }
  .light-row span { width: 4px; height: 4px; border-radius: 50%; background: #fff; box-shadow: 0 0 10px #fff; }
  .league { position: absolute; z-index: 3; left: 52px; top: 38px; display: grid; grid-template-columns: 96px auto; column-gap: 22px; align-items: center; }
  .league-mark { width: 96px; height: 96px; display: grid; place-items: center; border-radius: 50%; background: rgba(255,255,255,.08); color: ${accent}; font-weight: 950; font-size: 30px; box-shadow: 0 0 28px color-mix(in srgb, ${accent} 35%, transparent); }
  .league-title { font-size: 31px; line-height: .98; font-weight: 950; text-transform: uppercase; letter-spacing: .2px; max-width: 520px; }
  .league-sub { margin-top: 9px; color: ${accent}; font-size: 18px; font-weight: 850; text-transform: uppercase; }
  .date { position: absolute; z-index: 3; right: 66px; top: 58px; width: 420px; text-align: right; text-transform: uppercase; }
  .date .line1 { color: #fff; font-size: 25px; line-height: 1; font-weight: 950; letter-spacing: .5px; }
  .date .line2 { margin-top: 10px; color: ${accent}; font-size: 25px; line-height: 1; font-weight: 950; }
  .date-icon { position: absolute; z-index: 3; right: 506px; top: 56px; width: 34px; height: 34px; border: 3px solid ${accent}; border-radius: 4px; box-shadow: 0 0 12px color-mix(in srgb, ${accent} 48%, transparent); }
  .date-icon::before { content: ""; position: absolute; left: -3px; right: -3px; top: 8px; border-top: 3px solid ${accent}; }
  .date-icon::after { content: ""; position: absolute; left: 7px; top: -8px; width: 14px; height: 10px; border-left: 3px solid ${accent}; border-right: 3px solid ${accent}; }
  .stage { position: absolute; z-index: 3; left: 70px; right: 70px; top: 178px; height: 300px; display: grid; place-items: center; text-align: center; }
  .empty-badge { width: 126px; height: 126px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 28px; color: ${accent}; font-size: 58px; font-weight: 950; border: 2px solid color-mix(in srgb, ${accent} 70%, transparent); background: rgba(255,255,255,.055); box-shadow: 0 0 42px color-mix(in srgb, ${accent} 38%, transparent); }
  .empty-title { font-size: 58px; line-height: 1; font-weight: 950; text-transform: uppercase; text-shadow: 0 0 28px color-mix(in srgb, ${accent} 52%, transparent); }
  .empty-copy { margin-top: 20px; color: #cbd5e1; font-size: 25px; line-height: 1.2; font-weight: 750; }
  .footer { position: absolute; z-index: 3; left: 36px; right: 36px; bottom: 36px; height: 122px; border: 1px solid color-mix(in srgb, ${accent} 56%, transparent); border-radius: 12px; background: rgba(7,11,20,.82); display: grid; grid-template-columns: 1fr 1fr 1fr; }
  .cell { position: relative; padding: 28px 26px 18px 86px; min-width: 0; }
  .cell + .cell { border-left: 1px solid rgba(255,255,255,.16); }
  .cell-icon { position: absolute; left: 28px; top: 38px; color: ${accent}; font-size: 34px; }
  .label { color: #9ca3af; font-size: 13px; line-height: 1; font-weight: 850; text-transform: uppercase; letter-spacing: .4px; }
  .value { margin-top: 11px; color: #fff; font-size: 23px; line-height: 1.05; font-weight: 950; text-transform: uppercase; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
</head>
<body>
<div class="card">
  <div class="light-row">${Array.from({ length: 36 }).map(() => '<span></span>').join('')}</div>
  <div class="league"><div class="league-mark">${esc(initials(label, 'SP'))}</div><div><div class="league-title">${esc(title.toUpperCase())}</div><div class="league-sub">Fixture update</div></div></div>
  <div class="date-icon"></div>
  <div class="date"><div class="line1">${esc(date)}</div><div class="line2">No scheduled games</div></div>
  <div class="stage">
    <div>
      <div class="empty-badge">0</div>
      <div class="empty-title">No Fixtures Today</div>
      <div class="empty-copy">Nothing scheduled for this topic today.</div>
    </div>
  </div>
  <div class="footer">
    <div class="cell"><div class="cell-icon">▱</div><div class="label">Topic</div><div class="value">${esc(route)}</div></div>
    <div class="cell"><div class="cell-icon">◉</div><div class="label">Date</div><div class="value">${esc(date)}</div></div>
    <div class="cell"><div class="cell-icon">⚑</div><div class="label">Status</div><div class="value">No fixtures</div></div>
  </div>
</div>
</body>
</html>`;
}

function discoverChromePath() {
  const candidates = [
    process.env.PUPPETEER_EXECUTABLE_PATH,
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
  ].filter(Boolean);

  const cacheRoot = process.env.PUPPETEER_CACHE_DIR || path.join(process.cwd(), '.cache', 'puppeteer');
  const chromeDir = path.join(cacheRoot, 'chrome');
  if (fs.existsSync(chromeDir)) {
    for (const version of fs.readdirSync(chromeDir).sort().reverse()) {
      candidates.push(path.join(chromeDir, version, 'chrome-linux64', 'chrome'));
      candidates.push(path.join(chromeDir, version, 'chrome-mac-arm64', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'));
      candidates.push(path.join(chromeDir, version, 'chrome-mac-x64', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'));
    }
  }

  return candidates.find((candidate) => candidate && fs.existsSync(candidate)) || undefined;
}

async function main() {
  const input = readInput(inputPath);
  const outputPath = input.outputPath;
  if (!outputPath) {
    console.error('Missing outputPath');
    process.exit(1);
  }

  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  if (input.debugDir) fs.mkdirSync(input.debugDir, { recursive: true });

  const executablePath = input.chromePath || discoverChromePath();
  const browserArgs = Array.isArray(input.browserArgs) && input.browserArgs.length
    ? input.browserArgs
    : ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'];
  const attempts = Math.max(1, Number(input.retries || 0) + 1);
  const consoleEvents = [];
  const failedRequests = [];
  let lastError;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    let browser;
    let page;

    try {
      browser = await puppeteer.launch({
        executablePath,
        headless: 'new',
        args: browserArgs,
      });

      page = await browser.newPage();
      page.on('console', (message) => {
        consoleEvents.push({ type: message.type(), text: message.text(), attempt });
      });
      page.on('pageerror', (error) => {
        consoleEvents.push({ type: 'pageerror', text: error.message, attempt });
      });
      page.on('requestfailed', (request) => {
        failedRequests.push({
          url: request.url(),
          resourceType: request.resourceType(),
          failure: request.failure()?.errorText || 'request_failed',
          attempt,
        });
      });
      page.on('response', (response) => {
        if (response.status() >= 400 && ['image', 'media', 'font', 'stylesheet'].includes(response.request().resourceType())) {
          failedRequests.push({
            url: response.url(),
            resourceType: response.request().resourceType(),
            failure: `http_${response.status()}`,
            attempt,
          });
        }
      });

      await page.setViewport({ width: Number(input.width || 1200), height: Number(input.height || 675), deviceScaleFactor: 1 });
      const html = enhanceHtml(input.kind === 'no-fixtures' ? buildNoFixturesHtml(input) : buildHtml(input), input);
      if (input.htmlSnapshotPath) {
        fs.writeFileSync(input.htmlSnapshotPath, html);
      }

      await page.setContent(html, { waitUntil: 'networkidle0', timeout: Number(input.timeout || 12000) });
      await page.screenshot({
        path: outputPath,
        type: input.format === 'jpeg' ? 'jpeg' : (input.format === 'webp' ? 'webp' : 'png'),
        omitBackground: false,
      });

      if (input.consoleLogPath) {
        fs.writeFileSync(input.consoleLogPath, JSON.stringify({ consoleEvents, failedRequests, attempt, executablePath, browserArgs }, null, 2));
      }

      console.log(JSON.stringify({
        ok: true,
        renderer: 'browser_v3',
        attempt,
        template: input.template || null,
        theme: input.theme || null,
        failedRequests,
        consoleEvents,
        executablePath,
      }));

      return;
    } catch (error) {
      lastError = error;
      consoleEvents.push({ type: 'renderer-error', text: error && error.stack ? error.stack : String(error), attempt });

      if (page && input.failedScreenshotPath) {
        try {
          await page.screenshot({ path: input.failedScreenshotPath, type: 'png', omitBackground: false });
        } catch {
          // Ignore screenshot failures; the original render error is more useful.
        }
      }
    } finally {
      if (browser) {
        await browser.close();
      }

      if (input.consoleLogPath) {
        fs.writeFileSync(input.consoleLogPath, JSON.stringify({ consoleEvents, failedRequests, executablePath, browserArgs }, null, 2));
      }
    }
  }

  throw lastError || new Error('Render failed');
}

main().catch((error) => {
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(1);
});
