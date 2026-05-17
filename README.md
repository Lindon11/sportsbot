# PHP Telegram Multi-Sport and TV Sports Alert Bot

Production-ready, cron-driven PHP 8.2 Telegram bot for automated football and multi-sport alerts, daily customer guide digests, match cards, kickoff reminders, live scores where TheSportsDB supports them, and pushed TV sports listings using TheSportsDB premium V2 API.

The bot stays cron-friendly: it does not require Telegram commands or webhooks. A cron job polls live sports, TV listing data, and optional Telegram button callbacks, then posts alert graphics and guide messages into private Telegram groups and forum topics.

## Coverage

The default coverage preset is `uk_sports`. It enables Soccer, Rugby, Rugby Union, Rugby League, Cricket, Tennis, Darts, Snooker, Golf, Motorsport, Formula 1, Boxing, MMA, American Football, Basketball, Baseball, and Ice Hockey.

These football competitions remain available in the legacy football league selector by default:

| Competition | TheSportsDB league ID |
| --- | ---: |
| English Premier League | 4328 |
| EFL Championship | 4329 |
| League One | 4396 |
| League Two | 4397 |
| FA Cup | 4482 |
| EFL Cup | 4570 |
| Scottish Premiership | 4330 |
| Scottish Championship | 4395 |
| Scottish League One | 4669 |
| Scottish League Two | 4670 |
| Scottish FA Cup | 4723 |
| Scottish League Cup | 4888 |
| UEFA Champions League | 4480 |
| UEFA Europa League | 4481 |
| UEFA Womens Champions League | 4889 |

The bot now fetches `/livescore/all` first, filters by enabled sports/leagues, and falls back to `/livescore/soccer` if the all-sports endpoint is unavailable. Soccer still uses the legacy football league allow-list by default; non-soccer fixtures come from the coverage registry populated by Discover Coverage in admin.

## Features

- Goal alerts with score-change detection and timeline scorer lookup when available
- All-sports live score polling through `/livescore/all`
- Generic multi-sport alerts for match start, score updates, period/status changes, and full-time
- Kick-off alerts when a match first appears live near minute 0-3
- Half-time alerts on `HT`
- Full-time alerts on `FT`, with goal scorers when timeline data is available
- Red card alerts from event timeline data when available
- Yellow card alerts from event timeline data
- Substitution alerts from event timeline data
- Upcoming match previews posted before kick-off (configurable hours ahead)
- TV sports listings by configured channel using `/filter/tv/channel/{strChannel}`
- TV guide images with channel logos when TheSportsDB returns channel artwork
- Configurable TV sport filters such as Soccer, Darts, Rugby, Snooker, Cricket, Tennis, and more
- Daily pushed TV guide for configured channels, with optional all-sports or football-only mode
- Smart matchday burst cards for fixtures, kick-off soon, live now, TV guide, results, and tomorrow lookahead
- V2 content packs for morning planners, TV-now cards, and weekend planners
- Daily customer sports guide with live scores, fixtures, TV channels, followed-team highlights, and inline follow buttons
- Telegram forum topic routing through default topic IDs and per-sport route topic IDs
- Customer team/player follow preferences stored from Telegram button taps
- Sport profiles so non-football alerts use sport-aware score, period, start, and final wording
- Card queue with pagination so busy days send multiple readable cards instead of one overstuffed digest
- Telegram outbox tracking for route-aware delivery attempts, retries, and per-chat idempotency
- Alert decision logging for sent, skipped, failed, and duplicate alert outcomes
- Render engine controls for `auto`, `puppeteer`, and `gd`, with Chrome profile/cache settings
- Operator health checks for configuration, writable paths, renderer status, DNS, cron freshness, and outbox backlog
- Daily all-sports match cards remain available when burst cards are disabled
- Coverage discovery for TheSportsDB sports and leagues through `/all/sports` and `/all/leagues`
- Sport-specific Telegram routing through `BOT_TELEGRAM_ROUTES_JSON`
- TV channel names appended to match previews when TheSportsDB links a listing to the event
- Error alerting via a separate Telegram chat for bot failures
- Multi-group support: send alerts to multiple Telegram groups simultaneously
- SQLite state database for match state, sent alert keys, and API response cache
- Duplicate protection per alert key and per TheSportsDB timeline event
- Telegram Bot API `sendPhoto` delivery
- Dark 1280x720 GD-generated graphics with badges, score, event type, minute, league, and fallback crests
- API cache and request pacing for rate-limit protection
- Single-run lock to prevent overlapping cron executions
- Automatic cleanup of generated non-sample images
- Private admin control centre with card studio, scheduler controls, route tests, queue visibility, health, TV listing controls, and multi-group config

