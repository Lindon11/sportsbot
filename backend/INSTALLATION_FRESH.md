LaravelCP — Fresh Installation Guide
=====================================

This document explains a minimal, repeatable set of steps to install a fresh copy of LaravelCP on a server for development or production. Adjust paths, users and service names for your environment (Plesk, systemd, cPanel, etc.).

Prerequisites
- PHP 8.1+ (8.2 recommended) with extensions: OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, GD/Imagick if needed.
- Composer 2.x
- MySQL / MariaDB
- Node.js 16+ (for frontend build) + npm or pnpm
- Web server: nginx (recommended) or Apache with PHP-FPM
- git

Quick install (clone + app)
1. Clone the repo:

   git clone https://github.com/<your-org>/LaravelCP.git cp && cd cp

2. Install PHP dependencies:

   composer install --no-dev --optimize-autoloader

3. Create environment config:

   cp .env.example .env
   # Edit .env: set DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
   # Also set APP_URL, APP_ENV, APP_DEBUG=false and license/public keys locations if needed

4. Generate application key:

   php artisan key:generate

5. File/folder permissions (example, adjust user/group):

   sudo chown -R www-data:www-data storage bootstrap/cache
   sudo chmod -R 775 storage bootstrap/cache

Database & seeders
1. Run migrations and seed the default data (creates admin if included):

   php artisan migrate --force
   php artisan db:seed --class=DefaultAdminSeeder --force || php artisan db:seed --force

Default Admin Credentials
- Username: admin
- Password: admin123

Notes:
- The default admin user is created by the `DefaultAdminSeeder` (or via `create_admin.php`).
- For security, change the password immediately after first login or avoid enabling the default seeder in production.

License keys
- Place your `license_public.pem` in the backend project root, beside `artisan`, unless your licence was signed by the bundled production key. The stored licence is read from `LARAVEL_CP_LICENSE` or `storage/license_key`. If you need to generate a key for the installer, follow the project's license docs.

Cache / Session drivers
- For a simple installation, use file drivers in `.env`:

  CACHE_DRIVER=file
  SESSION_DRIVER=file
  QUEUE_CONNECTION=sync

- If you use `database` drivers for session/cache/queue, ensure the corresponding tables exist and you restart PHP-FPM workers after changing `.env`.

Frontend (admin SPA)
1. Build admin assets (Vite):

   cd resources/admin
   npm install
   npm run build

2. Copy or deploy build output according to your webserver setup (public directory or mounted asset path used by the Laravel view).

Clearing caches & compiled config
- After editing `.env` or config files, clear caches and ensure PHP workers are restarted:

  php artisan config:clear
  php artisan cache:clear
  php artisan route:clear
  php artisan view:clear

Web server & PHP-FPM
- Restart the php-fpm pool used by the site so running workers pick up `.env` changes. Common commands:

  sudo systemctl restart php8.2-fpm
  sudo systemctl restart plesk-php82-fpm
  sudo systemctl restart nginx

- If your site is fronted by nginx and uses a Plesk-managed FPM pool (`/opt/plesk/php/...`), restart the matching `plesk-phpXX-fpm` service.

Troubleshooting
- Error: "Driver [database] not supported." — means a running PHP process is trying to use a driver Laravel doesn't have configured. Fixes:
  - Ensure `.env` has valid `CACHE_DRIVER` / `SESSION_DRIVER` values.
  - Remove `bootstrap/cache/config.php` and run `php artisan config:clear`.
  - Restart the php-fpm service used by nginx/Apache so workers load the new `.env`.

- SSL name mismatch when testing with `curl`: either use the correct hostname in the certificate or use `curl -k` only for quick tests.

Optional production steps
- Configure HTTPS (Let's Encrypt or your CA).
- Set up supervisor for queue workers.
- Configure a process to rotate logs and back up the DB.
- Harden permissions and disable debug mode.

If you want, I can:
- Replace the existing [INSTALLATION.md](INSTALLATION.md) with this content and commit the change.
- Open a PR containing the new/install docs.

Please tell me which you'd like me to do next (replace file + commit, create a new file in the repo, or open a PR).
