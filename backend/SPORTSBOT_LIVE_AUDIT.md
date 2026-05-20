# SportsBot + Discord Bot Live Audit

Use this audit before uploading the current release candidate to IONOS/Plesk. It assumes root SSH, Plesk access, one Laravel Scheduler cron, and Discord bot-token channel delivery.

## Go / No-Go Rule

Go live only when all of these are true:

- `bash scripts/sportsbot-production-check.sh --strict --discord-bot` exits 0 on the live server.
- `php artisan sportsbot:health --json --fix --render --discord-bot` reports no failed checks.
- Browser V3 card smoke test reports `renderer_used=browser_v3`; GD fallback is a blocker unless intentionally accepted for launch.
- Discord bot route test sends both a text message and a card to the expected channel.
- One scheduler cycle writes fresh SportsBot logs and, when sending is enabled, delivery rows.

## Pre-Upload Audit

Run locally from the repository root:

```bash
git status --short
cd backend
php artisan sportsbot:health --json --discord-bot
bash scripts/sportsbot-production-check.sh --discord-bot
```

Expected local caveat: if local `.env` points `DB_HOST=mysql` and MySQL is not reachable from the shell, database, migration, cache, and scheduler checks can fail locally. Those checks must pass on the live server.

Review the upload contents before copying:

- Include dirty SportsBot PHP changes, migrations, admin source changes, built `public/admin` assets, `resources/sportsbot/v3-card-renderer.cjs`, `composer.lock`, and both npm lockfiles.
- Do not upload local `storage/logs`, generated `storage/app/sportsbot/cards`, `storage/app/sportsbot/render-debug`, or local `.env`.
- Keep a database backup and a copy of live `.env` before migration.

## Live Server Requirements

From `backend` on the live server:

```bash
php -v
php -m
composer check-platform-reqs --no-interaction
node --version
npm --version
npm ci
php artisan migrate:status
php artisan schedule:list
```

Required PHP/runtime surface:

- PHP 8.2 or newer.
- PHP extensions: `bcmath`, `curl`, `exif`, `fileinfo`, `gd`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `xml`, `zip`.
- Composer, Node, npm, Puppeteer, and Chromium/Chrome available to the web user and scheduled-task user.
- Writable `storage`, `storage/app/sportsbot/cards`, `storage/app/sportsbot/render-input`, `storage/app/sportsbot/assets`, `storage/logs`, and `bootstrap/cache`.

## Production Environment

Minimum live `.env` posture:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
FRONTEND_URL=https://your-domain.example
APP_TIMEZONE=Europe/London
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
APP_SCHEDULER_HTTP_TOKEN=

SPORTSBOT_ENABLED=true
SPORTSBOT_SEND_MESSAGES=true
SPORTSBOT_PROVIDER=thesportsdb
SPORTSBOT_THESPORTSDB_API_KEY=...

SPORTSBOT_DISCORD_ENABLED=true
SPORTSBOT_DISCORD_BOT_TOKEN=...
SPORTSBOT_DISCORD_DEFAULT_CHANNEL_ID=...
SPORTSBOT_DISCORD_BOT_CHANNELS_JSON=

SPORTSBOT_CARD_V3_BROWSER_ENABLED=true
SPORTSBOT_CARD_GD_FALLBACK_ENABLED=true
SPORTSBOT_CARD_BROWSER_ARGS=--no-sandbox,--disable-setuid-sandbox,--disable-dev-shm-usage
SPORTSBOT_UPDATER_ENABLED=false
```

For route-specific Discord channels, use JSON:

```env
SPORTSBOT_DISCORD_BOT_CHANNELS_JSON={"FOOTBALL":"123456789012345678","FORMULA_1":"234567890123456789","MMA":"345678901234567890"}
```

`SPORTSBOT_DISCORD_DEFAULT_CHANNEL_ID` is enough if every route can post to one Discord channel. Bot-token mode takes priority over webhook mode when `SPORTSBOT_DISCORD_BOT_TOKEN` is set.

## Live Audit Commands

Run after dependencies and `.env` are in place:

```bash
cd /path/to/backend
php artisan down --retry=30
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
bash scripts/sportsbot-production-check.sh --strict --discord-bot
php artisan up
```

If `sportsbot-production-check.sh` fails, keep the app in maintenance mode until the blocker is fixed or explicitly accepted.

## Discord Bot Verification

The Discord bot must have permission in every target channel to:

- View channel.
- Send messages.
- Attach files.
- Embed links.
- Use external emojis is optional; SportsBot does not require it for core delivery.

Then run:

```bash
php artisan sportsbot:health --json --discord-bot
```

In the admin panel, use SportsBot Discord route test for the route expected to publish next. Confirm the test message lands in the correct channel. For a card upload smoke, use the Discord diagnostic card action from SportsBot Coverage after browser render checks pass.

## Scheduler

Create one root/server-level Plesk scheduled task or root cron:

```cron
* * * * * cd /path/to/backend && /path/to/php artisan schedule:run >> /dev/null 2>&1
```

Do not create separate cron entries for each SportsBot command unless debugging a single job. The Laravel Scheduler already controls `sportsbot:run-native`, fixture prefetch, enrich, render, publish, live now, and digest jobs.

Avoid IONOS account URL cron for the main scheduler. IONOS URL cron is a web-service call and may be cancelled after 60 seconds. Use `/scheduler/run/{token}` only as a fallback when command cron is impossible.

References:

- Plesk scheduled tasks: https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks.64993/
- Plesk Linux scheduled-task shell: https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks/plesk-for-linux-scheduled-tasks-shell-setting.78064/
- IONOS cron job manager: https://www.ionos.com/help/hosting/cron-jobs/cron-job-manager/

## Post-Go-Live Checks

After one scheduler cycle:

```bash
php artisan schedule:list
tail -n 100 storage/logs/sportsbot-fixture-queue-prefetch.log
tail -n 100 storage/logs/sportsbot-fixture-queue-enrich.log
tail -n 100 storage/logs/sportsbot-fixture-queue-render.log
tail -n 100 storage/logs/sportsbot-fixture-queue-publish.log
php artisan sportsbot:health --json --discord-bot
```

In the database/admin panel, confirm recent rows in:

- `sportsbot_pipeline_runs`
- `sportsbot_fixture_queue`
- `sportsbot_deliveries`
- `sportsbot_telegram_messages` if Telegram remains enabled

Keep `SPORTSBOT_UPDATER_ENABLED=false` on live unless you deliberately want admin-triggered Git updates and have tested the sudo permission helper.
