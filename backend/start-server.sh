#!/bin/bash
# Laravel Development Server Keeper
# This script ensures the Laravel server stays running

# Use the project root (where this script lives) by default
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${LARAVEL_APP_DIR:-$SCRIPT_DIR}"
SERVER_HOST="${SERVER_HOST:-0.0.0.0}"
SERVER_PORT="${SERVER_PORT:-8001}"

cd "$APP_DIR"

# Kill any existing Laravel server
pkill -f "php artisan serve" || true

# Start server in background with nohup
nohup php artisan serve --host="$SERVER_HOST" --port="$SERVER_PORT" > "$APP_DIR/storage/logs/server.log" 2>&1 &

echo "Laravel server started in background (PID: $!)"
echo "Logs: $APP_DIR/storage/logs/server.log"
echo "URL: http://localhost:$SERVER_PORT"
