# SportsBot Production Deploy

Run these from the `backend` directory on the live server.

---

## 1. System Packages

```bash
sudo apt update
sudo apt install -y \
    php8.3-cli php8.3-mbstring php8.3-gd php8.3-curl \
    php8.3-mysql php8.3-xml php8.3-zip php8.3-bcmath \
    php8.3-xml php8.3-fileinfo php8.3-exif \
    nodejs npm chromium \
    fonts-dejavu-core \
    libnss3 libnspr4 libatk-bridge2.0-0 \
    libatk1.0-0 libcups2 libdrm2 libxkbcommon0 \
    libxcomposite1 libxdamage1 libxrandr2 \
    libgbm1 libpango-1.0-0 libcairo2 \
    libasound2 libegl1
```

Replace `php8.3-*` with your server's PHP version (8.2+ required).

---

## 2. Node.js & Puppeteer (for V3 Browser Cards)

Install the Puppeteer npm package and its bundled Chromium:

```bash
npm install
```

This reads `backend/package.json` and installs `puppeteer ^22.0.0` under `backend/node_modules/`.

> **Note:** Use `npm install` (not `npm ci`) — the lockfile may not be in sync.

---

## 3. Chrome/Chromium

Puppeteer 22+ bundles its own Chromium during `npm install`. If it can't find it, the script also checks:

- `PUPPETEER_EXECUTABLE_PATH` env var
- `/usr/bin/chromium`
- `/usr/bin/chromium-browser`
- `/usr/bin/google-chrome`
- `/usr/bin/google-chrome-stable`

On Ubuntu/Debian you can install system Chromium (as done above), or let Puppeteer use its bundled copy.

If auto-detection fails, set explicitly in `.env`:

```env
SPORTSBOT_CARD_CHROME_PATH=/usr/bin/chromium
# or
PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
```

---

## 4. PHP Composer Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

Required PHP extensions (declared in `composer.json`):
- `php ^8.2`
- `ext-gd` (for GD fallback card rendering)
- `ext-bcmath`, `ext-curl`, `ext-exif`, `ext-fileinfo`, `ext-gd`, `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pdo`, `ext-pdo_mysql`, `ext-xml`, `ext-zip`

---

## 5. Database & Storage

```bash
php artisan migrate --force
```

Ensure these directories are writable by the web server user:

```
storage/app/sportsbot/cards/
storage/app/sportsbot/render-input/
storage/app/sportsbot/assets/
storage/logs/
bootstrap/cache/
```

---

## 6. Permissions Fix Script

Create `/usr/local/bin/sportsbot-fix-permissions` on the server:

```bash
sudo tee /usr/local/bin/sportsbot-fix-permissions << 'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
TARGET="${1:-/srv/laravelcp}"
chown -R www-data:www-data "$TARGET/storage" "$TARGET/bootstrap/cache"
chmod -R 775 "$TARGET/storage" "$TARGET/bootstrap/cache"
echo "Permissions fixed: $TARGET"
SCRIPT
sudo chmod +x /usr/local/bin/sportsbot-fix-permissions
```

---

## 7. Environment (.env)

Copy `backend/.env.example` and configure. All SportsBot-relevant variables:

