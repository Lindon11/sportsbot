const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const inputPath = process.argv[2];
const outputPath = process.argv[3];

if (!inputPath || !outputPath) {
  console.error('Usage: node sportsbot-render-status.js <input.json> <output.png>');
  process.exit(1);
}

const data = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
const template = fs.readFileSync(path.join(__dirname, 'resources/cards/templates/uptime-card.html'), 'utf8');

const date = new Date().toLocaleDateString('en-US', {
  month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
});

let anyDown = false;
let servicesHtml = '';
for (const site of data.sites) {
  const isOnline = site.status === 'online';
  if (!isOnline) anyDown = true;
  const badgeClass = isOnline ? 'up' : 'down';
  const badgeText = isOnline ? 'Operational' : 'Downtime';

  servicesHtml += `
  <div class="row">
    <span class="site-name">${site.name}</span>
    <span class="status-badge ${badgeClass}">${badgeText}</span>
  </div>`;
}

const statusClass = anyDown ? 'down' : '';
const statusTitle = anyDown ? 'Experiencing Downtime' : 'All Systems Operational';
const statusMsg = anyDown
  ? 'Some services are currently experiencing downtime. Please wait while we restore normal operation.'
  : 'All systems are running smoothly. No issues detected.';
const icon = anyDown
  ? '<svg fill="#ef4444" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
  : '<svg fill="#22c55e" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';

const html = template
  .replace('{{STATUS_CLASS}}', statusClass)
  .replace('{{ICON}}', icon)
  .replace('{{STATUS_TITLE}}', statusTitle)
  .replace('{{STATUS_MSG}}', statusMsg)
  .replace('{{DATE}}', date)
  .replace('{{SERVICES}}', servicesHtml);

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=medium'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1200, height: 1200, deviceScaleFactor: 2 });
  await page.setContent(html, { waitUntil: 'networkidle0' });
  await page.emulateMediaType('screen');
  await page.evaluateHandle('document.fonts.ready');
  const el = await page.$('.card');
  const box = await el.boundingBox();
  await el.screenshot({ path: outputPath, omitBackground: true, clip: { x: box.x, y: box.y, width: box.width, height: box.height } });
  await browser.close();
  console.log('Rendered:', outputPath);
})();