## Project Structure

```text
/config
/assets
/cache
/generated
/fonts
/logs
/public
config.php
check_live.php
runner.php
generate_image.php
telegram.php
functions.php
README.md
```

## Requirements

- PHP 8.2 CLI
- PHP extensions: `curl`, `gd`, `sqlite3`, `json`
- TheSportsDB premium API key
- Telegram bot token
- Private Telegram group chat ID
- Optional admin UI: Nginx or Apache with PHP-FPM

## Ubuntu VPS Install

```bash
sudo apt update
sudo apt install -y php8.2-cli php8.2-curl php8.2-gd php8.2-sqlite3 ca-certificates fonts-dejavu-core
```

If you want the private setup UI, also install:

```bash
sudo apt install -y nginx php8.2-fpm
```

Deploy the project:

```bash
sudo mkdir -p /home/user/footballbot
sudo chown -R "$USER":"$USER" /home/user/footballbot
rsync -av ./ /home/user/footballbot/
cd /home/user/footballbot
mkdir -p cache/images generated logs fonts assets
```

Create an env file:

```bash
cp config/.env.example config/footballbot.env
nano config/footballbot.env
```

Example:

```bash
TELEGRAM_BOT_TOKEN=123456789:replace_me
TELEGRAM_CHAT_ID=-1001234567890
TELEGRAM_MESSAGE_THREAD_ID=
TELEGRAM_UPDATES_ENABLED=true
THESPORTSDB_API_KEY=replace_me

# Optional: Separate chat for error alerts (defaults to TELEGRAM_CHAT_ID)
TELEGRAM_ERROR_CHAT_ID=-1001234567890

# Optional: Additional chat IDs for multi-group support (comma-separated)
TELEGRAM_EXTRA_CHAT_IDS=-1009876543210,-1005555555555

BOT_TIMEZONE=Europe/London
BOT_FONT_REGULAR=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
BOT_FONT_BOLD=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf

# Optional: coverage and sport routing
BOT_COVERAGE_PRESET=uk_sports
BOT_ENABLED_SPORTS=Soccer,Rugby,Rugby Union,Rugby League,Cricket,Tennis,Darts,Snooker,Golf,Motorsport,Formula 1,Boxing,MMA,American Football,Basketball,Baseball,Ice Hockey
BOT_ENABLED_LEAGUE_IDS=
BOT_COVERAGE_COUNTRIES=england,scotland,wales,northern_ireland,ireland,united_kingdom,europe,international,world,united_states
BOT_AUTO_ENABLE_DISCOVERED_LEAGUES=true
BOT_MAX_SCHEDULE_LEAGUES=80
BOT_MAX_LIVE_MATCHES_PER_SPORT=8
BOT_TELEGRAM_ROUTES_JSON='{"Rugby":[{"chat_id":"-1001234567890","thread_id":12}],"Basketball":["-1009876543210"]}'

# Optional: smart matchday burst cards
BOT_CARD_BURSTS_ENABLED=true
BOT_CARD_ROUTE_MODE=smart
BOT_CARD_TYPES_ENABLED=fixtures,kickoff_soon,live_now,tv_guide,results,tomorrow
BOT_CARD_BURST_MIN_FIXTURES=3
BOT_CARD_BURST_MIN_LIVE=2
BOT_CARD_BURST_MIN_RESULTS=3
BOT_CARD_BURST_COOLDOWN_MINUTES=60
BOT_CARD_MAX_PAGES_PER_RUN=12
BOT_CARD_MAX_SENDS_PER_RUN=12
BOT_CONTENT_PACKS_ENABLED=morning_planner,live_now,kickoff_soon,results,tv_now,tomorrow,weekend

# Optional: customer guide and follow buttons
BOT_CUSTOMER_GUIDE_ENABLED=true
BOT_CUSTOMER_GUIDE_TIME=09:00
BOT_CUSTOMER_GUIDE_LOOKAHEAD_HOURS=24
BOT_TEAM_WATCHLIST=Arsenal,England
BOT_PLAYER_WATCHLIST=
BOT_FOLLOW_BUTTONS_ENABLED=true
BOT_MAX_FOLLOW_BUTTONS=8

# Optional: render reliability and diagnostics
BOT_RENDER_ENGINE=auto
BOT_RENDER_CHROME_PATH=
BOT_RENDER_USER_DATA_DIR=/home/user/footballbot/cache/chrome
BOT_RENDER_EXTRA_ARGS=
BOT_SPORT_PROFILES_JSON=
BOT_HEALTH_ALERTS_ENABLED=true
BOT_HEALTH_ALERT_TIME=07:30

# Optional: pushed TV sports listings
BOT_TV_ENABLED=true
BOT_TV_CHANNELS=sky_sports_main_event,sky_sports_premier_league,sky_sports_football,tnt_sports_1,tnt_sports_2,eurosport_1,bbc_one,itv4
BOT_TV_SPORTS=Soccer,Darts,Rugby,Snooker
BOT_TV_DISCOVERY_COUNTRIES=united_kingdom,ireland
BOT_TV_DISCOVERY_DAYS_AHEAD=7
BOT_TV_DAILY_ALERTS=true
BOT_TV_SEND_IMAGE=true
BOT_TV_DAILY_ALERT_TIME=08:00
BOT_TV_LOOKAHEAD_HOURS=24
BOT_TV_INCLUDE_IN_PREVIEWS=true
BOT_TV_PREVIEW_REQUIRE_TV=false
BOT_TV_FOOTBALL_ONLY=false
```

