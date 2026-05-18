<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Core\Services\LicenseService;
use App\Plugins\SportsBot\Models\SportsBotTelegramRoute;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Services\SportsBotRunner;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class SportsBotHealthCommand extends Command
{
    protected $signature = 'sportsbot:health
        {--json : Output machine-readable JSON}
        {--fix : Create missing writable SportsBot storage directories}
        {--render : Render a no-fixtures V3 card as a smoke test}';

    protected $description = 'Check SportsBot production readiness and runtime configuration';

    public function handle(SportsBotRunner $runner): int
    {
        $checks = [];
        $health = $runner->health();

        $checks[] = $this->check('Plugin enabled', (bool) $health['plugin_enabled']);
        $checks[] = $this->check('Provider API key configured', (bool) $health['provider_key_configured'], 'Set SPORTSBOT_THESPORTSDB_API_KEY.');
        $checks[] = $this->check('Telegram or Discord configured when sending is enabled', !(bool) $health['send_messages'] || (bool) ($health['telegram_configured'] || $health['discord_configured']));
        $checks[] = $this->check('Licence valid', LicenseService::isLicensed(), 'Add LARAVEL_CP_LICENSE or storage/license_key plus matching license_public.pem.');
        $checks[] = $this->check('LICENSE_CALLBACK_SECRET set', trim((string) env('LICENSE_CALLBACK_SECRET', '')) !== '', 'Run php artisan license:generate-secret.');

        foreach (['curl', 'fileinfo', 'gd', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'xml', 'zip'] as $extension) {
            $checks[] = $this->check('PHP extension: ' . $extension, extension_loaded($extension));
        }

        $checks = array_merge($checks, $this->databaseChecks());
        $checks = array_merge($checks, $this->storageChecks((bool) $this->option('fix')));
        $checks = array_merge($checks, $this->rendererChecks());
        $checks = array_merge($checks, $this->routeChecks());
        $checks = array_merge($checks, $this->automationChecks());

        if ((bool) $this->option('render')) {
            $checks[] = $this->renderSmokeCheck();
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ok' => !collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail'),
                'checks' => $checks,
                'summary' => [
                    'provider' => $health['provider'],
                    'schedule_enabled' => $health['schedule_enabled'],
                    'send_messages' => $health['send_messages'],
                    'enabled_sports' => $health['enabled_sports'],
                    'allowed_league_count' => $health['allowed_league_count'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail')
                ? Command::FAILURE
                : Command::SUCCESS;
        }

        $this->info('SportsBot production health');
        $this->line('Provider: ' . $health['provider']);
        $this->line('Schedule enabled: ' . ($health['schedule_enabled'] ? 'yes' : 'no'));
        $this->line('Native sending enabled: ' . ($health['send_messages'] ? 'yes' : 'no'));
        $this->line('Enabled sports: ' . implode(', ', $health['enabled_sports']));
        $this->line('Allowed leagues: ' . (int) $health['allowed_league_count']);
        $this->newLine();

        foreach ($checks as $check) {
            $prefix = match ($check['status']) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };

            $this->line(sprintf('%s %s%s', $prefix, $check['name'], $check['message'] !== '' ? ' - ' . $check['message'] : ''));
        }

        $failed = collect($checks)->where('status', 'fail')->count();
        $warned = collect($checks)->where('status', 'warn')->count();

        $this->newLine();

        if ($failed > 0) {
            $this->error("SportsBot production health failed ({$failed} fail, {$warned} warn).");

            return Command::FAILURE;
        }

        if ($warned > 0) {
            $this->warn("SportsBot production health passed with {$warned} warning(s).");

            return Command::SUCCESS;
        }

        $this->info('SportsBot production health passed.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{name:string,status:string,message:string}>
     */
    private function databaseChecks(): array
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks[] = $this->check('Database connection', true);
        } catch (Throwable $error) {
            return [$this->check('Database connection', false, $error->getMessage())];
        }

        foreach ([
            'migrations',
            'sportsbot_settings',
            'sportsbot_fixture_queue',
            'sportsbot_telegram_routes',
            'sportsbot_telegram_topics',
            'sportsbot_deliveries',
        ] as $table) {
            $checks[] = $this->check('Database table: ' . $table, $this->hasTable($table), 'Run php artisan migrate --force.');
        }

        return $checks;
    }

    /**
     * @return array<int, array{name:string,status:string,message:string}>
     */
    private function storageChecks(bool $fix): array
    {
        $checks = [];

        foreach ([
            storage_path('app/sportsbot/cards'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $path) {
            if (!is_dir($path) && $fix) {
                @mkdir($path, 0775, true);
            }

            $checks[] = $this->check('Writable path: ' . $this->shortPath($path), is_dir($path) && is_writable($path), 'Fix ownership/permissions for the web user.');
        }

        return $checks;
    }

    /**
     * @return array<int, array{name:string,status:string,message:string}>
     */
    private function rendererChecks(): array
    {
        $checks = [];
        $script = (string) config('plugins.SportsBot.cards.v3_renderer_script', base_path('resources/sportsbot/v3-card-renderer.cjs'));
        $node = trim((string) config('plugins.SportsBot.cards.node_binary', 'node')) ?: 'node';
        $browserEnabled = (bool) config('plugins.SportsBot.cards.v3_browser_enabled', true);

        $checks[] = $this->check('V3 browser cards enabled', $browserEnabled, 'Set SPORTSBOT_CARD_V3_BROWSER_ENABLED=true.');
        $checks[] = $this->check('V3 renderer script exists', is_file($script), $this->shortPath($script));

        if (!$browserEnabled) {
            return $checks;
        }

        $checks[] = $this->processCheck('Node available', [$node, '--version'], base_path());
        $checks[] = $this->processCheck('Puppeteer package loadable', [$node, '-e', "try{require('puppeteer');console.log('puppeteer')}catch(e){require('puppeteer-core');console.log('puppeteer-core')}"], base_path());

        $chromePath = trim((string) config('plugins.SportsBot.cards.chrome_path', ''));
        if ($chromePath !== '') {
            $checks[] = $this->check('Chrome executable path', is_file($chromePath) && is_executable($chromePath), $chromePath);
        } else {
            $checks[] = $this->processCheck(
                'Puppeteer browser executable available',
                [$node, '-e', "const fs=require('fs');let p;try{p=require('puppeteer')}catch(e){p=require('puppeteer-core')}const candidates=[process.env.PUPPETEER_EXECUTABLE_PATH,'/usr/bin/chromium','/usr/bin/chromium-browser','/usr/bin/google-chrome','/usr/bin/google-chrome-stable'];let exe=candidates.find(v=>v&&fs.existsSync(v));if(!exe&&p.executablePath){try{const guessed=p.executablePath();if(guessed&&fs.existsSync(guessed))exe=guessed}catch(e){}}if(!exe){console.error('Install chromium or set SPORTSBOT_CARD_CHROME_PATH/PUPPETEER_EXECUTABLE_PATH.');process.exit(1)}console.log(exe)"],
                base_path()
            );
        }

        return $checks;
    }

    /**
     * @return array<int, array{name:string,status:string,message:string}>
     */
    private function routeChecks(): array
    {
        if (!$this->hasTable('sportsbot_telegram_routes')) {
            return [];
        }

        $checks = [];
        $requiredRoutes = collect(SportsFixtureConfig::all())
            ->map(fn (array $config): string => (string) ($config['topic_key'] ?? ''))
            ->filter()
            ->unique()
            ->values();

        foreach ($requiredRoutes as $routeKey) {
            $route = SportsBotTelegramRoute::query()->where('route_key', $routeKey)->first();
            $checks[] = $this->check(
                'Telegram route assigned: ' . $routeKey,
                $route !== null && (bool) $route->enabled,
                'Assign this route in SportsBot Telegram Routes.',
                'warn'
            );
        }

        return $checks;
    }

    /**
     * @return array<int, array{name:string,status:string,message:string}>
     */
    private function automationChecks(): array
    {
        $settings = app(SportsBotSettingsService::class);

        $fixtureQueueEnabled = (bool) $settings->get('fixture_queue_schedule_enabled', config('plugins.SportsBot.publishing.fixture_queue.enabled', false));

        return [
            $this->check(
                'Fixture queue scheduler enabled',
                $fixtureQueueEnabled,
                'Enable Fixture Queue schedule in SportsBot Autopilot or set SPORTSBOT_FIXTURE_QUEUE_SCHEDULE_ENABLED=true.',
                'warn'
            ),
            $this->check(
                'Fixture prefetch automation enabled',
                !$fixtureQueueEnabled || (bool) $settings->get('fixture_queue_prefetch_enabled', config('plugins.SportsBot.publishing.fixture_queue.prefetch_enabled', true)),
                'Enable fixture queue prefetch.',
                'warn'
            ),
            $this->check(
                'Fixture enrich automation enabled',
                !$fixtureQueueEnabled || (bool) $settings->get('fixture_queue_enrich_enabled', config('plugins.SportsBot.publishing.fixture_queue.enrich_enabled', true)),
                'Enable fixture queue enrich.',
                'warn'
            ),
            $this->check(
                'Fixture render automation enabled',
                !$fixtureQueueEnabled || (bool) $settings->get('fixture_queue_render_enabled', config('plugins.SportsBot.publishing.fixture_queue.render_enabled', true)),
                'Enable fixture queue render.',
                'warn'
            ),
            $this->check(
                'Fixture publish automation enabled',
                !$fixtureQueueEnabled || (bool) $settings->get('fixture_queue_publish_enabled', config('plugins.SportsBot.publishing.fixture_queue.publish_enabled', true)),
                'Enable fixture queue publish.',
                'warn'
            ),
        ];
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function renderSmokeCheck(): array
    {
        try {
            $card = app(SportsBotCardRenderer::class)->noFixturesCard([
                'sport' => 'other_sports',
                'sport_label' => 'Other Sports',
                'title' => 'Other Sports Fixtures TV',
                'route_key' => 'OTHER_SPORTS',
                'date' => now()->toDateString(),
            ], 'v3');

            $path = (string) ($card['path'] ?? '');

            return $this->check('V3 card render smoke test', $path !== '' && is_file($path) && filesize($path) > 0, $this->shortPath($path));
        } catch (Throwable $error) {
            return $this->check('V3 card render smoke test', false, $error->getMessage());
        }
    }

    /**
     * @param array<int, string> $command
     * @return array{name:string,status:string,message:string}
     */
    private function processCheck(string $name, array $command, string $cwd): array
    {
        try {
            $process = new Process($command, $cwd);
            $process->setTimeout(15);
            $process->run();

            $output = trim($process->getOutput() ?: $process->getErrorOutput());

            return $this->check($name, $process->isSuccessful(), $output);
        } catch (Throwable $error) {
            return $this->check($name, false, $error->getMessage());
        }
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function check(string $name, bool $ok, string $message = '', string $failureStatus = 'fail'): array
    {
        return [
            'name' => $name,
            'status' => $ok ? 'pass' : $failureStatus,
            'message' => $ok ? '' : $message,
        ];
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function shortPath(string $path): string
    {
        $base = base_path();

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR) : $path;
    }
}
