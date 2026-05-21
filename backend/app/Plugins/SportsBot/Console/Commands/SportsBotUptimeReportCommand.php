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

    private ?array $font;
    private ?array $fontBold;

    public function handle(SportsBotNotifier $notifier): int
    {
        $days = max(7, min(90, (int) $this->option('days')));
        $siteId = $this->option('site');
        $send = (bool) $this->option('send');
        $route = $this->option('route') ?: TelegramRouteKeys::HIGHLIGHTS;

        $this->font = $this->findFont();

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
        $h = 440;
        $padX = 55;
        $padTop = 55;
        $padBottom = 55;
        $chartLeft = $padX;
        $chartRight = $w - $padX;
        $chartTop = $padTop;
        $chartBottom = $h - $padBottom;
        $chartW = $chartRight - $chartLeft;
        $barArea = $chartBottom - $chartTop;

        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;

        $colors = $this->allocateColors($img);

        imagefilledrectangle($img, 0, 0, $w, $h, $colors['bg']);

        // Header bar
        imagefilledrectangle($img, 0, 0, $w, 38, $colors['header']);

        $font = $this->font ?? [];
        $fontB = $font;

        // Title
        $startLabel = now()->subDays($days - 1)->format('M j');
        $endLabel = now()->format('M j');
        $this->drawText($img, $font, "Uptime  |  {$startLabel} - {$endLabel}", 55, 27, 14, $colors['dim']);

        // Legend
        $legendX = $w - 250;
        $this->drawText($img, $font, "●", $legendX, 26, 10, $colors['green']);
        $this->drawText($img, $font, "Up", $legendX + 14, 26, 10, $colors['dim']);
        $this->drawText($img, $font, "●", $legendX + 50, 26, 10, $colors['amber']);
        $this->drawText($img, $font, "Deg", $legendX + 64, 26, 10, $colors['dim']);
        $this->drawText($img, $font, "●", $legendX + 110, 26, 10, $colors['red']);
        $this->drawText($img, $font, "Down", $legendX + 124, 26, 10, $colors['dim']);

        // Grid lines
        for ($i = 0; $i <= 4; $i++) {
            $y = $chartTop + ($barArea / 4) * $i;
            imageline($img, $chartLeft, (int) $y, $chartRight, (int) $y, $colors['grid']);
        }

        $logs = SportsBotUptimeLog::where('site_id', $site->id)
            ->where('checked_at', '>=', now()->subDays($days))
            ->orderBy('checked_at')
            ->get();

        $daily = [];
        foreach ($logs as $log) {
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
                    $color = $colors['green'];
                } elseif ($failPct >= 0.9) {
                    $color = $colors['red'];
                } else {
                    $color = $colors['amber'];
                }
                $barH = $barArea;
            } else {
                $color = $colors['noData'];
                $barH = 3;
            }

            $x1 = (int) $x;
            $y1 = (int) ($chartBottom - $barH);
            $x2 = (int) ($x + $barW);
            $y2 = (int) $chartBottom;

            $radius = min(3, (int) ($barW / 2));
            imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2, $color);
            imagefilledellipse($img, (int) ($x1 + $x2) / 2, $y1 + $radius, $x2 - $x1, $radius * 2, $color);
        }

        // Bottom border
        imageline($img, $chartLeft, $chartBottom, $chartRight, $chartBottom, $colors['border']);

        // Date labels (every 5-7 days)
        $labelInterval = max(1, (int) ($days / 6));
        $fontSmall = $this->font ?? [];
        for ($i = $days - 1; $i >= 0; $i -= $labelInterval) {
            $d = $now->copy()->subDays($i);
            $x = $chartLeft + ($days - 1 - $i) * $barTotalW + ($barW / 2);
            $label = $d->format('j M');
            $this->drawTextCentered($img, $fontSmall, $label, (int) $x, $h - 12, 10, $colors['dim']);
        }

        // Baseline labels
        $this->drawTextCentered($img, $fontSmall, "0%", (int) ($chartLeft - 18), $chartBottom + 4, 9, $colors['dim']);
        $this->drawTextCentered($img, $fontSmall, "100%", (int) ($chartLeft - 22), $chartTop + 4, 9, $colors['dim']);

        $path = storage_path("app/sportsbot/cards/uptime-{$site->id}-" . time() . ".png");
        @mkdir(dirname($path), 0755, true);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function allocateColors($img): array
    {
        return [
            'bg' => imagecolorallocate($img, 10, 20, 35),
            'header' => imagecolorallocate($img, 15, 28, 48),
            'green' => imagecolorallocate($img, 34, 197, 94),
            'red' => imagecolorallocate($img, 239, 68, 68),
            'amber' => imagecolorallocate($img, 250, 204, 21),
            'noData' => imagecolorallocate($img, 25, 40, 60),
            'grid' => imagecolorallocate($img, 22, 38, 58),
            'border' => imagecolorallocate($img, 30, 50, 75),
            'dim' => imagecolorallocate($img, 120, 140, 165),
            'white' => imagecolorallocate($img, 248, 250, 252),
        ];
    }

    private function drawText($img, ?array $font, string $text, int $x, int $y, int $size, $color): void
    {
        if ($font) {
            @imagettftext($img, $size, 0, $x, $y, $color, $font[0], $text);
        }
    }

    private function drawTextCentered($img, ?array $font, string $text, int $cx, int $y, int $size, $color): void
    {
        if ($font) {
            $box = @imagettfbbox($size, 0, $font[0], $text);
            $tw = $box ? ($box[2] - $box[0]) : 0;
            @imagettftext($img, $size, 0, $cx - (int) ($tw / 2), $y, $color, $font[0], $text);
        }
    }

    private function findFont(): ?array
    {
        $paths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-Regular.ttf',
        ];
        foreach ($paths as $p) {
            if (is_file($p)) return [$p];
        }
        return null;
    }
}
