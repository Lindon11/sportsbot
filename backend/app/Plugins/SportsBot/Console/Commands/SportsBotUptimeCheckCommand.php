<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Models\SportsBotUptimeLog;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SportsBotUptimeCheckCommand extends Command
{
    protected $signature = 'monitor:uptime-check
        {--site= : Check a specific site by ID}
        {--force : Force check all sites regardless of interval}';

    protected $description = 'Check uptime for monitored sites and send Monitor Bot Telegram alerts';

    public function handle(MonitorBotTelegramNotifier $notifier): int
    {
        if (!Schema::hasTable('sportsbot_uptime_sites') || !Schema::hasTable('sportsbot_uptime_logs')) {
            $this->warn('Uptime monitor tables are missing. Run migrations before enabling Monitor Bot uptime checks.');

            return Command::SUCCESS;
        }

        $sites = $this->getSites();

        if ($sites->isEmpty()) {
            return Command::SUCCESS;
        }

        foreach ($sites as $site) {
            $this->checkSite($site, $notifier);
        }

        return Command::SUCCESS;
    }

    private function getSites()
    {
        $query = SportsBotUptimeSite::where('enabled', true);

        $siteId = $this->option('site');
        if ($siteId) {
            $query->where('id', (int) $siteId);
        }

        $force = $this->option('force');
        if (!$force) {
            return $query->get()->filter(fn (SportsBotUptimeSite $s) =>
                $s->last_checked_at === null || $s->last_checked_at->diffInSeconds(now()) >= $s->check_interval_seconds
            )->values();
        }

        return $query->get();
    }

    private function checkSite(SportsBotUptimeSite $site, MonitorBotTelegramNotifier $notifier): void
    {
        $start = microtime(true);
        $status = 'online';
        $statusCode = null;
        $error = null;

        try {
            $response = Http::timeout($site->timeout_seconds)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'])
                ->get($site->url);

            $statusCode = $response->status();
            $body = $response->body();

            if ($statusCode >= 500) {
                $status = 'offline';
                $error = "HTTP {$statusCode}";
            } elseif ($site->expected_keyword && !str_contains($body, $site->expected_keyword)) {
                $status = 'offline';
                $error = "Keyword '{$site->expected_keyword}' not found";
            }
        } catch (Throwable $e) {
            $status = 'offline';
            $error = $e->getMessage();
        }

        $responseTime = (int) ((microtime(true) - $start) * 1000);

        SportsBotUptimeLog::create([
            'site_id' => $site->id,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTime,
            'error' => $error,
            'status' => $status,
            'checked_at' => now(),
        ]);

        $wasOffline = $site->status === 'offline';
        $site->last_checked_at = now();
        $site->total_checks++;

        if ($status === 'online') {
            $site->status = 'online';
            $site->last_online_at = now();
            $site->consecutive_failures = 0;

            if ($wasOffline) {
                $this->sendAlert($site, 'recovered', $notifier, $responseTime, null, $statusCode);
            }
        } else {
            $site->consecutive_failures++;
            $site->total_failures++;

            if ($site->consecutive_failures >= $site->failure_threshold) {
                $site->status = 'offline';
                $site->last_offline_at = now();

                if (!$wasOffline) {
                    $this->sendAlert($site, 'down', $notifier, $responseTime, $error, $statusCode);
                }
            }
        }

        $site->uptime_percentage = $site->total_checks > 0
            ? (int) round((($site->total_checks - $site->total_failures) / $site->total_checks) * 100)
            : 100;

        $site->save();

        $this->line("[{$site->name}] {$status} ({$responseTime}ms)" . ($error ? " - {$error}" : ''));
    }

    private function sendAlert(SportsBotUptimeSite $site, string $type, MonitorBotTelegramNotifier $notifier, int $responseTime, ?string $error = null, ?int $statusCode = null): void
    {
        if (!$site->alerts_enabled) {
            return;
        }

        $isDown = $type === 'down';

        if (!$notifier->configured()) {
            Log::warning('monitor_bot.telegram.not_configured', [
                'site_id' => $site->id,
                'type' => $type,
            ]);

            return;
        }

        try {
            $cardPath = $this->renderAlertCard($site, $type, $responseTime, $error, $statusCode);
            $notifier->sendPhoto($cardPath, '');
        } catch (Throwable $error) {
            Log::warning('monitor_bot.uptime.card_alert_failed', [
                'site_id' => $site->id,
                'type' => $type,
                'error' => $error->getMessage(),
            ]);

            try {
                $notifier->sendMessage(($isDown ? '⚠️ Downtime' : '✅ Online') . ': ' . $site->name);
            } catch (Throwable $fallbackError) {
                Log::warning('monitor_bot.uptime.text_alert_failed', [
                    'site_id' => $site->id,
                    'type' => $type,
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }
    }

    private function renderAlertCard(SportsBotUptimeSite $site, string $type, int $responseTime, ?string $error, ?int $statusCode): string
    {
        $dir = storage_path('app/monitor-bot/cards');
        @mkdir($dir, 0755, true);

        $stamp = now()->format('YmdHis');
        $inputPath = $dir . '/uptime-alert-input-' . $site->id . '-' . $stamp . '.json';
        $outputPath = $dir . '/uptime-alert-' . $site->id . '-' . $stamp . '.png';

        $status = $type === 'down' ? 'offline' : 'online';
        $payload = [
            'mode' => 'alert',
            'type' => $type,
            'checked_at' => now()->toIso8601String(),
            'title' => $type === 'down' ? 'Server Experiencing Downtime' : 'Server Is Now Online',
            'message' => $type === 'down'
                ? "{$site->name} is currently experiencing downtime. Please wait for an update."
                : "{$site->name} is now back online and operating normally.",
            'sites' => [[
                'name' => $site->name,
                'url' => $site->url,
                'status' => $status,
                'status_code' => $statusCode,
                'response_time_ms' => $responseTime,
                'error' => $error,
            ]],
        ];

        file_put_contents($inputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $script = base_path('sportsbot-render-status.cjs');
        if (!is_file($script)) {
            @unlink($inputPath);
            throw new RuntimeException('Render script not found');
        }

        $command = sprintf(
            'node %s %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
        exec($command, $output, $exitCode);
        @unlink($inputPath);

        if ($exitCode !== 0 || !is_file($outputPath)) {
            throw new RuntimeException('Render failed: ' . implode("\n", $output));
        }

        return $outputPath;
    }
}
