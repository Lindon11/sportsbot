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
test -f license_public.pem || echo "WARN license_public.pem missing; valid LARAVEL_CP_LICENSE may still work only if signed by bundled production key."
test -f storage/license_key || test -n "${LARAVEL_CP_LICENSE:-}" || echo "WARN no storage/license_key file and LARAVEL_CP_LICENSE env not exported in this shell."

echo
echo "== PHP extensions =="
php -m | grep -E '^(curl|fileinfo|gd|json|mbstring|openssl|pdo_mysql|xml|zip)$' | sort

echo
echo "== Laravel =="
php artisan optimize:clear
php artisan license:validate
php artisan migrate:status --no-interaction >/dev/null

echo
echo "== SportsBot =="
php artisan sportsbot:health --fix --render

echo
echo "== Scheduler =="
php artisan schedule:list

echo
echo "Production check complete."
