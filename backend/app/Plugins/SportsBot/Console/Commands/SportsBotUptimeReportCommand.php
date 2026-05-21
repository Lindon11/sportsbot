<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Models\SportsBotUptimeLog;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SportsBotUptimeReportCommand extends Command
{
    protected $signature = 'monitor:uptime-report
        {--site= : Site ID to report on (default: all)}
        {--days=30 : Number of days to show}
        {--send : Send report through the Monitor Bot Telegram account}';

    protected $description = 'Generate and optionally send a Monitor Bot uptime report card';

    public function handle(MonitorBotTelegramNotifier $notifier): int
    {
        if (!Schema::hasTable('sportsbot_uptime_sites') || !Schema::hasTable('sportsbot_uptime_logs')) {
            $this->warn('Uptime monitor tables are missing. Run migrations before generating reports.');

            return Command::SUCCESS;
        }

        $days = max(7, min(90, (int) $this->option('days')));
        $siteId = $this->option('site');
        $send = (bool) $this->option('send');

        $sites = $siteId
            ? SportsBotUptimeSite::where('id', (int) $siteId)->get()
            : SportsBotUptimeSite::all();

        if ($sites->isEmpty()) {
            $this->warn('No sites found');
            return Command::SUCCESS;
        }

        $sitesData = [];
        foreach ($sites as $site) {
            $sitesData[] = [
                'name' => $site->name,
                'status' => $site->status,
            ];
        }

        $dir = storage_path('app/monitor-bot/cards');
        @mkdir($dir, 0755, true);
        $inputPath = $dir . '/uptime-input-' . time() . '.json';
        $outputPath = $dir . '/uptime-' . time() . '.png';

        file_put_contents($inputPath, json_encode(['sites' => $sitesData]));

        $script = base_path('sportsbot-render-status.cjs');
        if (!is_file($script)) {
            $this->error('Render script not found');
            return Command::FAILURE;
        }

        $cmd = sprintf('node %s %s %s 2>&1', escapeshellarg($script), escapeshellarg($inputPath), escapeshellarg($outputPath));
        exec($cmd, $output, $exitCode);

        @unlink($inputPath);

        if ($exitCode !== 0) {
            $this->error('Render failed: ' . implode("\n", $output));
            return Command::FAILURE;
        }

        $this->info("Report saved: {$outputPath}");

        if ($send && is_file($outputPath)) {
            try {
                $notifier->sendPhoto($outputPath, '');
                $this->info('Sent through Monitor Bot');
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
