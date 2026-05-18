# SportsBot Production Deploy

Run these from the `backend` directory on the live server.

## Required Server Packages

SportsBot production cards need PHP, GD fonts, Node, Puppeteer, and Chrome/Chromium:

```bash
sudo apt update
sudo apt install php8.3-mbstring php8.3-gd php8.3-curl php8.3-mysql php8.3-xml php8.3-zip php8.3-bcmath nodejs npm chromium fonts-dejavu-core
```

If your server uses a different PHP version, replace `php8.3-*` with the installed version.

## Required Private Runtime Files

These are intentionally ignored by git and must be copied or configured on live:

```text
license_public.pem
storage/license_key
.env
```

Do not put `license_private.pem` on a customer/live app server unless that server is intended to generate licence keys.

## V3 Card Runtime

Install Node dependencies on live:

```bash
npm ci
```

If Chromium is not at a standard path, set:

```env
SPORTSBOT_CARD_CHROME_PATH=/usr/bin/chromium
```

Optional font overrides:

```env
SPORTSBOT_CARD_FONT_REGULAR=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
SPORTSBOT_CARD_FONT_BOLD=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf
```

## Automation Switches

For fully automated fixture cards, enable the fixture queue scheduler in the admin Autopilot screen, or set:

```env
SPORTSBOT_FIXTURE_QUEUE_SCHEDULE_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_PREFETCH_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_ENRICH_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_RENDER_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_PUBLISH_ENABLED=true
```

The queue scheduler runs prefetch, TV enrichment, V3 card rendering, and publish. Keep the older text-style topic schedules off unless you deliberately want those messages too:

```env
SPORTSBOT_FIXTURES_TODAY_SCHEDULE_ENABLED=false
SPORTSBOT_TV_GUIDE_SCHEDULE_ENABLED=false
```

## Deployment Check

After uploading code and runtime files:

```bash
bash scripts/sportsbot-production-check.sh
```

The script checks PHP syntax before Laravel boots. This catches broken uploads like:

```text
ParseError ... SportsBotCardRenderer.php
```

It also checks the licence, migrations, writable storage, PHP extensions, V3 renderer, Puppeteer, Chrome/Chromium, Telegram route coverage, and a real V3 no-fixtures card render.

## Safe Deploy Order

```bash
php artisan down
composer install --no-dev --optimize-autoloader
npm ci
php artisan migrate --force
bash scripts/sportsbot-production-check.sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

Only import a full local database over live if live has no data you need to keep. Use an SQL dump that includes `DROP TABLE IF EXISTS`; otherwise phpMyAdmin can stop on existing tables and leave the database half-imported.
