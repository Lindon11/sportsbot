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
    'English Rugby League Super League': 'Super League',
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
  return html;
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

function buildFightHtml(input) {
  const fixture = input.fixture || {};
  const league = shortLeague(fixture.league || fixture.strLeague || 'Fights');
  const date = clean(fixture.date_label || fixture.dateEvent || 'Date TBC').toUpperCase();
  const time = clean(fixture.kickoff_label || fixture.time || fixture.strTime || 'Time TBC').toUpperCase();
  const eventName = clean(fixture.event_name || fixture.strEvent || fixture.manual_text_override || 'Fight Night');
  const home = clean(fixture.home_team || fixture.strHomeTeam);
  const away = clean(fixture.away_team || fixture.strAwayTeam);
  const poster = clean(fixture.event_thumb || fixture.event_poster || fixture.strThumb || fixture.strPoster || fixture.league_badge || fixture.league_logo || fixture.strLeagueBadge || fixture.strLeagueLogo);
  const tv = clean(fixture.tv_channel || fixture.strChannel || 'Not listed').toUpperCase();
  const venue = clean(fixture.venue || fixture.strVenue || league || 'Venue TBC').toUpperCase();
  const brand = input.branding?.watermark || 'THE SPORTS HUB';
  const thumbUrl = esc(poster);

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #0a0c12; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; overflow: hidden; border-radius: 34px; border: 2px solid rgba(60, 160, 255, .85); background: radial-gradient(circle at center, rgba(30, 120, 220, .28), transparent 40%), linear-gradient(135deg, #080c12 0%, #0e1624 48%, #060810 100%); box-shadow: inset 0 0 42px rgba(30, 120, 220, .3), 0 0 20px rgba(30, 120, 220, .4); }
.thumb { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.vignette { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,.85) 0%, rgba(0,0,0,.25) 40%, rgba(0,0,0,.1) 70%, rgba(0,0,0,.4) 100%); pointer-events: none; }
.main { position: absolute; z-index: 4; left: 0; right: 0; bottom: 200px; text-align: center; padding: 0 60px; }
.event-title { font-size: 64px; font-weight: 1000; line-height: .92; text-transform: uppercase; text-shadow: 0 4px 30px rgba(0,0,0,.95); letter-spacing: 1px; }
.info-bar { position: absolute; z-index: 6; left: 52px; right: 52px; bottom: 70px; height: 110px; display: grid; grid-template-columns: 1fr 1fr 1fr; border: 1.5px solid rgba(60, 160, 255, .8); border-radius: 16px; background: rgba(0,0,0,.7); backdrop-filter: blur(8px); overflow: hidden; }
.info-box { padding: 24px 28px; border-right: 1px solid rgba(255,255,255,.15); text-align: center; }
.info-box:last-child { border-right: none; }
.info-label { color: #b4aecb; font-size: 13px; font-weight: 1000; letter-spacing: 1.5px; text-transform: uppercase; }
.info-value { margin-top: 6px; font-size: 22px; line-height: 1.1; font-weight: 1000; text-transform: uppercase; }
footer { position: absolute; z-index: 7; bottom: 24px; left: 0; width: 100%; text-align: center; color: transparent; font-size: 18px; font-weight: 900; letter-spacing: 16px; text-transform: uppercase; background: linear-gradient(135deg, #e8e8e8 0%, #ffffff 25%, #c0c0c0 50%, #ffffff 75%, #e0e0e0 100%); -webkit-background-clip: text; background-clip: text; }
footer::before, footer::after { content: ""; display: inline-block; width: 160px; height: 2px; margin: 0 30px 4px; background: linear-gradient(90deg, transparent, #5bb8ff); opacity: .75; }
footer::after { background: linear-gradient(90deg, #5bb8ff, transparent); }
</style>
</head>
<body>
<div class="card">
  ${thumbUrl ? `<img class="thumb" src="${thumbUrl}" />` : ''}
  <div class="vignette"></div>
  <div class="main">
    <div class="event-title">${esc(eventName.toUpperCase())}</div>
  </div>
  <div class="info-bar">
    <div class="info-box"><div class="info-label">TV Broadcast</div><div class="info-value">${esc(tv)}</div></div>
    <div class="info-box"><div class="info-label">Date / Time</div><div class="info-value">${esc(date)}</div><div class="info-value" style="color:#5bb8ff;font-size:18px;margin-top:2px">${esc(time)}</div></div>
    <div class="info-box"><div class="info-label">Circuit</div><div class="info-value">${esc(venue)}</div></div>
  </div>
  <footer>${esc(brand)}</footer>
</div>
</body>
</html>`;
}

function buildMotorsportHtml(input) {
  const fixture = input.fixture || {};
  const league = shortLeague(fixture.league || fixture.strLeague || 'Motorsport');
  const date = clean(fixture.date_label || fixture.dateEvent || 'Date TBC').toUpperCase();
  const time = clean(fixture.kickoff_label || fixture.time || fixture.strTime || 'Time TBC').toUpperCase();
  const eventName = clean(fixture.event_name || fixture.strEvent || fixture.manual_text_override || 'Race Event');
  const venue = clean(fixture.venue || fixture.strVenue || 'Circuit TBC');
  const tv = clean(fixture.tv_channel || fixture.strChannel || 'Not listed');
  const brand = input.branding?.watermark || 'THE SPORTS HUB';
  const leagueLogo = clean(fixture.league_logo || fixture.league_badge || fixture.strLeagueLogo || fixture.strLeagueBadge);
  const background = clean(fixture.event_thumb || fixture.event_poster || fixture.strThumb || fixture.strPoster || fixture.background_image || leagueLogo);
  const leagueLogoHtml = leagueLogo
    ? `<img class="series-logo" src="${esc(leagueLogo)}" alt="">`
    : `<div class="series-logo fallback">F1</div>`;
  const backgroundHtml = background ? `<img class="bg-image" src="${esc(background)}" alt="">` : '';

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #05060a; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; overflow: hidden; border-radius: 34px; border: 2px solid rgba(255,31,31,.92); background: radial-gradient(circle at 50% 48%, rgba(180,20,20,.42), transparent 36%), linear-gradient(135deg, #05070c 0%, #0b0d14 48%, #050204 100%); box-shadow: inset 0 0 48px rgba(255,31,31,.28), 0 0 22px rgba(255,31,31,.38); }
.bg-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .22; filter: saturate(.55) contrast(1.15); }
.vignette { position: absolute; inset: 0; background: radial-gradient(circle at center, transparent 0 38%, rgba(0,0,0,.55) 72%), linear-gradient(to bottom, rgba(0,0,0,.25), rgba(0,0,0,.72)); }
.speed-lines { position: absolute; inset: 0; background: repeating-linear-gradient(112deg, transparent 0 88px, rgba(255,31,31,.16) 89px 92px), linear-gradient(90deg, rgba(255,31,31,.16), transparent 28%, transparent 72%, rgba(255,31,31,.16)); opacity: .85; }
.header { position: relative; z-index: 5; display: flex; align-items: center; justify-content: space-between; padding: 52px 64px 0; }
.series { display: flex; align-items: center; gap: 24px; }
.series-logo { width: 104px; height: 104px; object-fit: contain; filter: drop-shadow(0 0 18px rgba(255,31,31,.35)); }
.series-logo.fallback { display: grid; place-items: center; border-radius: 50%; background: rgba(255,31,31,.18); color: #ff3131; font-size: 38px; font-weight: 1000; }
.series-title { font-size: 44px; font-weight: 1000; letter-spacing: 2px; text-transform: uppercase; }
.series-subtitle { margin-top: 6px; color: #ff3131; font-size: 20px; font-weight: 1000; letter-spacing: 1px; text-transform: uppercase; }
.date-box { text-align: right; color: #f8fafc; font-size: 28px; font-weight: 1000; letter-spacing: 1px; text-transform: uppercase; }
.time-box { margin-top: 8px; color: #ff3131; font-size: 24px; }
.center { position: relative; z-index: 4; height: 494px; display: grid; place-items: center; text-align: center; padding: 0 112px; }
.mark { width: 116px; height: 116px; display: grid; place-items: center; margin: 0 auto 28px; border-radius: 50%; background: rgba(255,31,31,.18); color: #ff3131; font-size: 40px; font-weight: 1000; box-shadow: 0 0 42px rgba(255,31,31,.22); }
.event-title { max-width: 1060px; font-size: 62px; line-height: .92; font-weight: 1000; text-transform: uppercase; text-shadow: 0 8px 30px rgba(0,0,0,.92); }
.info-bar { position: absolute; z-index: 6; left: 52px; right: 52px; bottom: 70px; height: 116px; display: grid; grid-template-columns: 1fr 1.15fr 1fr; border: 1.5px solid rgba(255,31,31,.8); border-radius: 16px; background: rgba(4,7,18,.82); backdrop-filter: blur(8px); overflow: hidden; }
.info-box { padding: 24px 28px; border-right: 1px solid rgba(255,255,255,.15); }
.info-box:last-child { border-right: none; }
.info-label { color: #9ca3af; font-size: 14px; font-weight: 1000; letter-spacing: 1.4px; text-transform: uppercase; }
.info-value { margin-top: 7px; color: #fff; font-size: 24px; line-height: 1.06; font-weight: 1000; text-transform: uppercase; }
footer { position: absolute; z-index: 7; bottom: 24px; left: 0; width: 100%; text-align: center; color: transparent; font-size: 18px; font-weight: 900; letter-spacing: 16px; text-transform: uppercase; background: linear-gradient(135deg, #e8e8e8 0%, #ffffff 25%, #c0c0c0 50%, #ffffff 75%, #e0e0e0 100%); -webkit-background-clip: text; background-clip: text; }
footer::before, footer::after { content: ""; display: inline-block; width: 160px; height: 2px; margin: 0 30px 4px; background: linear-gradient(90deg, transparent, #ff3131); opacity: .75; }
footer::after { background: linear-gradient(90deg, #ff3131, transparent); }
</style>
</head>
<body>
<div class="card">
  ${backgroundHtml}
  <div class="vignette"></div>
  <div class="speed-lines"></div>
  <div class="header">
    <div class="series">
      ${leagueLogoHtml}
      <div>
        <div class="series-title">${esc(league.toUpperCase())}</div>
        <div class="series-subtitle">Race Event</div>
      </div>
    </div>
    <div class="date-box">
      <div>${esc(date)}</div>
      <div class="time-box">${esc(time)}</div>
    </div>
  </div>
  <div class="center">
    <div>
      <div class="mark">F1</div>
      <div class="event-title">${esc(eventName.toUpperCase())}</div>
    </div>
  </div>
  <div class="info-bar">
    <div class="info-box"><div class="info-label">TV Broadcast</div><div class="info-value">${esc(tv)}</div></div>
    <div class="info-box"><div class="info-label">Circuit</div><div class="info-value">${esc(venue)}</div></div>
    <div class="info-box"><div class="info-label">Race</div><div class="info-value">${esc(eventName)}</div></div>
  </div>
  <footer>${esc(brand)}</footer>
</div>
</body>
</html>`;
}

function resultStatus(fixture) {
  const raw = clean(fixture.result_status || fixture.status || fixture.strStatus || fixture.strProgress || '');
  if (!raw) return 'FULL TIME';
  const normalized = raw.toLowerCase();
  if (['ft', 'full time', 'finished', 'match finished', 'event finished'].includes(normalized)) return 'FULL TIME';
  return raw.toUpperCase();
}

function resultDate(fixture) {
  return clean(fixture.date_label || fixture.dateEvent || fixture.date || fixture.strDate || '').toUpperCase();
}

function scoreValue(...values) {
  for (const value of values) {
    const text = clean(value);
    if (text !== '') return text;
  }
  return '-';
}

function resultScore(fixture) {
  const home = scoreValue(fixture.home_score, fixture.intHomeScore);
  const away = scoreValue(fixture.away_score, fixture.intAwayScore);
  return { home, away, hasScore: home !== '-' && away !== '-' };
}

function resultScoreClass(score) {
  const length = clean(score.home).length + clean(score.away).length;
  if (length >= 6) return 'score score-long';
  if (length >= 5) return 'score score-medium';
  return 'score';
}

function resultTeamLines(value) {
  const team = clean(value).toUpperCase();
  if (!team) return ['TEAM'];
  const suffixes = [' UNITED', ' CITY', ' WANDERERS', ' ROVERS', ' ATHLETIC', ' LAKERS', ' CELTICS', ' CAVALIERS', ' PISTONS'];
  for (const suffix of suffixes) {
    if (team.endsWith(suffix) && team.length - suffix.length > 3) {
      return [team.slice(0, -suffix.length).trim(), suffix.trim()];
    }
  }
  const words = team.split(/\s+/).filter(Boolean);
  if (words.length === 2 && team.length > 13) return words;
  if (words.length >= 3) return [words.slice(0, -1).join(' '), words.at(-1)];
  return [team];
}

function resultTeamFontSize(line, lineCount) {
  const length = clean(line).length;
  const max = lineCount > 1 ? 50 : 56;
  if (length <= 9) return max;
  if (length <= 13) return max - 4;
  if (length <= 17) return max - 10;
  return max - 16;
}

function resultTeamNameHtml(value, classNameValue = 'team-name') {
  const lines = resultTeamLines(value);
  return `<div class="${classNameValue}${lines.length > 1 ? ' two-line' : ''}">${lines.map((line) => `<span style="font-size:${resultTeamFontSize(line, lines.length)}px">${esc(line)}</span>`).join('')}</div>`;
}

function resultFamily(input, key) {
  const template = className(input.template || '');
  const theme = className(input.theme || '');
  if (['fights', 'boxing', 'mma'].includes(key) || template.includes('fight-poster') || theme.includes('fight-night')) return 'fight';
  if (['formula_1', 'motorsport'].includes(key) || theme.includes('motorsport')) return 'motorsport';
  if (['basketball', 'baseball', 'american_football', 'ice_hockey'].includes(key) || template.includes('usa-broadcast') || theme.includes('usa-broadcast')) return 'usa';
  return 'stadium';
}

function resultLogo(url, label, classNameValue) {
  const src = clean(url);
  if (src) return `<img class="${classNameValue}" src="${esc(src)}" alt="">`;
  return `<div class="${classNameValue} fallback">${esc(initials(label, 'TV'))}</div>`;
}

function buildHtml(input) {
  const fixture = input.fixture || {};
  const key = sportKey(fixture.sport || fixture.strSport || fixture.sport_key);
  if (key === 'fights' || key === 'boxing' || key === 'mma') return buildFightHtml(input);
  if (key === 'formula_1' || key === 'motorsport') return buildMotorsportHtml(input);

  const league = shortLeague(fixture.league || fixture.strLeague || 'Sports');
  const date = clean(fixture.date_label || fixture.dateEvent || 'Date TBC').toUpperCase();
  const time = clean(fixture.kickoff_label || fixture.time || fixture.strTime || 'Time TBC').toUpperCase();
  const home = clean(fixture.home_team || fixture.strHomeTeam);
  const away = clean(fixture.away_team || fixture.strAwayTeam);
  const homeLines = teamLines(home || 'Home');
  const awayLines = teamLines(away || 'Away');
  const homeLogo = clean(fixture.home_badge || fixture.strHomeTeamBadge || fixture.home_logo);
  const awayLogo = clean(fixture.away_badge || fixture.strAwayTeamBadge || fixture.away_logo);
  const leagueLogo = clean(fixture.league_logo || fixture.league_badge || fixture.strLeagueLogo || fixture.strLeagueBadge);
  const backgroundImage = clean(fixture.background_image);
  const tv = clean(fixture.tv_channel || 'Not listed');
  const venue = clean(fixture.venue || fixture.strVenue || 'Venue TBC');
  const brand = input.branding?.watermark || 'THE SPORTS HUB';
  const homeForm = fixture.home_form || [];
  const awayForm = fixture.away_form || [];
  const roundRaw = clean(fixture.intRound || fixture.round || fixture.strRound || '');
  const round = roundRaw && roundRaw !== '0' ? roundRaw : '';

  const homeLogoSrc = esc(homeLogo);
  const awayLogoSrc = esc(awayLogo);
  const leagueLogoSrc = esc(leagueLogo);
  const homeLogoHtml = homeLogoSrc ? `<img class="team-logo" src="${homeLogoSrc}" />` : `<div class="team-logo" style="width:200px;height:200px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto;background:#b33cff;color:#fff;font-size:48px;font-weight:1000">${esc(initials(home))}</div>`;
  const awayLogoHtml = awayLogoSrc ? `<img class="team-logo" src="${awayLogoSrc}" />` : `<div class="team-logo" style="width:200px;height:200px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto;background:#b33cff;color:#fff;font-size:48px;font-weight:1000">${esc(initials(away))}</div>`;
  const bgStyle = backgroundImage ? `background-image: linear-gradient(to bottom, rgba(0,0,0,.15), rgba(0,0,0,.78)), url("${esc(backgroundImage)}");` : '';

  function formHtml(form) {
    if (!form || form.length === 0) return '';
    return '<div class="form-row">' + form.map(r => `<span class="form-dot ${r === 'W' ? 'win' : r === 'L' ? 'loss' : 'draw'}">${r}</span>`).join('') + '</div>';
  }

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #02030a; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; padding: 54px 58px; overflow: hidden; border-radius: 34px; border: 2px solid rgba(180, 55, 255, .9); background: radial-gradient(circle at center, rgba(139, 28, 220, .34), transparent 35%), linear-gradient(135deg, #040712 0%, #060819 52%, #02030a 100%); box-shadow: inset 0 0 42px rgba(176, 55, 255, .35), 0 0 20px rgba(176, 55, 255, .45); }
.bg { position: absolute; inset: 0; ${bgStyle} background-size: cover; background-position: center; opacity: .42; filter: contrast(1.2) saturate(.85); }
.vignette { position: absolute; inset: 0; background: radial-gradient(circle at center, transparent 0 42%, rgba(0,0,0,.62) 78%), linear-gradient(to bottom, rgba(0,0,0,.05), rgba(0,0,0,.5)); }
.header { position: relative; z-index: 5; display: flex; align-items: flex-start; gap: 34px; }
.league-logo { width: 160px; height: 76px; object-fit: contain; }
.header-line { width: 2px; height: 86px; background: linear-gradient(to bottom, transparent, #b83cff, transparent); }
.league-title { font-size: 42px; font-weight: 1000; letter-spacing: 2px; text-transform: uppercase; }
.fixture-label { margin-top: 10px; color: #b33cff; font-size: 30px; font-weight: 1000; text-transform: uppercase; }
.match { position: relative; z-index: 4; height: 470px; display: grid; grid-template-columns: 1fr 200px 1fr; align-items: center; padding: 20px 100px 0; }
.team { text-align: center; }
.team-logo { height: 270px; width: 270px; object-fit: contain; filter: drop-shadow(0 0 18px rgba(255,255,255,.28)) drop-shadow(0 0 30px rgba(155,55,255,.28)); }
.team-name { margin-top: 28px; font-size: 60px; line-height: .9; font-style: italic; font-weight: 1000; text-transform: uppercase; letter-spacing: -1px; text-shadow: 0 8px 20px rgba(0,0,0,.9); }
.team-sub { margin-top: 10px; font-size: 28px; color: #b98cff; font-weight: 900; text-transform: uppercase; }
.vs { width: 150px; height: 150px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: auto; font-size: 62px; font-weight: 1000; font-style: italic; background: radial-gradient(circle, #1b062b, #080812); border: 2px solid rgba(188, 65, 255, .95); box-shadow: 0 0 28px rgba(183, 57, 255, .65), inset 0 0 26px rgba(183, 57, 255, .35); color: #fff; }
.form-row { margin-top: 14px; display: flex; justify-content: center; gap: 8px; }
.form-dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 1000; color: #fff; }
.form-dot.win { background: #22c55e; }
.form-dot.loss { background: #ef4444; }
.form-dot.draw { background: #6b7280; }
.info-bar { position: absolute; z-index: 6; left: 58px; right: 58px; bottom: 60px; height: 148px; display: grid; grid-template-columns: 1fr 1fr 1fr; border: 1.5px solid rgba(185, 61, 255, .9); border-radius: 22px; background: rgba(4, 7, 18, .78); backdrop-filter: blur(7px); overflow: hidden; }
.info-box { padding: 26px 34px; border-right: 1px solid rgba(255,255,255,.25); }
.info-box:last-child { border-right: none; }
.info-label { color: #b4aecb; font-size: 16px; font-weight: 1000; letter-spacing: 1.5px; text-transform: uppercase; }
.info-value { margin-top: 8px; font-size: 26px; line-height: 1.1; font-weight: 1000; text-transform: uppercase; }
footer { position: absolute; bottom: 18px; left: 0; width: 100%; text-align: center; color: transparent; font-size: 18px; font-weight: 900; letter-spacing: 16px; text-transform: uppercase; background: linear-gradient(135deg, #e8e8e8 0%, #ffffff 25%, #c0c0c0 50%, #ffffff 75%, #e0e0e0 100%); -webkit-background-clip: text; background-clip: text; }
</style>
</head>
<body>
<div class="card">
  <div class="bg"></div>
  <div class="vignette"></div>
  <div class="header">
    <img class="league-logo" src="${leagueLogoSrc || ''}" onerror="this.style.display='none'" />
    ${leagueLogoSrc ? `<div class="header-line"></div>` : ''}
    <div>
      <div class="league-title">${esc(league.toUpperCase())}</div>
      <div class="fixture-label">${round ? 'Matchweek ' + round : 'Fixture'}</div>
    </div>
  </div>
  <div class="match">
    <div class="team home">
      ${homeLogoHtml}
      <div class="team-name">${esc(homeLines[0])}</div>
      ${homeLines[1] ? `<div class="team-sub">${esc(homeLines[1])}</div>` : ''}
      ${formHtml(homeForm)}
    </div>
    <div class="vs-wrap"><div class="vs">VS</div></div>
    <div class="team away">
      ${awayLogoHtml}
      <div class="team-name">${esc(awayLines[0])}</div>
      ${awayLines[1] ? `<div class="team-sub">${esc(awayLines[1])}</div>` : ''}
      ${formHtml(awayForm)}
    </div>
  </div>
  <div class="info-bar">
    <div class="info-box"><div class="info-label">TV Broadcast</div><div class="info-value">${esc(tv)}</div></div>
    <div class="info-box"><div class="info-label">Kick-Off</div><div class="info-value">${esc(time)}</div></div>
    <div class="info-box"><div class="info-label">Venue</div><div class="info-value">${esc(venue)}</div></div>
  </div>
  <footer>${esc(brand)}</footer>
</div>
</body>
</html>`;
}

function buildStadiumResultHtml(input) {
  const fixture = input.fixture || {};
  const key = sportKey(fixture.sport || fixture.strSport || fixture.sport_key);
  const accent = input.accent || accentFor(key);
  const league = shortLeague(fixture.league || fixture.strLeague || sportLabel(key));
  const date = resultDate(fixture);
  const home = clean(fixture.home_team || fixture.strHomeTeam);
  const away = clean(fixture.away_team || fixture.strAwayTeam);
  const eventName = clean(fixture.event_name || fixture.strEvent || (home && away ? `${home} vs ${away}` : 'Match result'));
  const score = resultScore(fixture);
  const status = resultStatus(fixture);
  const brand = input.branding?.watermark || 'THE SPORTS HUB';
  const backgroundImage = clean(fixture.background_image || fixture.event_thumb || fixture.strThumb);
  const bgStyle = backgroundImage ? `background-image: linear-gradient(rgba(2,4,12,.62), rgba(2,4,12,.88)), url("${esc(backgroundImage)}");` : '';
  const leagueLogo = clean(fixture.league_logo || fixture.league_badge || fixture.strLeagueLogo || fixture.strLeagueBadge);
  const hasMatchup = home !== '' && away !== '';

  const stage = hasMatchup ? `
  <div class="match">
    <div class="team">${resultLogo(fixture.home_badge || fixture.strHomeTeamBadge || fixture.home_logo, home, 'team-logo')}${resultTeamNameHtml(home)}</div>
    <div class="score-wrap">
      <div class="${resultScoreClass(score)}"><span>${esc(score.home)}</span><b>-</b><span>${esc(score.away)}</span></div>
      <div class="score-status">${esc(status)}</div>
    </div>
    <div class="team">${resultLogo(fixture.away_badge || fixture.strAwayTeamBadge || fixture.away_logo, away, 'team-logo')}${resultTeamNameHtml(away)}</div>
  </div>` : `
  <div class="event-result">
    ${resultLogo(leagueLogo, league, 'event-logo')}
    <div class="event-title">${esc(eventName.toUpperCase())}</div>
    <div class="score-status">${esc(status)}</div>
  </div>`;

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #02040b; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; padding: 44px 64px; border-radius: 34px; border: 2px solid ${accent}; background: radial-gradient(circle at center, color-mix(in srgb, ${accent} 32%, transparent), transparent 38%), linear-gradient(135deg, #050710, #02040b); overflow: hidden; box-shadow: inset 0 0 44px color-mix(in srgb, ${accent} 28%, transparent); }
.bg { position: absolute; inset: 0; ${bgStyle} background-size: cover; background-position: center; opacity: .45; filter: contrast(1.18) saturate(.86); }
.vignette { position: absolute; inset: 0; background: radial-gradient(circle at center, transparent 0 36%, rgba(0,0,0,.78) 76%), linear-gradient(to bottom, rgba(0,0,0,.08), rgba(0,0,0,.72)); }
.header { position: relative; z-index: 4; display: flex; align-items: center; gap: 26px; min-height: 102px; }
.league-logo { width: 150px; height: 84px; object-fit: contain; filter: drop-shadow(0 0 18px color-mix(in srgb, ${accent} 40%, transparent)); }
.league-logo.fallback { display: grid; place-items: center; border-radius: 50%; background: rgba(255,255,255,.08); color: ${accent}; font-size: 38px; font-weight: 1000; }
.header-line { width: 2px; height: 90px; background: linear-gradient(to bottom, transparent, ${accent}, transparent); }
.league-title { max-width: 720px; font-size: 44px; line-height: .96; font-weight: 1000; letter-spacing: 2px; text-transform: uppercase; }
.status { margin-top: 8px; color: ${accent}; font-size: 28px; font-weight: 1000; text-transform: uppercase; }
.date { margin-left: auto; text-align: right; color: #dbe4f0; font-size: 23px; font-weight: 1000; text-transform: uppercase; }
.watermark { position: absolute; z-index: 1; top: 104px; left: 50%; transform: translateX(-50%); width: 360px; opacity: .08; }
.match { position: relative; z-index: 3; height: 650px; display: grid; grid-template-columns: minmax(0, 1fr) 430px minmax(0, 1fr); align-items: center; gap: 34px; padding: 8px 58px 18px; }
.team { text-align: center; min-width: 0; }
.team-logo { width: 256px; height: 256px; object-fit: contain; margin: 0 auto; filter: drop-shadow(0 0 18px rgba(255,255,255,.2)) drop-shadow(0 0 35px color-mix(in srgb, ${accent} 32%, transparent)); }
.team-logo.fallback { display: grid; place-items: center; border-radius: 50%; background: ${accent}; color: #fff; font-size: 56px; font-weight: 1000; }
.team-name { margin-top: 26px; line-height: .92; font-weight: 1000; font-style: italic; text-transform: uppercase; text-shadow: 0 8px 20px rgba(0,0,0,.9); }
.team-name span { display: block; white-space: nowrap; }
.team-name.two-line span + span { margin-top: 4px; }
.score-wrap { text-align: center; }
.score { height: 170px; min-width: 380px; padding: 0 28px; display: flex; align-items: center; justify-content: center; gap: 28px; border-radius: 999px; background: radial-gradient(circle, rgba(8, 12, 26, .98), rgba(3, 5, 12, .86)); box-shadow: 0 0 46px color-mix(in srgb, ${accent} 52%, transparent), inset 0 0 36px color-mix(in srgb, ${accent} 24%, transparent); color: white; font-size: 112px; font-weight: 1000; line-height: 1; }
.score.score-medium { font-size: 96px; gap: 24px; }
.score.score-long { font-size: 86px; gap: 20px; }
.score b { color: ${accent}; font-size: .72em; }
.score-status { margin-top: 24px; color: ${accent}; font-size: 30px; font-weight: 1000; text-transform: uppercase; }
.event-result { position: relative; z-index: 4; height: 650px; display: grid; place-items: center; text-align: center; }
.event-logo { width: 180px; height: 180px; object-fit: contain; }
.event-logo.fallback { display: grid; place-items: center; border-radius: 50%; background: ${accent}; font-size: 50px; font-weight: 1000; }
.event-title { margin-top: 22px; max-width: 1000px; font-size: 64px; font-weight: 1000; line-height: .96; text-transform: uppercase; }
.footer { position: absolute; z-index: 5; left: 0; right: 0; bottom: 24px; text-align: center; color: #d9d0e8; font-size: 19px; font-weight: 900; letter-spacing: 20px; text-transform: uppercase; }
</style>
</head>
<body>
<div class="card">
  <div class="bg"></div>
  <div class="vignette"></div>
  ${leagueLogo ? `<img class="watermark" src="${esc(leagueLogo)}" alt="">` : ''}
  <div class="header">
    ${resultLogo(leagueLogo, league, 'league-logo')}
    <div class="header-line"></div>
    <div><div class="league-title">${esc(league.toUpperCase())}</div><div class="status">RESULT</div></div>
    <div class="date">${esc(date || status)}</div>
  </div>
  ${stage}
  <div class="footer">${esc(brand)}</div>
</div>
</body>
</html>`;
}

function buildUsaResultHtml(input) {
  const fixture = input.fixture || {};
  const key = sportKey(fixture.sport || fixture.strSport || fixture.sport_key);
  const accent = input.accent || accentFor(key);
  const league = shortLeague(fixture.league || fixture.strLeague || sportLabel(key));
  const date = resultDate(fixture);
  const home = clean(fixture.home_team || fixture.strHomeTeam || 'Home');
  const away = clean(fixture.away_team || fixture.strAwayTeam || 'Away');
  const score = resultScore(fixture);
  const status = resultStatus(fixture);
  const brand = input.branding?.watermark || 'THE SPORTS HUB';
  const backgroundImage = clean(fixture.background_image || fixture.event_thumb || fixture.strThumb);
  const bgStyle = backgroundImage ? `background-image: linear-gradient(rgba(3,7,18,.54), rgba(3,7,18,.88)), url("${esc(backgroundImage)}");` : '';

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #05070d; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; overflow: hidden; border-radius: 34px; border: 2px solid ${accent}; background: linear-gradient(135deg, #07111f 0%, #06070d 52%, #140b06 100%); box-shadow: inset 0 0 46px color-mix(in srgb, ${accent} 24%, transparent); }
.bg { position: absolute; inset: 0; ${bgStyle} background-size: cover; background-position: center; opacity: .44; filter: contrast(1.2) saturate(.9); }
.grid { position: absolute; inset: 0; background: repeating-linear-gradient(90deg, rgba(255,255,255,.045) 0 2px, transparent 2px 96px), repeating-linear-gradient(0deg, rgba(255,255,255,.035) 0 2px, transparent 2px 96px); mask-image: linear-gradient(to bottom, transparent, black 18%, black 78%, transparent); opacity: .7; }
.top { position: relative; z-index: 3; height: 126px; display: grid; grid-template-columns: 300px 1fr 300px; align-items: center; padding: 0 48px; background: linear-gradient(90deg, rgba(0,0,0,.62), rgba(255,255,255,.06), rgba(0,0,0,.62)); border-bottom: 2px solid ${accent}; }
.bug { color: ${accent}; font-size: 31px; font-weight: 1000; text-transform: uppercase; }
.league { text-align: center; font-size: 43px; line-height: .98; font-weight: 1000; text-transform: uppercase; letter-spacing: 2px; }
.date { text-align: right; color: #dbeafe; font-size: 25px; font-weight: 1000; text-transform: uppercase; }
.teams { position: relative; z-index: 3; height: 690px; display: grid; grid-template-columns: minmax(0, 1fr) 440px minmax(0, 1fr); align-items: center; padding: 22px 64px 48px; gap: 42px; }
.team { min-width: 0; text-align: center; }
.team-logo { width: 272px; height: 272px; margin: 0 auto; object-fit: contain; filter: drop-shadow(0 0 22px rgba(255,255,255,.22)); }
.team-logo.fallback { display: grid; place-items: center; border-radius: 28px; background: linear-gradient(135deg, ${accent}, rgba(255,255,255,.12)); color: #fff; font-size: 56px; font-weight: 1000; }
.team-name { margin-top: 26px; line-height: .94; font-weight: 1000; text-transform: uppercase; text-shadow: 0 8px 24px #000; }
.team-name span { display: block; white-space: nowrap; }
.team-name.two-line span + span { margin-top: 5px; }
.scoreboard { height: 226px; display: grid; place-items: center; border-radius: 24px; border: 2px solid rgba(255,255,255,.18); background: linear-gradient(180deg, rgba(0,0,0,.9), rgba(10,15,28,.74)); box-shadow: 0 0 54px color-mix(in srgb, ${accent} 48%, transparent); }
.score { display: flex; align-items: center; justify-content: center; gap: 28px; color: #fff; font-size: 110px; font-weight: 1000; line-height: 1; }
.score.score-medium { font-size: 94px; gap: 24px; }
.score.score-long { font-size: 84px; gap: 20px; }
.score b { color: ${accent}; font-size: .72em; }
.score-status { margin-top: 16px; color: ${accent}; font-size: 27px; font-weight: 1000; text-transform: uppercase; letter-spacing: 2px; }
.brand { position: absolute; z-index: 5; bottom: 24px; left: 0; right: 0; text-align: center; color: #d7dce8; font-size: 18px; font-weight: 900; letter-spacing: 18px; text-transform: uppercase; }
</style>
</head>
<body>
<div class="card">
  <div class="bg"></div>
  <div class="grid"></div>
  <div class="top"><div class="bug">${esc(sportLabel(key).toUpperCase())}</div><div class="league">${esc(league.toUpperCase())}</div><div class="date">${esc(date || status)}</div></div>
  <div class="teams">
    <div class="team">${resultLogo(fixture.home_badge || fixture.strHomeTeamBadge || fixture.home_logo, home, 'team-logo')}${resultTeamNameHtml(home)}</div>
    <div class="scoreboard"><div><div class="${resultScoreClass(score)}"><span>${esc(score.home)}</span><b>-</b><span>${esc(score.away)}</span></div><div class="score-status">${esc(status)}</div></div></div>
    <div class="team">${resultLogo(fixture.away_badge || fixture.strAwayTeamBadge || fixture.away_logo, away, 'team-logo')}${resultTeamNameHtml(away)}</div>
  </div>
  <div class="brand">${esc(brand)}</div>
</div>
</body>
</html>`;
}

function buildFightResultHtml(input) {
  const fixture = input.fixture || {};
  const league = shortLeague(fixture.league || fixture.strLeague || 'Fights');
  const date = resultDate(fixture);
  const eventName = clean(fixture.event_name || fixture.strEvent || fixture.manual_text_override || 'Fight Night Result');
  const home = clean(fixture.home_team || fixture.strHomeTeam);
  const away = clean(fixture.away_team || fixture.strAwayTeam);
  const score = resultScore(fixture);
  const status = resultStatus(fixture);
  const poster = clean(fixture.event_thumb || fixture.event_poster || fixture.strThumb || fixture.strPoster || fixture.background_image);
  const brand = input.branding?.watermark || 'THE SPORTS HUB';
  const scoreLine = score.hasScore ? `${score.home} - ${score.away}` : status;

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #0a0608; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; overflow: hidden; border-radius: 34px; border: 2px solid rgba(239, 68, 68, .9); background: radial-gradient(circle at center, rgba(220, 38, 38, .3), transparent 42%), linear-gradient(135deg, #140608, #050204); box-shadow: inset 0 0 48px rgba(220,38,38,.32); }
.poster { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .62; filter: contrast(1.12) saturate(.86); }
.vignette { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,.92), rgba(0,0,0,.28) 45%, rgba(0,0,0,.72)); }
.top { position: relative; z-index: 4; padding: 44px 58px; display: flex; justify-content: space-between; align-items: flex-start; }
.label { color: #f87171; font-size: 32px; font-weight: 1000; letter-spacing: 4px; text-transform: uppercase; }
.date { color: #e5e7eb; font-size: 24px; font-weight: 1000; text-transform: uppercase; }
.main { position: absolute; z-index: 4; left: 70px; right: 70px; bottom: 118px; text-align: center; }
.event-title { font-size: 76px; font-weight: 1000; line-height: .92; text-transform: uppercase; text-shadow: 0 4px 30px rgba(0,0,0,.95); }
.fighters { margin-top: 34px; display: flex; align-items: center; justify-content: center; gap: 28px; flex-wrap: wrap; }
.fighter-name { font-size: 52px; font-weight: 1000; font-style: italic; text-transform: uppercase; text-shadow: 0 4px 24px rgba(0,0,0,.95); background: rgba(0,0,0,.56); padding: 8px 22px; border-radius: 8px; }
.score { margin: 32px auto 0; min-width: 280px; display: inline-flex; align-items: center; justify-content: center; padding: 16px 34px; border-radius: 999px; border: 2px solid rgba(248,113,113,.75); background: rgba(0,0,0,.72); color: #fff; font-size: 58px; font-weight: 1000; text-transform: uppercase; box-shadow: 0 0 42px rgba(239,68,68,.36); }
footer { position: absolute; z-index: 7; bottom: 24px; left: 0; width: 100%; text-align: center; color: #d7dce8; font-size: 18px; font-weight: 900; letter-spacing: 16px; text-transform: uppercase; }
</style>
</head>
<body>
<div class="card">
  ${poster ? `<img class="poster" src="${esc(poster)}" alt="">` : ''}
  <div class="vignette"></div>
  <div class="top"><div class="label">Fight Result</div><div class="date">${esc(date || status)}</div></div>
  <div class="main">
    <div class="event-title">${esc(eventName.toUpperCase())}</div>
    ${home && away ? `<div class="fighters"><span class="fighter-name">${esc(home)}</span><span class="fighter-name">${esc(away)}</span></div>` : ''}
    <div class="score">${esc(scoreLine)}</div>
  </div>
  <footer>${esc(brand)}</footer>
</div>
</body>
</html>`;
}

function buildMotorsportResultHtml(input) {
  const fixture = input.fixture || {};
  const league = shortLeague(fixture.league || fixture.strLeague || 'Motorsport');
  const date = resultDate(fixture);
  const eventName = clean(fixture.event_name || fixture.strEvent || fixture.manual_text_override || 'Race Result');
  const status = resultStatus(fixture);
  const thumb = clean(fixture.event_thumb || fixture.event_poster || fixture.strThumb || fixture.strPoster || fixture.background_image || fixture.league_badge || fixture.league_logo || fixture.strLeagueBadge || fixture.strLeagueLogo);
  const venue = clean(fixture.venue || fixture.strVenue || 'Circuit TBC');
  const brand = input.branding?.watermark || 'THE SPORTS HUB';

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #070a12; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; overflow: hidden; border-radius: 34px; border: 2px solid rgba(60, 160, 255, .9); background: radial-gradient(circle at center, rgba(30, 120, 220, .3), transparent 42%), linear-gradient(135deg, #07101d, #03060d); box-shadow: inset 0 0 48px rgba(30,120,220,.3); }
.thumb { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .5; filter: contrast(1.18) saturate(.82); }
.vignette { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,.9), rgba(0,0,0,.24) 45%, rgba(0,0,0,.68)); }
.speed-lines { position: absolute; inset: 0; background: repeating-linear-gradient(112deg, transparent 0 82px, rgba(91,184,255,.18) 83px 86px); opacity: .8; }
.top { position: relative; z-index: 4; padding: 46px 58px; display: flex; justify-content: space-between; align-items: flex-start; }
.label { color: #5bb8ff; font-size: 32px; font-weight: 1000; letter-spacing: 4px; text-transform: uppercase; }
.date { color: #e5e7eb; font-size: 24px; font-weight: 1000; text-transform: uppercase; }
.main { position: absolute; z-index: 4; left: 74px; right: 74px; bottom: 132px; text-align: center; }
.event-title { font-size: 76px; font-weight: 1000; line-height: .92; text-transform: uppercase; text-shadow: 0 4px 30px rgba(0,0,0,.95); }
.status-pill { margin: 36px auto 0; display: inline-flex; align-items: center; justify-content: center; padding: 18px 38px; border-radius: 999px; border: 2px solid rgba(91,184,255,.78); background: rgba(0,0,0,.72); color: #fff; font-size: 42px; font-weight: 1000; text-transform: uppercase; box-shadow: 0 0 42px rgba(91,184,255,.32); }
footer { position: absolute; z-index: 7; bottom: 24px; left: 0; width: 100%; text-align: center; color: #d7dce8; font-size: 18px; font-weight: 900; letter-spacing: 16px; text-transform: uppercase; }
</style>
</head>
<body>
<div class="card">
  ${thumb ? `<img class="thumb" src="${esc(thumb)}" alt="">` : ''}
  <div class="vignette"></div>
  <div class="speed-lines"></div>
  <div class="top"><div class="label">Race Result</div><div class="date">${esc(date || status)}</div></div>
  <div class="main"><div class="event-title">${esc(eventName.toUpperCase())}</div><div class="status-pill">${esc(status)}</div></div>
  <footer>${esc(brand)}</footer>
</div>
</body>
</html>`;
}

function buildResultHtml(input) {
  const fixture = input.fixture || {};
  const key = sportKey(fixture.sport || fixture.strSport || fixture.sport_key);
  const family = resultFamily(input, key);
  if (family === 'fight') return buildFightResultHtml(input);
  if (family === 'motorsport') return buildMotorsportResultHtml(input);
  if (family === 'usa') return buildUsaResultHtml(input);
  return buildStadiumResultHtml(input);
}

function buildNoFixturesHtml(input) {
  const summary = input.summary || input.fixture || {};
  const key = sportKey(summary.sport || summary.sport_key || summary.route_key || summary.title || 'sports');
  const icon = sportIcon(key);
  const title = clean(summary.title || summary.label || `${titleCase(summary.sport || 'Sports')} Fixtures TV`, 'Sports Fixtures TV');
  const date = clean(summary.date || summary.date_label || new Date().toISOString().slice(0, 10)).toUpperCase();
  const route = clean(summary.route_key || summary.topic_key || 'SPORTS');
  const label = clean(summary.sport_label || summary.sport || title);
  const brand = input.branding?.watermark || 'THE SPORTS HUB';

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<style>
* { box-sizing: border-box; }
body { margin: 0; width: 1536px; height: 864px; background: #02030a; font-family: Arial, Helvetica, sans-serif; color: #fff; overflow: hidden; }
.card { position: relative; width: 1536px; height: 864px; padding: 54px 58px; overflow: hidden; border-radius: 34px; border: 2px solid rgba(180, 55, 255, .9); background: radial-gradient(circle at center, rgba(139, 28, 220, .34), transparent 35%), linear-gradient(135deg, #040712 0%, #060819 52%, #02030a 100%); box-shadow: inset 0 0 42px rgba(176, 55, 255, .35), 0 0 20px rgba(176, 55, 255, .45); }
.bg { position: absolute; inset: 0; background: linear-gradient(to bottom, rgba(0,0,0,.15), rgba(0,0,0,.78)); background-size: cover; background-position: center; opacity: .42; filter: contrast(1.2) saturate(.85); }
.vignette { position: absolute; inset: 0; background: radial-gradient(circle at center, transparent 0 42%, rgba(0,0,0,.62) 78%), linear-gradient(to bottom, rgba(0,0,0,.05), rgba(0,0,0,.5)); }
.header { position: relative; z-index: 5; display: flex; align-items: flex-start; gap: 34px; }
.league-logo { width: 160px; height: 76px; object-fit: contain; }
.header-line { width: 2px; height: 86px; background: linear-gradient(to bottom, transparent, #b83cff, transparent); }
.league-title { font-size: 42px; font-weight: 1000; letter-spacing: 2px; text-transform: uppercase; }
.fixture-label { margin-top: 10px; color: #b33cff; font-size: 30px; font-weight: 1000; text-transform: uppercase; }
.stage { position: relative; z-index: 4; height: 520px; display: grid; place-items: center; text-align: center; }
.empty-icon { font-size: 96px; margin-bottom: 24px; filter: grayscale(.3); }
.empty-title { font-size: 64px; font-weight: 1000; text-transform: uppercase; text-shadow: 0 6px 32px rgba(0,0,0,.8); }
.empty-copy { margin-top: 18px; color: #b4aecb; font-size: 32px; font-weight: 800; }
.footer { position: absolute; z-index: 7; bottom: 32px; left: 0; width: 100%; text-align: center; color: transparent; font-size: 20px; font-weight: 900; letter-spacing: 18px; text-transform: uppercase; background: linear-gradient(135deg, #e8e8e8 0%, #ffffff 25%, #c0c0c0 50%, #ffffff 75%, #e0e0e0 100%); -webkit-background-clip: text; background-clip: text; }
.footer::before, .footer::after { content: ""; display: inline-block; width: 190px; height: 2px; margin: 0 36px 6px; background: linear-gradient(90deg, transparent, #b13cff); opacity: .75; }
.footer::after { background: linear-gradient(90deg, #b13cff, transparent); }
</style>
</head>
<body>
<div class="card">
  <div class="bg"></div>
  <div class="vignette"></div>
  <div class="header">
    <div class="league-logo" style="border-radius:50%;background:rgba(255,255,255,.06);display:grid;place-items:center;font-size:48px">${icon}</div>
    <div class="header-line"></div>
    <div>
      <div class="league-title">${esc(label.toUpperCase())}</div>
      <div class="fixture-label">Fixture</div>
    </div>
  </div>
  <div class="stage">
    <div>
      <div class="empty-icon">${icon}</div>
      <div class="empty-title">No Fixtures Today</div>
      <div class="empty-copy">Nothing scheduled for today.</div>
    </div>
  </div>
  <div class="footer">${esc(brand)}</div>
</div>
</body>
</html>`;
}

function sportIcon(key) {
  const icons = {
    football: '⚽',
    rugby: '🏉',
    cricket: '🏏',
    tennis: '🎾',
    fights: '🥊',
    motorsport: '🏎️',
    basketball: '🏀',
    baseball: '⚾',
    american_football: '🏈',
    ice_hockey: '🏒',
    golf: '⛳',
    other_sports: '🏅',
  };
  return icons[key] || '⚽';
}

function sportLabel(key) {
  const labels = {
    football: 'Football',
    rugby: 'Rugby',
    cricket: 'Cricket',
    tennis: 'Tennis',
    fights: 'Fights',
    motorsport: 'Motorsport',
    basketball: 'Basketball',
    baseball: 'Baseball',
    american_football: 'American Football',
    ice_hockey: 'Ice Hockey',
    golf: 'Golf',
    other_sports: 'Sports',
  };
  return labels[key] || 'Sports';
}

function leagueShortName(name) {
  return shortLeague(name).toUpperCase().slice(0, 16);
}

function buildLeagueHeaderHtml(input) {
  const info = input.leagueInfo || {};
  const width = Number(input.width || 1200);
  const height = Number(input.height || 675);
  const key = sportKey(info.sport || 'sports');
  const accent = input.accent || accentFor(key);
  const leagueRaw = shortLeague(info.name || 'League');
  const leagueName = leagueRaw.toUpperCase();
  const leagueShort = leagueShortName(info.name || 'TV');
  const titleSize = leagueName.length > 18 ? '52px' : leagueName.length > 12 ? '68px' : '82px';
  const titleTop = leagueName.length > 18 ? '360px' : '378px';
  const subtitleTop = leagueName.length > 18 ? '500px' : '515px';
  const rawDate = clean(info.date || '');
  const today = new Date();
  const fallbackDate = today.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).toUpperCase();
  const parsed = rawDate ? new Date(rawDate) : today;
  const displayDate = !isNaN(parsed.getTime()) ? parsed.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).toUpperCase() : fallbackDate;
  const logoUrl = clean(info.badge || info.logo);

  return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; width: ${width}px; height: ${height}px; background: #050508; font-family: Arial, Helvetica, sans-serif; overflow: hidden; }
  .card {
    position: relative; width: ${width}px; height: ${height}px; overflow: hidden; color: white;
    background:
      radial-gradient(circle at center, color-mix(in srgb, ${accent} 45%, transparent) 0%, transparent 38%),
      linear-gradient(135deg, #050508 0%, #12001f 50%, #050508 100%);
    border: 3px solid ${accent};
    border-radius: 28px;
  }
  .lines {
    position: absolute; inset: 0;
    background:
      linear-gradient(120deg, transparent 20%, color-mix(in srgb, ${accent} 35%, transparent) 21%, transparent 22%),
      linear-gradient(300deg, transparent 70%, color-mix(in srgb, ${accent} 25%, transparent) 71%, transparent 72%);
    opacity: 0.9;
  }
  .top-label {
    position: absolute; top: 0; left: 0;
    padding: 20px 36px;
    background: linear-gradient(90deg, ${accent}, color-mix(in srgb, ${accent} 50%, #000) 100%);
    font-size: 28px; font-weight: 800; letter-spacing: 4px;
    border-bottom-right-radius: 6px;
    white-space: nowrap;
  }
  .logo-wrap {
    position: absolute; top: 116px; left: 50%; transform: translateX(-50%);
    width: 255px; height: 255px;
    display: flex; align-items: center; justify-content: center;
  }
  .logo-wrap img { width: 200px; height: 200px; object-fit: contain; filter: drop-shadow(0 0 12px rgba(0,0,0,.7)); }
  .logo-wrap .fallback {
    width: 200px; height: 200px; border-radius: 50%;
    display: grid; place-items: center;
    background: ${accent}; color: #fff;
    font-size: 72px; font-weight: 1000;
    box-shadow: 0 0 30px color-mix(in srgb, ${accent} 60%, transparent);
  }
  .title {
    position: absolute; width: 100%; text-align: center;
    font-size: ${titleSize}; font-weight: 1000; font-style: italic;
    letter-spacing: -2px; text-transform: uppercase;
    text-shadow: 0 8px 20px rgba(0,0,0,0.85);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    padding: 0 60px;
  }
  .subtitle {
    position: absolute; width: 100%; text-align: center;
    color: #b0b4c0; font-size: 24px; font-weight: 800; letter-spacing: 18px;
  }
  .watermark {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 340px; opacity: 0.035; font-weight: 900;
    pointer-events: none;
  }
</style>
</head>
<body>
<div class="card">
  <div class="lines"></div>
  <div class="top-label">📅 ${esc(displayDate)}</div>
  <div class="watermark">${esc(leagueShort)}</div>
  <div class="logo-wrap">${logoHtml(logoUrl, leagueName, '')}</div>
  <div class="title" style="top: ${titleTop}">${esc(leagueName)}</div>
  <div class="subtitle" style="top: ${subtitleTop}">DAILY FIXTURES</div>
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
      const cardKind = input.kind || input.fixture?.kind || '';
      const html = enhanceHtml(cardKind === 'no-fixtures' ? buildNoFixturesHtml(input) : input.kind === 'league-header' ? buildLeagueHeaderHtml(input) : cardKind === 'result' ? buildResultHtml(input) : buildHtml(input), input);
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
