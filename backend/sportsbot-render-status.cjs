const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const inputPath = process.argv[2];
const outputPath = process.argv[3];

if (!inputPath || !outputPath) {
  console.error('Usage: node sportsbot-render-status.cjs <input.json> <output.png>');
  process.exit(1);
}

const data = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
const template = fs.readFileSync(path.join(__dirname, 'resources/cards/templates/uptime-card.html'), 'utf8');

const date = new Date(data.checked_at || Date.now()).toLocaleDateString('en-GB', {
  month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
});

function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function shortUrl(url) {
  try {
    const parsed = new URL(String(url || ''));
    return parsed.hostname + parsed.pathname.replace(/\/$/, '');
  } catch (_) {
    return String(url || '');
  }
}

let anyDown = false;
for (const site of data.sites || []) {
  const isOnline = site.status === 'online';
  if (!isOnline) anyDown = true;
}

const firstSite = (data.sites || [])[0] || {};
const isAlert = data.mode === 'alert';
const statusClass = anyDown ? 'down' : 'up';
const kicker = data.kicker || (isAlert ? (anyDown ? 'Downtime Alert' : 'Recovery Notice') : 'Uptime Monitor');
const headline = data.title || (anyDown ? 'Experiencing Downtime' : 'We Are Now Back Online');
const statusMsg = data.message || (anyDown
  ? 'We are facing some issues. Please wait whilst we fix this.'
  : 'All operations are now functioning normally.');

const html = template
  .replace('{{KICKER}}', esc(kicker))
  .replaceAll('{{STATUS_CLASS}}', statusClass)
  .replace('{{HEADLINE}}', esc(headline))
  .replace('{{STATUS_MSG}}', esc(statusMsg))
  .replace('{{SERVICE_NAME}}', esc(firstSite.name || 'Server'))
  .replace('{{SERVICE_URL}}', esc(shortUrl(firstSite.url || '')))
  .replace('{{STATE_LABEL}}', esc(anyDown ? 'Downtime' : 'Online'))
  .replace('{{DATE}}', date);

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=medium'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 760, deviceScaleFactor: 2 });
  await page.setContent(html, { waitUntil: 'networkidle0' });
  await page.emulateMediaType('screen');
  await page.evaluateHandle('document.fonts.ready');
  const el = await page.$('.card');
  await el.screenshot({ path: outputPath, omitBackground: true });
  await browser.close();
  console.log('Rendered:', outputPath);
})();
