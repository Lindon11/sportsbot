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
                    $notifier->sendPhoto($path, "📊 Uptime: {$site->name} ({$days}d)", [
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
        $h = 630;
        $pad = 50;
        $chartTop = 140;
        $chartBottom = $h - 80;
        $chartH = $chartBottom - $chartTop;

        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;

        $bg = imagecolorallocate($img, 7, 17, 31);
        $card = imagecolorallocate($img, 15, 26, 44);
        $green = imagecolorallocate($img, 34, 197, 94);
        $red = imagecolorallocate($img, 239, 68, 68);
        $amber = imagecolorallocate($img, 250, 204, 21);
        $gray = imagecolorallocate($img, 51, 65, 85);
        $white = imagecolorallocate($img, 248, 250, 252);
        $dim = imagecolorallocate($img, 148, 163, 184);
        $dark = imagecolorallocate($img, 30, 41, 59);

        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        imagefilledrectangle($img, 30, 20, $w - 30, $h - 20, $card);
        imagerectangle($img, 30, 20, $w - 30, $h - 20, $dark);

        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

        // Title
        $this->drawText($img, $font, "📊 Uptime — {$site->name}", 55, 65, 24, $white);
        $this->drawText($img, $fontRegular, "{$days}-day history  •  {$site->url}", 55, 98, 14, $dim);

        // Uptime percentage badge
        $pct = $site->uptime_percentage;
        $pctColor = $pct >= 99 ? $green : ($pct >= 95 ? $amber : $red);
        $this->drawText($img, $font, "{$pct}%", $w - 160, 65, 36, $pctColor);

        // Status bar
        $last30 = SportsBotUptimeLog::where('site_id', $site->id)
            ->where('checked_at', '>=', now()->subDays($days))
            ->orderBy('checked_at')
            ->get();

        if ($last30->isEmpty()) {
            $this->drawText($img, $fontRegular, "No data yet", 55, 300, 20, $dim);
            $path = storage_path("app/sportsbot/cards/uptime-{$site->id}.png");
            @mkdir(dirname($path), 0755, true);
            imagepng($img, $path);
            imagedestroy($img);
            return $path;
        }

        // Group by day
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

        // Generate last X days including empty ones
        $barW = max(8, ($w - $pad * 2 - 60) / $days);
        $gap = max(2, $barW * 0.25);
        $barTotalW = $barW + $gap;
        $startX = $pad;
        $now = now()->startOfDay();

        // Legend
        $legendY = 118;
        $this->drawText($img, $fontRegular, "■ Up", $w - 320, $legendY, 12, $green);
        $this->drawText($img, $fontRegular, "■ Degraded", $w - 220, $legendY, 12, $amber);
        $this->drawText($img, $fontRegular, "■ Down", $w - 90, $legendY, 12, $red);

        $totalOnline = 0;
        $totalDays = 0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->toDateString();
            $x = $startX + ($days - 1 - $i) * $barTotalW;
            $dayData = $daily[$date] ?? null;

            if ($dayData && $dayData['total'] > 0) {
                $totalDays++;
                $failPct = $dayData['fail'] / $dayData['total'];
                if ($failPct === 0) {
                    $color = $green;
                    $totalOnline++;
                } elseif ($failPct >= 0.9) {
                    $color = $red;
                } else {
                    $color = $amber;
                }
                $barH = $chartH;
            } else {
                $color = $dark;
                $barH = 4;
            }

            $x1 = (int) $x;
            $y1 = (int) ($chartBottom - $barH);
            $x2 = (int) ($x + $barW);
            $y2 = (int) $chartBottom;
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
        }

        // Baseline
        imageline($img, $pad, $chartBottom, $w - $pad - 30, $chartBottom, $gray);

        // Date labels
        $this->drawText($img, $fontRegular, $now->copy()->subDays($days - 1)->format('M j'), $startX, $chartBottom + 20, 11, $dim);
        $this->drawText($img, $fontRegular, $now->format('M j'), $w - $pad - 60, $chartBottom + 20, 11, $dim);
        $this->drawText($img, $fontRegular, "Today", $w - $pad - 60, $chartBottom + 38, 11, $dim);

        $path = storage_path("app/sportsbot/cards/uptime-{$site->id}-" . time() . ".png");
        @mkdir(dirname($path), 0755, true);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function drawText($img, $font, string $text, int $x, int $y, int $size, $color): void
    {
        @imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }
}
