#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

echo "== PHP syntax =="
find app/Plugins/SportsBot app/Core routes config -name '*.php' -print0 | xargs -0 -n 1 php -l >/dev/null
echo "PHP syntax OK"

echo
echo "== Required files =="
test -f app/Plugins/SportsBot/Services/SportsBotCardRenderer.php
test -f resources/sportsbot/v3-card-renderer.cjs
test -f package.json
test -f license_public.pem || echo "WARN license_public.pem missing; valid LARAVEL_CP_LICENSE may still work only if signed by bundled production key."
test -f storage/license_key || test -n "${LARAVEL_CP_LICENSE:-}" || echo "WARN no storage/license_key file and LARAVEL_CP_LICENSE env not exported in this shell."

echo
echo "== Required system packages =="
dpkg -l fonts-dejavu-core >/dev/null 2>&1 || echo "MISSING: fonts-dejavu-core (run: apt install fonts-dejavu-core)"
dpkg -l chromium >/dev/null 2>&1 || dpkg -l chromium-browser >/dev/null 2>&1 || echo "WARN: no Chromium package detected (check SPORTSBOT_CARD_CHROME_PATH if using puppeteer bundled)"

echo
echo "== Chrome system libraries =="
for lib in libnss3 libnspr4 libatk-bridge2.0-0 libatk1.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxrandr2 libgbm1; do
  dpkg -l "$lib" >/dev/null 2>&1 || echo "MISSING: $lib"
done

echo
echo "== Node.js =="
command -v node && node --version || echo "MISSING: Node.js (install: apt install nodejs or use NodeSource)"
command -v npm && npm --version || echo "MISSING: npm"

echo
echo "== Node dependencies =="
if [ -d node_modules/puppeteer ]; then
  echo "puppeteer: installed"
else
  echo "MISSING: puppeteer not installed (run: npm install)"
fi

echo
echo "== PHP extensions =="
php -m | grep -E '^(curl|fileinfo|gd|json|mbstring|openssl|pdo_mysql|xml|zip|bcmath|exif)$' | sort

echo
echo "== Laravel =="
php artisan optimize:clear
php artisan license:validate
php artisan migrate:status --no-interaction >/dev/null

echo
echo "== Storage permissions =="
for dir in storage/app/sportsbot/cards storage/app/sportsbot/render-input storage/app/sportsbot/assets storage/logs bootstrap/cache; do
  if [ -d "$dir" ] && [ -w "$dir" ]; then
    echo "OK: $dir"
  else
    echo "PROBLEM: $dir (missing or not writable)"
  fi
done

echo
echo "== SportsBot =="
php artisan sportsbot:health --fix --render

echo
echo "== Scheduler =="
php artisan schedule:list

echo
echo "Production check complete."
