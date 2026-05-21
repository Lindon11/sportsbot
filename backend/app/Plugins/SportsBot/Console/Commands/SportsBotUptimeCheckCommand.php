<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Models\SportsBotUptimeLog;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use App\Plugins\SportsBot\Services\SportsBotNotifier;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class SportsBotUptimeCheckCommand extends Command
{
    protected $signature = 'sportsbot:uptime-check
        {--site= : Check a specific site by ID}
        {--force : Force check all sites regardless of interval}';

    protected $description = 'Check uptime for all monitored sites and send alerts';

    public function handle(SportsBotNotifier $notifier): int
    {
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
            $sites = $query->get()->filter(fn (SportsBotUptimeSite $s) =>
                $s->last_checked_at === null || $s->last_checked_at->diffInSeconds(now()) >= $s->check_interval_seconds
            );
        }

        return $query->get();
    }

    private function checkSite(SportsBotUptimeSite $site, SportsBotNotifier $notifier): void
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

            if (!$response->successful()) {
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

        $wasOnline = $site->isOnline();
        $site->last_checked_at = now();
        $site->total_checks++;

        if ($status === 'online') {
            $site->status = 'online';
            $site->last_online_at = now();
            $site->consecutive_failures = 0;

            if (!$wasOnline) {
                $this->sendAlert($site, 'recovered', $notifier, $responseTime);
            }
        } else {
            $site->consecutive_failures++;
            $site->total_failures++;

            if ($site->consecutive_failures >= $site->failure_threshold) {
                $site->status = 'offline';
                $site->last_offline_at = now();

                if ($wasOnline) {
                    $this->sendAlert($site, 'down', $notifier, $responseTime, $error);
                }
            }
        }

        $site->uptime_percentage = $site->total_checks > 0
            ? (int) round((($site->total_checks - $site->total_failures) / $site->total_checks) * 100)
            : 100;

        $site->save();

        $this->line("[{$site->name}] {$status} ({$responseTime}ms)" . ($error ? " - {$error}" : ''));
    }

    private function sendAlert(SportsBotUptimeSite $site, string $type, SportsBotNotifier $notifier, int $responseTime, ?string $error = null): void
    {
        if (!$site->alerts_enabled) {
            return;
        }

        $routeKey = $site->alert_route_key ?: TelegramRouteKeys::DEFAULT;

        if ($type === 'down') {
            $message = "🚨 <b>DOWN:</b> {$site->name}\n{$site->url}\n<b>Error:</b> {$error}";
        } else {
            $message = "✅ <b>RECOVERED:</b> {$site->name}\n{$site->url}\n<b>Response:</b> {$responseTime}ms";
        }

        try {
            $notifier->send($message, [
                'route_key' => $routeKey,
                'type' => 'UPTIME_ALERT',
                'parse_mode' => 'HTML',
            ]);
        } catch (Throwable) {
        }
    }
}
