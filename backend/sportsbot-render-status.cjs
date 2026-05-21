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
    <div class="name">${site.name}</div>
    <span class="badge ${badgeClass}">${badgeText}</span>
  </div>`;
}

const statusClass = anyDown ? 'down' : '';
const statusMsg = anyDown ? '<span class="down">Experiencing downtime</span>' : '<span class="up">All systems operational</span>';
const html = template
  .replace('{{STATUS_CLASS}}', statusClass)
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
