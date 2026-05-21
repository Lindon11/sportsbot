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

    protected $description = 'Generate and optionally send an uptime report card';

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

        foreach ($sites as $site) {
            $path = $this->renderReport($site, $days);
            if (!$path) {
                $this->warn("Failed to render report for {$site->name}");
                continue;
            }

            $this->info("Report saved: {$path}");

            if ($send) {
                try {
                    $notifier->sendPhoto($path, '📊', [
                        'route_key' => $route,
                        'type' => 'UPTIME_REPORT',
                    ]);
                    $this->info("Sent to {$route}");
                } catch (Throwable $e) {
                    $this->error("Send failed: {$e->getMessage()}");
                }
            }
        }

        return Command::SUCCESS;
    }

    private function renderReport(SportsBotUptimeSite $site, int $days): ?string
    {
        $w = 1200;
        $h = 400;
        $padX = 50;
        $padY = 30;
        $chartW = $w - $padX * 2;
        $chartH = $h - $padY * 2;

        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;

        $bg = imagecolorallocate($img, 10, 20, 35);
        $card = imagecolorallocate($img, 17, 30, 50);
        $green = imagecolorallocate($img, 34, 197, 94);
        $red = imagecolorallocate($img, 239, 68, 68);
        $amber = imagecolorallocate($img, 250, 204, 21);
        $dark = imagecolorallocate($img, 25, 40, 60);
        $gridColor = imagecolorallocate($img, 25, 40, 60);

        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        $chartLeft = $padX + 5;
        $chartRight = $w - $padX - 5;
        $chartTop = $padY + 5;
        $chartBottom = $h - $padY - 5;
        $barArea = $chartBottom - $chartTop;

        // Grid lines
        for ($i = 0; $i <= 4; $i++) {
            $y = $chartTop + ($barArea / 4) * $i;
            imageline($img, $chartLeft, (int) $y, $chartRight, (int) $y, $gridColor);
        }

        $last30 = SportsBotUptimeLog::where('site_id', $site->id)
            ->where('checked_at', '>=', now()->subDays($days))
            ->orderBy('checked_at')
            ->get();

        if ($last30->isEmpty()) {
            $path = storage_path("app/sportsbot/cards/uptime-{$site->id}.png");
            imagepng($img, $path);
            imagedestroy($img);
            return $path;
        }

        $daily = [];
        foreach ($last30 as $log) {
            $date = $log->checked_at->toDateString();
            if (!isset($daily[$date])) {
                $daily[$date] = ['total' => 0, 'fail' => 0];
            }
            $daily[$date]['total']++;
            if ($log->status === 'offline') {
                $daily[$date]['fail']++;
            }
        }

        $barTotalW = $chartW / $days;
        $barW = max(4, $barTotalW - 2);
        $now = now()->startOfDay();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->toDateString();
            $x = $chartLeft + ($days - 1 - $i) * $barTotalW;
            $dayData = $daily[$date] ?? null;

            if ($dayData && $dayData['total'] > 0) {
                $failPct = $dayData['fail'] / $dayData['total'];
                if ($failPct === 0) {
                    $color = $green;
                } elseif ($failPct >= 0.9) {
                    $color = $red;
                } else {
                    $color = $amber;
                }
                $barH = $barArea;
            } else {
                $color = $dark;
                $barH = 3;
            }

            $x1 = (int) $x;
            $y1 = (int) ($chartBottom - $barH);
            $x2 = (int) ($x + $barW);
            $y2 = (int) $chartBottom;

            // Rounded top
            $radius = min(3, (int) ($barW / 2));
            imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2, $color);
            imagefilledellipse($img, (int) ($x1 + $x2) / 2, $y1 + $radius, $x2 - $x1, $radius * 2, $color);
        }

        $path = storage_path("app/sportsbot/cards/uptime-{$site->id}-" . time() . ".png");
        @mkdir(dirname($path), 0755, true);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