Secure it:

```bash
chmod 600 config/footballbot.env
```

The bot also auto-loads `config/footballbot.env`, so cron and the admin UI can use the same configuration.

## Private Admin UI

The admin UI lives at:

```text
public/admin/index.php
```

Use it for:

- Creating the admin password on first run
- Saving Telegram and TheSportsDB keys (including error chat and extra groups)
- Sending a Telegram test image
- Writing a plain-text Telegram message from the bot
- Generating a real last-English-match test graphic with team badge lookups
- Generating sample graphics
- Running a dry live check
- Previewing and manually sending smart burst cards from Card Studio
- Monitoring pending, sent, and failed card jobs
- Viewing Telegram outbox delivery attempts and alert decision audit logs
- Running render diagnostics and health checks
- Reviewing sport profiles and route resolution by sport
- Retrying failed card dispatches without duplicating successful chat/page sends
- Sending a TV schedule test message
- Viewing bot and cron logs
- Alert breakdown by type with counts
- API rate-limit dashboard (last call, cache TTLs, cached entries)
- TV listing channel config, channel discovery, sport filters, daily guide controls, image/text output, and preview routing
- Coverage control centre for sports, leagues, live availability, fixture counts, and discovery
- Telegram route editor for per-sport groups
- Clearing API cache
- Resetting match state and sent-alert history

Security rules:

- Point your web server document root to `/home/user/footballbot/public`, never the project root.
- Put the admin panel behind HTTPS.
- Restrict by IP if possible.
- Use a long admin password.
- Do not expose `config/`, `cache/`, `logs/`, or `generated/` as public directories.

