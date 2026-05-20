#!/usr/bin/env bash
set -uo pipefail

cd "$(dirname "$0")/.."

INSTALL=0
REPAIR_PERMISSIONS=0
STRICT_WARNINGS=0
DISCORD_BOT=0
HELPER="/usr/local/bin/sportsbot-fix-permissions"

for arg in "$@"; do
  case "$arg" in
    --install)
      INSTALL=1
      REPAIR_PERMISSIONS=1
      ;;
    --repair-permissions)
      REPAIR_PERMISSIONS=1
      ;;
    --strict)
      STRICT_WARNINGS=1
      ;;
    --discord-bot)
      DISCORD_BOT=1
      ;;
    -h|--help)
      cat <<'USAGE'
SportsBot live install/verification script.

Run from anywhere inside the deployed backend checkout:
  bash scripts/sportsbot-production-check.sh

Options:
  --install              Run composer/npm install, build admin assets, migrate, cache config, and repair permissions.
  --repair-permissions   Run the hardcoded sudo permission helper.
  --strict               Treat warnings as a failed verification.
  --discord-bot          Require Discord bot-token/channel-map readiness in SportsBot health.

This script never runs raw chown/chmod. Permission repair uses:
  sudo /usr/local/bin/sportsbot-fix-permissions
USAGE
      exit 0
      ;;
    *)
      echo "Unknown option: $arg"
      exit 2
      ;;
  esac
done

PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

say_section() {
  echo
  echo "== $1 =="
}

pass() {
  PASS_COUNT=$((PASS_COUNT + 1))
  echo "PASS: $1"
}

warn() {
  WARN_COUNT=$((WARN_COUNT + 1))
  echo "WARN: $1"
}

fail() {
  FAIL_COUNT=$((FAIL_COUNT + 1))
  echo "FAIL: $1"
}

have() {
  command -v "$1" >/dev/null 2>&1
}

run_required() {
  local label="$1"
  shift

  echo "\$ $*"
  if "$@"; then
    pass "$label"
  else
    fail "$label"
  fi
}

run_optional() {
  local label="$1"
  shift

  echo "\$ $*"
  if "$@"; then
    pass "$label"
  else
    warn "$label"
  fi
}

check_file() {
  if [ -f "$1" ]; then
    pass "file exists: $1"
  else
    fail "missing file: $1"
  fi
}

check_dir_writable() {
  if [ -d "$1" ] && [ -w "$1" ]; then
    pass "writable: $1"
  else
    fail "missing or not writable: $1"
  fi
}

php_version_id() {
  php -r 'echo PHP_VERSION_ID;' 2>/dev/null
}

version_at_least() {
  local actual="$1"
  local minimum="$2"
  [ "$actual" -ge "$minimum" ]
}

detect_chrome() {
  node -e "const fs=require('fs');let p;try{p=require('puppeteer')}catch(e){try{p=require('puppeteer-core')}catch(e2){p=null}}const candidates=[process.env.SPORTSBOT_CARD_CHROME_PATH,process.env.PUPPETEER_EXECUTABLE_PATH,'/usr/bin/chromium','/usr/bin/chromium-browser','/usr/bin/google-chrome','/usr/bin/google-chrome-stable'];let exe=candidates.find(v=>v&&fs.existsSync(v));if(!exe&&p&&p.executablePath){try{const guessed=p.executablePath();if(guessed&&fs.existsSync(guessed))exe=guessed}catch(e){}}if(!exe)process.exit(1);console.log(exe)"
}

say_section "Project"
echo "Backend path: $(pwd)"
check_file "artisan"
check_file "composer.json"
check_file "composer.lock"
check_file "package.json"
check_file "package-lock.json"
check_file "resources/admin/package.json"
check_file "resources/admin/package-lock.json"
check_file "app/Plugins/SportsBot/Services/SportsBotCardRenderer.php"
check_file "resources/sportsbot/v3-card-renderer.cjs"
check_file "app/Plugins/SportsBot/database/migrations/2026_05_19_120000_add_media_render_diagnostics_to_sportsbot_fixture_queue.php"

if have git && [ -d "../.git" ]; then
  echo "Git branch: $(git -C .. rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
  echo "Git commit: $(git -C .. rev-parse --short HEAD 2>/dev/null || echo unknown)"
fi

say_section "PHP and extensions"
if have php; then
  PHP_ID="$(php_version_id)"
  echo "PHP: $(php -v | head -n 1)"
  if version_at_least "$PHP_ID" 80200; then
    pass "PHP version is 8.2 or newer"
  else
    fail "PHP 8.2+ required; current PHP_VERSION_ID=$PHP_ID"
  fi
else
  fail "php binary missing"
fi

for ext in bcmath curl exif fileinfo gd json mbstring openssl pdo pdo_mysql xml zip; do
  if php -r "exit(extension_loaded('${ext}') ? 0 : 1);" 2>/dev/null; then
    pass "PHP extension: $ext"
  else
    fail "PHP extension missing: $ext"
  fi
done

say_section "Composer"
if have composer; then
  echo "Composer: $(composer --version 2>/dev/null | head -n 1)"
  run_required "Composer platform requirements match this server" composer check-platform-reqs --no-interaction
  if [ "$INSTALL" -eq 1 ]; then
    run_required "Composer install from lockfile" composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  elif [ -f "vendor/autoload.php" ]; then
    pass "vendor/autoload.php exists"
  else
    fail "vendor/autoload.php missing; run with --install"
  fi
else
  fail "composer binary missing"
fi

say_section "Node, NPM, and Puppeteer"
if have node; then
  echo "Node: $(node --version)"
  pass "node binary available"