```env
# ── Core ──
SPORTSBOT_ENABLED=true
SPORTSBOT_SEND_MESSAGES=true
SPORTSBOT_SCHEDULE_ENABLED=true
SPORTSBOT_PROVIDER=thesportsdb
SPORTSBOT_THESPORTSDB_API_KEY=

# ── Telegram ──
SPORTSBOT_TELEGRAM_BOT_TOKEN=
SPORTSBOT_TELEGRAM_CHAT_ID=
SPORTSBOT_TELEGRAM_MESSAGE_THREAD_ID=
SPORTSBOT_TELEGRAM_EXTRA_CHAT_IDS=
SPORTSBOT_TELEGRAM_PARSE_MODE=HTML
SPORTSBOT_TELEGRAM_DISABLE_NOTIFICATION=false
SPORTSBOT_TELEGRAM_WEBHOOK_ENABLED=false
SPORTSBOT_TELEGRAM_WEBHOOK_SECRET=

# ── Discord Bot-token delivery ──
SPORTSBOT_DISCORD_ENABLED=true
SPORTSBOT_DISCORD_BOT_TOKEN=
SPORTSBOT_DISCORD_DEFAULT_CHANNEL_ID=
# Optional route map. A default channel is enough for all routes.
# Example: {"FORMULA_1":"123456789012345678","FOOTBALL":"234567890123456789"}
SPORTSBOT_DISCORD_BOT_CHANNELS_JSON=

# ── Discord Webhooks (fallback mode when bot token is empty) ──
SPORTSBOT_DISCORD_WEBHOOK_URL=
SPORTSBOT_DISCORD_USERNAME=SportsBot
SPORTSBOT_DISCORD_AVATAR_URL=

# ── Card Rendering ──
SPORTSBOT_CARDS_ENABLED=true
SPORTSBOT_CARD_V3_BROWSER_ENABLED=true
SPORTSBOT_CARD_NODE_BINARY=node
SPORTSBOT_CARD_CHROME_PATH=
SPORTSBOT_CARD_BROWSER_TIMEOUT=15
SPORTSBOT_CARD_BROWSER_RETRIES=1
SPORTSBOT_CARD_BROWSER_CONCURRENCY=2
SPORTSBOT_CARD_BROWSER_ARGS=--no-sandbox,--disable-setuid-sandbox,--disable-dev-shm-usage
SPORTSBOT_CARD_GD_FALLBACK_ENABLED=true
SPORTSBOT_CARD_LOW_BANDWIDTH_MODE=false
SPORTSBOT_CARD_DEFAULT_TEMPLATE=stadium-v3
SPORTSBOT_CARD_DEFAULT_THEME=limitless-dark
# SPORTSBOT_CARD_WATERMARK="The Sports Hub"
SPORTSBOT_RICH_CARDS_ENABLED=true

# ── Font paths (GD fallback) ──
SPORTSBOT_CARD_FONT_REGULAR=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
SPORTSBOT_CARD_FONT_BOLD=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf

# ── Asset Cache ──
SPORTSBOT_CARD_ASSET_CACHE_TIMEOUT=12
SPORTSBOT_CARD_ASSET_CACHE_RETRIES=2
SPORTSBOT_CARD_ASSET_CACHE_RETRY_DELAY_MS=250
SPORTSBOT_CARD_ASSET_CACHE_STALE_DAYS=30
SPORTSBOT_CARD_IMAGE_CACHE_TTL=604800

# ── Updater ──
SPORTSBOT_UPDATER_ENABLED=true
SPORTSBOT_UPDATER_REMOTE=origin
SPORTSBOT_UPDATER_FORCE_SYNC_TARGET=origin/main
SPORTSBOT_UPDATER_ADMIN_FRONTEND_PATH=resources/admin
SPORTSBOT_UPDATER_REPAIR_PERMISSIONS_ENABLED=true

# ── Scrapers ──
SPORTSBOT_SCRAPERS_ENABLED=true
SPORTSBOT_SCRAPER_SEARCH_ENABLED=
SPORTSBOT_SCRAPER_SEARCH_URLS=
SPORTSBOT_COMBAT_POSTER_URLS=
SPORTSBOT_BROADCAST_SCHEDULE_URLS=
SPORTSBOT_F1_SCHEDULE_URLS=
```

---

## 8. Safe Deploy Order

```bash
php artisan down --retry=30

composer install --no-dev --optimize-autoloader
npm install
php artisan migrate --force

# Fix permissions
sudo /usr/local/bin/sportsbot-fix-permissions

# Verify
bash scripts/sportsbot-production-check.sh --discord-bot

# Cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan up
```

---

## 9. Health Verification

Run the health check:

```bash
php artisan sportsbot:health --discord-bot
```

Expected output includes:

| Check | Should show |
|---|---|
| V3 browser cards enabled | OK |
| V3 renderer script exists | OK |
| Node available | OK (shows version) |
| Puppeteer package loadable | OK (shows puppeteer) |
| Chrome executable | OK (shows path) |
| Storage writable paths | OK |
| Telegram routes assigned | OK |
| Discord bot token configured | OK |
| Discord bot channel map configured | OK |

To verify a real card render:

```bash
bash scripts/sportsbot-production-check.sh --discord-bot
```

---

## 10. Scheduler on Plesk / IONOS

Use one Laravel Scheduler cron. On Plesk with root SSH, create a server-level scheduled task or root cron entry:

```cron
* * * * * cd /path/to/backend && /path/to/php artisan schedule:run >> /dev/null 2>&1
```

Prefer this command cron over IONOS account URL cron. IONOS URL cron runs as a web request and is capped at 60 seconds, which is too tight for render/enrich/publish bursts. If command cron is blocked, set `APP_SCHEDULER_HTTP_TOKEN` and call:

```text
https://your-domain.example/scheduler/run/YOUR_LONG_RANDOM_TOKEN
```

Plesk can run commands, URLs, or PHP scripts. On Linux, subscription scheduled tasks may run in a chrooted shell, so verify `php`, `node`, `npm`, `composer`, `chromium`, and the project path are available to the scheduled-task user. Root/server-level scheduled tasks avoid most chroot surprises.

References:
- https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks.64993/
- https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks/plesk-for-linux-scheduled-tasks-shell-setting.78064/
- https://www.ionos.com/help/hosting/cron-jobs/cron-job-manager/

---

## 11. Automation

Enable the fixture queue pipeline in the admin **Autopilot** screen, or via `.env`:

```env
SPORTSBOT_FIXTURE_QUEUE_SCHEDULE_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_PREFETCH_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_ENRICH_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_RENDER_ENABLED=true
SPORTSBOT_FIXTURE_QUEUE_PUBLISH_ENABLED=true
```

Disable legacy text schedules to avoid duplicate messages:

```env
SPORTSBOT_FIXTURES_TODAY_SCHEDULE_ENABLED=false
SPORTSBOT_TV_GUIDE_SCHEDULE_ENABLED=false
```