Example Nginx site:

```nginx
server {
    listen 80;
    server_name your-domain.example;
    root /home/user/footballbot/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Enable it:

```bash
sudo nano /etc/nginx/sites-available/footballbot
sudo ln -s /etc/nginx/sites-available/footballbot /etc/nginx/sites-enabled/footballbot
sudo nginx -t
sudo systemctl reload nginx
```

Then open:

```text
http://your-domain.example/admin/
```

Create the admin password, save your keys, generate samples, and send a Telegram test.

## Telegram Setup

1. Create a bot with BotFather and copy the token.
2. Add the bot to your private group.
3. Give the bot permission to post messages.
4. Get the group chat ID. Private supergroup IDs usually look like `-1001234567890`.
5. Put the token and chat ID in `config/footballbot.env`.

No commands are required by this bot after setup.

## Test Locally On VPS

Load env vars and generate sample images:

```bash
set -a
. /home/user/footballbot/config/footballbot.env
set +a
/usr/bin/php /home/user/footballbot/generate_image.php --sample
```

Run a dry poll. This fetches TheSportsDB data and generates any eligible images, but it does not post to Telegram and does not mutate alert state:

```bash
set -a
. /home/user/footballbot/config/footballbot.env
set +a
/usr/bin/php /home/user/footballbot/check_live.php --dry-run
```

Run a real poll:

```bash
set -a
. /home/user/footballbot/config/footballbot.env
set +a
/usr/bin/php /home/user/footballbot/check_live.php
```

Logs are written to:

```text
logs/bot.log
```

State is written to:

```text
cache/state.sqlite
```

Run the lightweight test suite:

```bash
/usr/bin/php tests/multisport_test.php
/usr/bin/php tests/card_scheduler_test.php
/usr/bin/php tests/v2_foundation_test.php
```

## Cron

Edit crontab:

```bash
crontab -e
```

Add:

```cron
*/2 * * * * set -a; . /home/user/footballbot/config/footballbot.env; set +a; /usr/bin/php /home/user/footballbot/check_live.php >> /home/user/footballbot/logs/cron.log 2>&1
```

Because `config/footballbot.env` is auto-loaded, this shorter cron also works:

```cron
*/2 * * * * /usr/bin/php /home/user/footballbot/check_live.php >> /home/user/footballbot/logs/cron.log 2>&1
```

This matches TheSportsDB premium two-minute livescore cadence and keeps polling efficient.

### Telegram Webhook Mode (Optional)

If you want button taps and Telegram messages handled instantly (instead of via `getUpdates` polling), enable webhook mode:

1. In `config/footballbot.env`:

```bash
TELEGRAM_WEBHOOK_ENABLED=true
TELEGRAM_WEBHOOK_SECRET_TOKEN=replace_with_random_secret
```

2. Deploy the public webhook endpoint:

```text
https://your-domain.example/telegram_webhook.php
```

3. Register webhook with Telegram:

```bash
curl -sS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook" \
  -d "url=https://your-domain.example/telegram_webhook.php" \
  -d "secret_token=$TELEGRAM_WEBHOOK_SECRET_TOKEN"
