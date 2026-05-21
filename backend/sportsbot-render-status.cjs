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

let servicesHtml = '';
for (const site of data.sites) {
  const isOnline = site.status === 'online';
  const pct = site.uptime_percentage || 100;
  const pctClass = pct >= 99 ? '' : pct >= 95 ? 'warn' : 'down';
  const dotClass = isOnline ? '' : 'down';

  let barsHtml = '';
  const days = site.daily_status || [];
  for (const d of days) {
    const barClass = d.status === 'up' ? '' : d.status === 'degraded' ? 'warn' : d.status === 'down' ? 'down' : 'none';
    barsHtml += `<span class="bar ${barClass}"></span>`;
  }

  servicesHtml += `
  <div class="service">
    <div class="icon">📡</div>
    <div>
      <div class="name">${site.name}</div>
    </div>
    <div>
      <div class="bars">${barsHtml}</div>
      <div class="days">Last 30 days</div>
    </div>
    <div class="stats">
      <div><span class="status-dot ${dotClass}"></span><span class="percent ${pctClass}">${pct.toFixed(3)}%</span></div>
      <div>uptime</div>
      <div>${site.avg_response || '-'}</div>
    </div>
  </div>`;
}

const html = template.replace('{{DATE}}', date).replace('{{SERVICES}}', servicesHtml);

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--font-render-hinting=none'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1200, height: 800 });
  await page.setContent(html, { waitUntil: 'networkidle0' });
  const el = await page.$('.status-card');
  await el.screenshot({ path: outputPath, omitBackground: true });
  await browser.close();
  console.log('Rendered:', outputPath);
})();
