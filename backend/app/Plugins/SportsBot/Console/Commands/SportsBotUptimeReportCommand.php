<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Models\SportsBotUptimeLog;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use App\Plugins\SportsBot\Services\SportsBotNotifier;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SportsBotUptimeReportCommand extends Command
{
    protected $signature = 'sportsbot:uptime-report
        {--site= : Site ID to report on (default: all)}
        {--days=30 : Number of days to show}
        {--send : Send report to Telegram}
        {--route= : Route key to send to}';

    protected $description = 'Generate and optionally send an uptime report card via Puppeteer';

    public function handle(SportsBotNotifier $notifier): int
    {
        $days = max(7, min(90, (int) $this->option('days')));
        $siteId = $this->option('site');
        $send = (bool) $this->option('send');
        $route = $this->option('route') ?: TelegramRouteKeys::HIGHLIGHTS;

        $sites = $siteId
            ? SportsBotUptimeSite::where('id', (int) $siteId)->get()
            : SportsBotUptimeSite::all();

        if ($sites->isEmpty()) {
            $this->warn('No sites found');
            return Command::SUCCESS;
        }

        $sitesData = [];
        foreach ($sites as $site) {
            $dailyStatus = $this->buildDailyStatus($site, $days);
            $avgResponse = SportsBotUptimeLog::where('site_id', $site->id)
                ->whereNotNull('response_time_ms')
                ->where('checked_at', '>=', now()->subDays($days))
                ->avg('response_time_ms');

            $sitesData[] = [
                'name' => $site->name,
                'url' => $site->url,
                'status' => $site->status,
                'uptime_percentage' => $site->uptime_percentage,
                'avg_response' => $avgResponse ? 'Avg ' . round($avgResponse) . 'ms' : '-',
                'daily_status' => $dailyStatus,
            ];
        }

        $dir = storage_path('app/sportsbot/cards');
        @mkdir($dir, 0755, true);
        $inputPath = $dir . '/uptime-input-' . time() . '.json';
        $outputPath = $dir . '/uptime-' . time() . '.png';

        file_put_contents($inputPath, json_encode(['sites' => $sitesData]));

        $script = base_path('sportsbot-render-status.js');
        if (!is_file($script)) {
            $this->error('Render script not found');
            return Command::FAILURE;
        }

        $cmd = "node {$script} {$inputPath} {$outputPath} 2>&1";
        exec($cmd, $output, $exitCode);

        @unlink($inputPath);

        if ($exitCode !== 0) {
            $this->error('Render failed: ' . implode("\n", $output));
            return Command::FAILURE;
        }

        $this->info("Report saved: {$outputPath}");

        if ($send && is_file($outputPath)) {
            try {
                $notifier->sendPhoto($outputPath, '📊', [
                    'route_key' => $route,
                    'type' => 'UPTIME_REPORT',
                ]);
                $this->info("Sent to {$route}");
            } catch (Throwable $e) {
                $this->error("Send failed: {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }

    private function buildDailyStatus(SportsBotUptimeSite $site, int $days): array
    {
        $startDate = now()->subDays($days - 1)->startOfDay();
        $results = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $startDate->copy()->addDays($day);
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $failCount = SportsBotUptimeLog::where('site_id', $site->id)
                ->where('checked_at', '>=', $dayStart)->where('checked_at', '<=', $dayEnd)
                ->where('status', 'offline')->count();

            $totalCount = SportsBotUptimeLog::where('site_id', $site->id)
                ->where('checked_at', '>=', $dayStart)->where('checked_at', '<=', $dayEnd)
                ->count();

            if ($totalCount === 0) {
                $results[] = ['status' => 'none'];
            } elseif ($failCount === 0) {
                $results[] = ['status' => 'up'];
            } elseif ($failCount >= $totalCount) {
                $results[] = ['status' => 'down'];
            } else {
                $results[] = ['status' => 'degraded'];
            }
        }

        return $results;
    }
}
