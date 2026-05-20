<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class EnvSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        if ($response = $this->adminOnlyResponse()) {
            return $response;
        }

        $values = $this->readEnvValues();
        $groups = $this->groups();

        foreach ($groups as &$group) {
            foreach ($group['fields'] as &$field) {
                $key = $field['key'];
                $current = $values[$key] ?? '';

                $field['exists'] = array_key_exists($key, $values);
                $field['configured'] = trim((string) $current) !== '';
                $field['value'] = !empty($field['secret']) ? '' : $current;
            }
        }

        return response()->json([
            'success' => true,
            'env_path' => base_path('.env'),
            'writable' => $this->envWritable(),
            'groups' => $groups,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->adminOnlyResponse()) {
            return $response;
        }

        if (!$this->envWritable()) {
            return response()->json([
                'success' => false,
                'message' => '.env is not writable by the web server user.',
            ], 422);
        }

        $definitions = $this->fieldDefinitions();
        $payload = $request->validate([
            'values' => 'required|array',
            'values.*' => ['nullable'],
        ])['values'];

        $current = $this->readEnvValues();
        $updates = [];

        foreach ($payload as $key => $value) {
            if (!isset($definitions[$key])) {
                continue;
            }

            $definition = $definitions[$key];

            if (!empty($definition['read_only'])) {
                continue;
            }

            if (!empty($definition['secret']) && trim((string) $value) === '' && !empty($current[$key])) {
                continue;
            }

            $updates[$key] = $this->normalizeValue($value, $definition);
        }

        if ($updates === []) {
            return response()->json([
                'success' => true,
                'message' => 'No environment changes to save.',
            ]);
        }

        $this->updateEnvFile($updates);

        Artisan::call('config:clear');
        Artisan::call('cache:clear');

        return response()->json([
            'success' => true,
            'message' => 'Environment settings saved. Config and application cache were cleared.',
        ]);
    }

    private function envWritable(): bool
    {
        $envFile = base_path('.env');

        if (File::exists($envFile)) {
            return is_writable($envFile);
        }

        return is_writable(base_path());
    }

    private function readEnvValues(): array
    {
        $envFile = base_path('.env');

        if (!File::exists($envFile)) {
            return [];
        }

        $values = [];
        $lines = preg_split('/\R/', File::get($envFile)) ?: [];

        foreach ($lines as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $matches)) {
                continue;
            }

            $values[$matches[1]] = $this->unquoteEnvValue($matches[2]);
        }

        return $values;
    }

    private function unquoteEnvValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $first = $value[0];
        $last = substr($value, -1);

        if ($first === '"' && $last === '"') {
            return str_replace(['\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        if ($first === "'" && $last === "'") {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function updateEnvFile(array $updates): void
    {
        $envFile = base_path('.env');

        if (!File::exists($envFile)) {
            File::copy(base_path('.env.example'), $envFile);
        }

        File::copy($envFile, $envFile . '.backup.' . now()->format('YmdHis'));

        $content = File::get($envFile);

        foreach ($updates as $key => $value) {
            $line = $key . '=' . $this->quoteEnvValue($value);

            if (preg_match('/^' . preg_quote($key, '/') . '=/m', $content)) {
                $content = preg_replace_callback(
                    '/^' . preg_quote($key, '/') . '=.*/m',
                    fn () => $line,
                    $content
                );
            } else {
                $content = rtrim($content) . PHP_EOL . $line . PHP_EOL;
            }
        }

        File::put($envFile, $content);
    }

    private function quoteEnvValue(string $value): string
    {
        $value = str_replace(["\n", "\r"], '', $value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.,:\/@{}?&=+\- ]+$/', $value) && !str_contains($value, '#')) {
            return str_contains($value, ' ') ? '"' . str_replace('"', '\"', $value) . '"' : $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
    }

    private function normalizeValue(mixed $value, array $definition): string
    {
        if (($definition['type'] ?? 'text') === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        if (($definition['type'] ?? 'text') === 'number') {
            return is_numeric($value) ? (string) $value : (string) ($definition['default'] ?? 0);
        }

        if (($definition['type'] ?? 'text') === 'textarea') {
            $parts = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $value) ?: []));

            return $parts === [] ? trim((string) $value) : implode(',', $parts);
        }

        return trim((string) $value);
    }

    private function adminOnlyResponse(): ?JsonResponse
    {
        $user = request()->user();

        if ($user && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Only admins can manage live environment settings.',
        ], 403);
    }

    private function fieldDefinitions(): array
    {
        $definitions = [];

        foreach ($this->groups() as $group) {
            foreach ($group['fields'] as $field) {
                $definitions[$field['key']] = $field;
            }
        }

        return $definitions;
    }

    private function groups(): array
    {
        return [
            [
                'id' => 'app',
                'label' => 'App',
                'description' => 'Core production values that affect URLs, debug output, sessions and queues.',
                'fields' => [
                    $this->field('APP_NAME', 'App Name'),
                    $this->field('APP_ENV', 'App Environment', 'select', ['options' => ['production', 'local', 'staging']]),
                    $this->field('APP_DEBUG', 'Debug Mode', 'boolean', ['warning' => 'Keep this false on live servers.']),
                    $this->field('APP_URL', 'App URL'),
                    $this->field('FRONTEND_URL', 'Frontend URL'),
                    $this->field('APP_SCHEDULER_HTTP_TOKEN', 'HTTP Scheduler Token', 'text', ['secret' => true, 'warning' => 'Set a long random value if you want to trigger Laravel Scheduler from cPanel or an external cron URL.']),
                    $this->field('APP_TIMEZONE', 'Timezone'),
                    $this->field('APP_LOCALE', 'Locale'),
                    $this->field('APP_FALLBACK_LOCALE', 'Fallback Locale'),
                    $this->field('APP_FAKER_LOCALE', 'Faker Locale'),
                    $this->field('APP_KEY', 'App Key', 'text', ['secret' => true, 'read_only' => true, 'warning' => 'Changing this can invalidate encrypted data and sessions.']),
                    $this->field('LOG_LEVEL', 'Log Level', 'select', ['options' => ['debug', 'info', 'notice', 'warning', 'error', 'critical']]),
                    $this->field('CACHE_STORE', 'Cache Store', 'select', ['options' => ['database', 'file', 'redis']]),
                    $this->field('QUEUE_CONNECTION', 'Queue Connection', 'select', ['options' => ['sync', 'database', 'redis']]),
                    $this->field('SESSION_DRIVER', 'Session Driver', 'select', ['options' => ['database', 'file', 'redis', 'cookie']]),
                    $this->field('SESSION_LIFETIME', 'Session Lifetime', 'number'),
                    $this->field('SESSION_ENCRYPT', 'Encrypt Sessions', 'boolean'),
                    $this->field('SESSION_DOMAIN', 'Session Domain'),
                    $this->field('CORS_ALLOWED_ORIGINS', 'CORS Allowed Origins', 'textarea'),
                    $this->field('SANCTUM_STATEFUL_DOMAINS', 'Sanctum Stateful Domains', 'textarea'),
                ],
            ],
            [
                'id' => 'services',
                'label' => 'Services',
                'description' => 'Redis, storage, broadcasting and cloud storage values.',
                'fields' => [
                    $this->field('BROADCAST_CONNECTION', 'Broadcast Connection', 'select', ['options' => ['log', 'reverb', 'pusher', 'redis', 'null']]),
                    $this->field('FILESYSTEM_DISK', 'Filesystem Disk', 'select', ['options' => ['local', 'public', 's3']]),
                    $this->field('MEMCACHED_HOST', 'Memcached Host'),
                    $this->field('REDIS_CLIENT', 'Redis Client', 'select', ['options' => ['phpredis', 'predis']]),
                    $this->field('REDIS_HOST', 'Redis Host'),
                    $this->field('REDIS_PASSWORD', 'Redis Password', 'text', ['secret' => true]),
                    $this->field('REDIS_PORT', 'Redis Port', 'number'),
                    $this->field('AWS_ACCESS_KEY_ID', 'AWS Access Key ID'),
                    $this->field('AWS_SECRET_ACCESS_KEY', 'AWS Secret Access Key', 'text', ['secret' => true]),
                    $this->field('AWS_DEFAULT_REGION', 'AWS Region'),
                    $this->field('AWS_BUCKET', 'AWS Bucket'),
                    $this->field('AWS_USE_PATH_STYLE_ENDPOINT', 'AWS Path Style Endpoint', 'boolean'),
                ],
            ],
            [
                'id' => 'database',
                'label' => 'Database',
                'description' => 'Only change these when you are moving the live database connection.',
                'warning' => 'Wrong database values can take the site offline.',
                'fields' => [
                    $this->field('DB_CONNECTION', 'Connection', 'select', ['options' => ['mysql', 'mariadb', 'pgsql', 'sqlite']]),
                    $this->field('DB_HOST', 'Host'),
                    $this->field('DB_PORT', 'Port', 'number'),
                    $this->field('DB_DATABASE', 'Database'),
                    $this->field('DB_USERNAME', 'Username'),
                    $this->field('DB_PASSWORD', 'Password', 'text', ['secret' => true]),
                ],
            ],
            [
                'id' => 'mail',
                'label' => 'Mail',
                'description' => 'SMTP and Mailgun settings used by outgoing mail.',
                'fields' => [
                    $this->field('MAIL_MAILER', 'Mailer', 'select', ['options' => ['log', 'smtp', 'mailgun']]),
                    $this->field('MAIL_HOST', 'SMTP Host'),
                    $this->field('MAIL_PORT', 'SMTP Port', 'number'),
                    $this->field('MAIL_USERNAME', 'SMTP Username'),
                    $this->field('MAIL_PASSWORD', 'SMTP Password', 'text', ['secret' => true]),
                    $this->field('MAIL_FROM_ADDRESS', 'From Address'),
                    $this->field('MAIL_FROM_NAME', 'From Name'),
                    $this->field('MAILGUN_DOMAIN', 'Mailgun Domain'),
                    $this->field('MAILGUN_SECRET', 'Mailgun Secret', 'text', ['secret' => true]),
                    $this->field('MAILGUN_ENDPOINT', 'Mailgun Endpoint'),
                ],
            ],
            [
                'id' => 'sportsbot_core',
                'label' => 'SportsBot Core',
                'description' => 'Provider, live score and general SportsBot controls.',
                'fields' => [
                    $this->field('SPORTSBOT_ENABLED', 'Enabled', 'boolean'),
                    $this->field('SPORTSBOT_SEND_MESSAGES', 'Send Messages', 'boolean'),
                    $this->field('SPORTSBOT_SCHEDULE_ENABLED', 'Legacy Schedule Enabled', 'boolean'),
                    $this->field('SPORTSBOT_SCHEDULE_FREQUENCY', 'Legacy Schedule Frequency'),
                    $this->field('SPORTSBOT_PROVIDER', 'Provider'),
                    $this->field('SPORTSBOT_THESPORTSDB_BASE_URL', 'TheSportsDB Base URL'),
                    $this->field('SPORTSBOT_THESPORTSDB_API_KEY', 'TheSportsDB API Key', 'text', ['secret' => true]),
                    $this->field('SPORTSBOT_HTTP_TIMEOUT', 'HTTP Timeout', 'number'),
                    $this->field('SPORTSBOT_HTTP_CONNECT_TIMEOUT', 'HTTP Connect Timeout', 'number'),
                    $this->field('SPORTSBOT_LIVE_SCORE_CACHE_TTL', 'Live Score Cache TTL', 'number'),
                    $this->field('SPORTSBOT_ENABLED_SPORTS', 'Enabled Sports', 'textarea'),
                    $this->field('SPORTSBOT_ALLOWED_LEAGUE_IDS', 'Allowed League IDs', 'textarea'),
                    $this->field('SPORTSBOT_MAX_LIVE_MATCHES_PER_RUN', 'Max Live Matches Per Run', 'number'),
                ],
            ],
            [
                'id' => 'sportsbot_messaging',
                'label' => 'Messaging',
                'description' => 'Telegram and Discord delivery settings.',
                'fields' => [
                    $this->field('SPORTSBOT_TELEGRAM_BOT_TOKEN', 'Telegram Bot Token', 'text', ['secret' => true]),
                    $this->field('SPORTSBOT_TELEGRAM_CHAT_ID', 'Telegram Chat ID'),
                    $this->field('SPORTSBOT_TELEGRAM_MESSAGE_THREAD_ID', 'Telegram Default Thread ID'),
                    $this->field('SPORTSBOT_TELEGRAM_EXTRA_CHAT_IDS', 'Telegram Extra Chat IDs', 'textarea'),
                    $this->field('SPORTSBOT_TELEGRAM_PARSE_MODE', 'Telegram Parse Mode', 'select', ['options' => ['HTML', 'MarkdownV2', 'Markdown']]),
                    $this->field('SPORTSBOT_TELEGRAM_DISABLE_NOTIFICATION', 'Telegram Silent Messages', 'boolean'),
                    $this->field('SPORTSBOT_TELEGRAM_WEBHOOK_ENABLED', 'Telegram Webhook Enabled', 'boolean'),
                    $this->field('SPORTSBOT_TELEGRAM_WEBHOOK_SECRET', 'Telegram Webhook Secret', 'text', ['secret' => true]),
                    $this->field('SPORTSBOT_DISCORD_ENABLED', 'Discord Enabled', 'boolean'),
                    $this->field('SPORTSBOT_DISCORD_BOT_TOKEN', 'Discord Bot Token', 'text', ['secret' => true, 'warning' => 'Bot-token mode takes priority over Discord webhooks.']),
                    $this->field('SPORTSBOT_DISCORD_DEFAULT_CHANNEL_ID', 'Discord Default Channel ID'),
                    $this->field('SPORTSBOT_DISCORD_BOT_CHANNELS_JSON', 'Discord Route Channels JSON', 'textarea', ['warning' => 'JSON map of SportsBot route keys to Discord channel IDs, for example {"FORMULA_1":"123","default":"456"}.']),
                    $this->field('SPORTSBOT_DISCORD_WEBHOOK_URL', 'Discord Default Webhook URL', 'text', ['secret' => true]),
                    $this->field('SPORTSBOT_DISCORD_USERNAME', 'Discord Username'),
                    $this->field('SPORTSBOT_DISCORD_AVATAR_URL', 'Discord Avatar URL'),
                ],
            ],
            [
                'id' => 'sportsbot_tv',
                'label' => 'TV & Scrapers',
                'description' => 'TV guide, search and scraper source settings.',
                'fields' => [
                    $this->field('SPORTSBOT_TV_ENABLED', 'TV Guide Enabled', 'boolean'),
                    $this->field('SPORTSBOT_TV_CHANNELS', 'TV Channels', 'textarea'),
                    $this->field('SPORTSBOT_TV_SPORTS', 'TV Sports', 'textarea'),
                    $this->field('SPORTSBOT_TV_LOOKAHEAD_HOURS', 'TV Lookahead Hours', 'number'),
                    $this->field('SPORTSBOT_TV_MAX_EVENTS_PER_CHANNEL', 'TV Max Events Per Channel', 'number'),
                    $this->field('SPORTSBOT_TV_GUIDE_MAX_PER_CHANNEL', 'TV Guide Max Per Channel', 'number'),
                    $this->field('SPORTSBOT_TV_GUIDE_SHOW_EMPTY_CHANNELS', 'Show Empty TV Channels', 'boolean'),
                    $this->field('SPORTSBOT_TV_CACHE_TTL', 'TV Cache TTL', 'number'),
                    $this->field('SPORTSBOT_SCRAPERS_ENABLED', 'Scrapers Enabled', 'boolean'),
                    $this->field('SPORTSBOT_SCRAPER_SEARCH_ENABLED', 'Search Enabled', 'boolean'),
                    $this->field('SPORTSBOT_SCRAPER_SEARCH_URLS', 'Search URLs', 'textarea'),
                    $this->field('SPORTSBOT_SCRAPER_SEARCH_MAX_RESULTS', 'Search Max Results', 'number'),
                    $this->field('SPORTSBOT_SCRAPER_AUTO_USE_CONFIDENCE', 'Auto Use Confidence', 'number'),
                    $this->field('SPORTSBOT_COMBAT_POSTER_URLS', 'Combat Poster URLs', 'textarea'),
                    $this->field('SPORTSBOT_BROADCAST_SCHEDULE_URLS', 'Broadcast Schedule URLs', 'textarea'),
                    $this->field('SPORTSBOT_F1_SCHEDULE_URLS', 'F1 Schedule URLs', 'textarea'),
                    $this->field('SPORTSBOT_COMBAT_POSTER_SEARCH_QUERIES', 'Combat Search Queries', 'textarea'),
                    $this->field('SPORTSBOT_BROADCAST_SCHEDULE_SEARCH_QUERIES', 'Broadcast Search Queries', 'textarea'),
                    $this->field('SPORTSBOT_F1_SCHEDULE_SEARCH_QUERIES', 'F1 Search Queries', 'textarea'),
                ],
            ],
            [
                'id' => 'sportsbot_schedule',
                'label' => 'Schedules',
                'description' => 'Autopilot and publishing schedule values.',
                'fields' => [
                    $this->field('SPORTSBOT_FIXTURES_TODAY_SCHEDULE_ENABLED', 'Fixtures Today Schedule', 'boolean'),
                    $this->field('SPORTSBOT_FIXTURES_TODAY_SCHEDULE_TIME', 'Fixtures Today Time'),
                    $this->field('SPORTSBOT_TV_GUIDE_SCHEDULE_ENABLED', 'TV Guide Schedule', 'boolean'),
                    $this->field('SPORTSBOT_TV_GUIDE_SCHEDULE_TIME', 'TV Guide Time'),
                    $this->field('SPORTSBOT_LIVE_NOW_SCHEDULE_ENABLED', 'Live Now Schedule', 'boolean'),
                    $this->field('SPORTSBOT_LIVE_NOW_SCHEDULE_FREQUENCY', 'Live Now Frequency'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_SCHEDULE_ENABLED', 'Fixture Queue Schedule', 'boolean'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_PREFETCH_ENABLED', 'Queue Prefetch Enabled', 'boolean'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_PREFETCH_TIME', 'Queue Prefetch Time'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_ENRICH_ENABLED', 'Queue Enrich Enabled', 'boolean'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_ENRICH_FREQUENCY', 'Queue Enrich Frequency'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_ENRICH_DAYS', 'Queue Enrich Days', 'number'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_ENRICH_LIMIT', 'Queue Enrich Limit', 'number'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_RENDER_ENABLED', 'Queue Render Enabled', 'boolean'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_RENDER_FREQUENCY', 'Queue Render Frequency'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_ENABLED', 'Queue Publish Enabled', 'boolean'),
                    $this->field('SPORTSBOT_FIXTURE_QUEUE_PUBLISH_FREQUENCY', 'Queue Publish Frequency'),
                ],
            ],
            [
                'id' => 'sportsbot_cards',
                'label' => 'Cards',
                'description' => 'Rich card rendering, browser and font settings.',
                'fields' => [
                    $this->field('SPORTSBOT_CARDS_ENABLED', 'Cards Enabled', 'boolean'),
                    $this->field('SPORTSBOT_CARD_WIDTH', 'Card Width', 'number'),
                    $this->field('SPORTSBOT_CARD_HEIGHT', 'Card Height', 'number'),
                    $this->field('SPORTSBOT_CARD_FONT_REGULAR', 'Regular Font Path'),
                    $this->field('SPORTSBOT_CARD_FONT_BOLD', 'Bold Font Path'),
                    $this->field('SPORTSBOT_CARD_V3_BROWSER_ENABLED', 'V3 Browser Cards', 'boolean'),
                    $this->field('SPORTSBOT_CARD_V3_RENDERER_SCRIPT', 'V3 Renderer Script'),
                    $this->field('SPORTSBOT_CARD_NODE_BINARY', 'Node Binary'),
                    $this->field('SPORTSBOT_CARD_CHROME_PATH', 'Chrome Path'),
                    $this->field('SPORTSBOT_CARD_BROWSER_TIMEOUT', 'Browser Timeout', 'number'),
                    $this->field('SPORTSBOT_CARD_IMAGE_CACHE_TTL', 'Image Cache TTL', 'number'),
                    $this->field('SPORTSBOT_RICH_CARDS_ENABLED', 'Rich Cards Enabled', 'boolean'),
                    $this->field('SPORTSBOT_CALLBACK_THROTTLE_SECONDS', 'Callback Throttle Seconds', 'number'),
                    $this->field('SPORTSBOT_SEND_SCORE_UPDATES', 'Send Score Updates', 'boolean'),
                    $this->field('SPORTSBOT_SEND_STATUS_UPDATES', 'Send Status Updates', 'boolean'),
                    $this->field('SPORTSBOT_SEND_FIRST_SEEN_LIVE_ALERTS', 'First Seen Live Alerts', 'boolean'),
                ],
            ],
            [
                'id' => 'updater',
                'label' => 'Updater & License',
                'description' => 'Settings for the admin Git updater and license callbacks.',
                'fields' => [
                    $this->field('SPORTSBOT_UPDATER_ENABLED', 'Admin Updater Enabled', 'boolean'),
                    $this->field('SPORTSBOT_UPDATER_REMOTE', 'Git Remote'),
                    $this->field('SPORTSBOT_UPDATER_ADMIN_FRONTEND_PATH', 'Admin Frontend Path'),
                    $this->field('LARAVEL_CP_LICENSE', 'LaravelCP License', 'text', ['secret' => true]),
                    $this->field('LCP_LICENSE_PRIVATE_KEY_PATH', 'License Private Key Path'),
                    $this->field('LICENSE_CALLBACK_SECRET', 'License Callback Secret', 'text', ['secret' => true]),
                ],
            ],
        ];
    }

    private function field(string $key, string $label, string $type = 'text', array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'secret' => false,
            'read_only' => false,
            'warning' => null,
        ], $extra);
    }
}