else
  fail "node binary missing"
fi

if have npm; then
  echo "NPM: $(npm --version)"
  pass "npm binary available"
else
  fail "npm binary missing"
fi

if [ "$INSTALL" -eq 1 ] && have npm; then
  run_required "Backend npm install from package-lock" npm ci
fi

if [ -d "node_modules/puppeteer" ] || [ -d "node_modules/puppeteer-core" ]; then
  pass "Puppeteer package installed"
else
  fail "Puppeteer package missing; run npm ci in backend or use --install"
fi

if have node; then
  run_required "Puppeteer package loadable by Node" node -e "try{const p=require('puppeteer');console.log('puppeteer '+require('puppeteer/package.json').version)}catch(e){const p=require('puppeteer-core');console.log('puppeteer-core '+require('puppeteer-core/package.json').version)}"
  if detect_chrome >/tmp/sportsbot-chrome-path.txt 2>/tmp/sportsbot-chrome-error.txt; then
    pass "Chrome/Chromium executable found: $(cat /tmp/sportsbot-chrome-path.txt)"
  else
    fail "Chrome/Chromium executable missing; install chromium or set SPORTSBOT_CARD_CHROME_PATH/PUPPETEER_EXECUTABLE_PATH"
  fi
fi

say_section "Chrome system libraries"
if have dpkg; then
  for lib in libnss3 libnspr4 libatk-bridge2.0-0 libatk1.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libgbm1; do
    if dpkg -l "$lib" >/dev/null 2>&1; then
      pass "system package: $lib"
    else
      fail "system package missing: $lib"
    fi
  done
else
  warn "dpkg not available; skipped Debian Chrome library package checks"
fi

say_section "Admin frontend"
if [ "$INSTALL" -eq 1 ] && have npm; then
  pushd resources/admin >/dev/null || exit 1
  run_required "Admin npm install from package-lock" npm ci --include=dev
  run_required "Admin frontend build" npm run build
  popd >/dev/null || exit 1
else
  check_file "public/admin/index.html"
  if find public/admin/assets -maxdepth 1 -type f -name 'index-*.js' | grep -q .; then
    pass "admin built JS asset exists"
  else
    fail "admin built JS asset missing; run with --install"
  fi
fi

say_section "Laravel and database"
if [ "$INSTALL" -eq 1 ]; then
  run_required "Run database migrations" php artisan migrate --force
fi

if MIGRATION_STATUS="$(php artisan migrate:status --no-interaction 2>&1)"; then
  if echo "$MIGRATION_STATUS" | grep -q "Pending"; then
    echo "$MIGRATION_STATUS"
    fail "pending migrations found; run php artisan migrate --force or use --install"
  else
    pass "no pending migrations"
  fi
else
  echo "$MIGRATION_STATUS"
  fail "could not read migration status"
fi

HEALTH_FLAGS=(--json --fix --render)
if [ "$DISCORD_BOT" -eq 1 ]; then
  HEALTH_FLAGS+=(--discord-bot)
fi

run_required "SportsBot health and Browser v3 render smoke test" php artisan sportsbot:health "${HEALTH_FLAGS[@]}"

if [ "$INSTALL" -eq 1 ]; then
  run_required "Clear optimized Laravel caches" php artisan optimize:clear
  run_required "Cache Laravel config" php artisan config:cache
else
  run_optional "Laravel config/cache clear smoke" php artisan optimize:clear
fi

say_section "Storage and permissions"
for dir in storage/app/sportsbot/cards storage/app/sportsbot/render-input storage/app/sportsbot/assets storage/app/sportsbot/render-debug storage/logs bootstrap/cache; do
  check_dir_writable "$dir"
done

if sudo -n -l "$HELPER" >/dev/null 2>&1; then
  pass "sudoers allows helper: sudo $HELPER"
else
  warn "could not confirm sudoers helper access with sudo -n -l $HELPER"
fi

if [ "$REPAIR_PERMISSIONS" -eq 1 ]; then
  run_required "Repair Laravel writable permissions with sudo helper" sudo "$HELPER"
fi

say_section "Scheduler"
run_optional "Laravel scheduler list" php artisan schedule:list

say_section "Environment audit report"
if [ -x "scripts/sportsbot-environment-audit.sh" ]; then
  AUDIT_DIR="storage/app/sportsbot/env"
  AUDIT_PATH="${AUDIT_DIR}/live-$(date +%Y%m%d-%H%M%S).json"
  mkdir -p "$AUDIT_DIR"
  AUDIT_FLAGS=(--with-health --write "$AUDIT_PATH")
  if [ "$DISCORD_BOT" -eq 1 ]; then
    AUDIT_FLAGS+=(--discord-bot)
  fi
  if scripts/sportsbot-environment-audit.sh "${AUDIT_FLAGS[@]}" >/dev/null; then
    pass "wrote environment audit report: $AUDIT_PATH"
  else
    warn "environment audit report failed"
  fi
else
  warn "scripts/sportsbot-environment-audit.sh is missing or not executable"
fi

say_section "Summary"
echo "PASS: $PASS_COUNT"
echo "WARN: $WARN_COUNT"
echo "FAIL: $FAIL_COUNT"

if [ "$FAIL_COUNT" -gt 0 ]; then
  echo "SportsBot production verification failed."
  exit 1
fi

if [ "$STRICT_WARNINGS" -eq 1 ] && [ "$WARN_COUNT" -gt 0 ]; then
  echo "SportsBot production verification failed because --strict treats warnings as failures."
  exit 1
fi

echo "SportsBot production verification passed."