```

4. Verify webhook status:

```bash
curl -sS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getWebhookInfo"
```

To switch back to polling:

```bash
curl -sS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/deleteWebhook"
```

Then set:

```bash
TELEGRAM_WEBHOOK_ENABLED=false
```

## How Alert Detection Works

- The bot polls `/api/v2/json/livescore/all` and falls back to `/api/v2/json/livescore/soccer`.
- It filters matches by enabled sports and leagues. Soccer keeps the configured football league IDs and aliases by default.
- The previous score and status are read from SQLite.
- Soccer goal alerts are emitted only when the total score increases compared with stored state.
- Non-soccer live rows use generic match-start, score-update, period-change, and full-time alerts.
- When timeline data is available, the bot uses `lookup/event_timeline/{idEvent}` to attach scorer, assist, minute, side, and stable timeline alert keys.
- If timeline data is missing but the score increased, the bot still posts a fallback goal alert with "Scorer unavailable".
- Half-time and full-time alerts are state transitions, not repeated status reads.
- Red cards are sent once per unsent red-card timeline event.
- Yellow cards are sent once per unsent yellow-card timeline event.
- Substitutions are sent once per unsent substitution timeline event.
- Match previews are posted for upcoming fixtures within a configurable window (default 4 hours) before kick-off.
- The customer guide is posted once per local day after the configured guide time, combining live scores, fixtures, TV channels, and followed-team highlights.
- Follow buttons under guide messages are processed through `getUpdates` during cron runs unless webhook mode is enabled.
- When TV channels are configured, previews are enriched with matching TV channel names by `idEvent`.
- Event TV lookup is attempted with `/lookup/event_tv/{idEvent}` before falling back to configured channel listings.
- A daily TV guide is posted once per local day after the configured time, covering the configured channel list, sport filter, and lookahead window.
- TV guides can be sent as graphics so channel logos can appear when TheSportsDB includes `strLogo` in channel results.
- The admin panel can run TV channel discovery, which harvests channel IDs, slugs, sports, and logos from country, sport, and day TV listing endpoints when listings are available.
- Error alerts are sent to a separate Telegram chat when the bot encounters failures.
- First-seen mid-match goals, full-time states, red cards, yellow cards, and substitutions are suppressed by default to avoid posting stale alerts after a fresh deployment.

## TheSportsDB API Notes

The implementation uses the current TheSportsDB V2 base URL:

```text
https://www.thesportsdb.com/api/v2/json
```

V2 requests send the premium API key as:

```text
X-API-KEY: your_key
```

Endpoints used:

- `/livescore/all`
- `/livescore/soccer` fallback
- `/all/sports`
- `/all/leagues`
- `/lookup/event_timeline/{idEvent}`
- `/lookup/event_tv/{idEvent}`
- `/lookup/league/{idLeague}`
- `/lookup/player/{idPlayer}`
- `/schedule/next/league/{idLeague}` (for upcoming match previews)
- `/schedule/previous/league/{idLeague}` (for last-match test images)
- `/filter/tv/channel/{strChannel}` (for TV sports listings)

Reference docs:

- https://www.thesportsdb.com/documentation
- https://www.thesportsdb.com/docs_api_examples

## Fonts

The renderer searches in this order:

1. `BOT_FONT_REGULAR` and `BOT_FONT_BOLD`
2. Any `.ttf`, `.otf`, or `.ttc` file in `/fonts`
3. Ubuntu DejaVu or Liberation Sans
4. macOS Arial fallback
5. GD built-in fonts as a last resort

For best output on Ubuntu, install `fonts-dejavu-core`.

## Generated Images

Runtime alert images are written to `generated/` and old non-sample images are removed automatically after 24 hours. Sample images are named `sample_*.png` and are preserved.

To regenerate samples:

```bash
/usr/bin/php generate_image.php --sample
```

## Production Notes

- Keep `cache/`, `generated/`, and `logs/` writable by the cron user.
- Do not expose this directory through a public web server.
- Store secrets in environment variables or `config/footballbot.env`, not in source control.
- Monitor `logs/cron.log` and `logs/bot.log` after the first live match day.
- The cron job has a file lock, so slow API or Telegram responses will not create overlapping runs.
- Telegram posting is retried naturally by the next cron run if sending fails, because alert keys are marked sent only after successful `sendPhoto`.
- V2 alert and card delivery attempts are recorded in `telegram_outbox`, so successful chat deliveries can be skipped while failed chats remain visible for retry/diagnosis.
- If Puppeteer cannot run on the host, set `BOT_RENDER_ENGINE=gd` to force the built-in GD renderer, or set `BOT_RENDER_USER_DATA_DIR` to a writable bot-owned Chrome profile directory.
