<?php

namespace App\Plugins\SportsBot\Services;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabber;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabberRun;
use App\Plugins\SportsBot\Models\SportsBotEpgImportRun;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SportsBotEpgHealthService
{
    public function __construct(
        private readonly SportsBotEpgGrabberRuntime $grabbers = new SportsBotEpgGrabberRuntime(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $alerts = [];
        if (! Schema::hasTable('sportsbot_epg_sources')) {
            return [
                'status' => 'missing_tables',
                'alerts' => [$this->alert('tables_missing', 'critical', 'EPG provider tables are missing. Run migrations.')],
            ];
        }

        $alerts = array_merge(
            $alerts,
            $this->exportAlerts(),
            $this->sourceAlerts(),
            $this->grabberAlerts(),
            $this->coverageAlerts(),
            $this->importVolumeAlerts(),
        );

        return [
            'status' => $alerts === [] ? 'healthy' : 'attention',
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notify(MonitorBotTelegramNotifier $notifier): array
    {
        $snapshot = $this->snapshot();
        $alerts = (array) ($snapshot['alerts'] ?? []);
        $sent = 0;
        $suppressed = 0;
        $failed = 0;

        foreach ($alerts as $alert) {
            Log::warning('sportsbot.epg.health_alert', $alert);
            $key = 'sportsbot:epg:health-notified:' . sha1((string) ($alert['key'] ?? json_encode($alert)));
            $cooldown = now()->addMinutes(max(15, (int) config('plugins.SportsBot.epg.health.notify_cooldown_minutes', 360)));
            if (! Cache::add($key, true, $cooldown)) {
                $suppressed++;
                continue;
            }

            if (! $notifier->configured()) {
                $suppressed++;
                continue;
            }

            try {
                $notifier->sendMessage($this->message($alert));
                $sent++;
            } catch (Throwable $error) {
                Cache::forget($key);
                $failed++;
                Log::warning('sportsbot.epg.health_notify_failed', [
                    'alert' => $alert,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $snapshot + [
            'sent' => $sent,
            'suppressed' => $suppressed,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportAlerts(): array
    {
        $path = storage_path('app/sportsbot/epg/guide.xml');
        if (! is_file($path)) {
            return [$this->alert('export_missing', 'warning', 'Cached EPG XMLTV export is missing.')];
        }

        $maxAgeHours = max(1, (int) config('plugins.SportsBot.epg.health.export_max_age_hours', 8));
        $ageHours = round(max(0, time() - (int) filemtime($path)) / 3600, 1);
        if ($ageHours > $maxAgeHours) {
            return [$this->alert('export_stale', 'warning', "Cached EPG XMLTV export is {$ageHours} hours old.", [
                'max_age_hours' => $maxAgeHours,
            ])];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceAlerts(): array
    {
        $alerts = [];
        $rows = SportsBotEpgSource::query()
            ->where('enabled', true)
            ->where(function ($query): void {
                $query->whereIn('status', ['failed', 'blocked', 'empty'])->orWhere('stale', true);
            })
            ->orderBy('priority')
            ->limit(10)
            ->get();

        foreach ($rows as $source) {
            $status = $source->stale ? 'stale' : (string) $source->status;
            $alerts[] = $this->alert('source:' . $source->id . ':' . $status, $status === 'blocked' ? 'critical' : 'warning', 'EPG source needs attention: ' . ($source->name ?: $source->url) . " ({$status}).", [
                'source_id' => $source->id,
                'url' => $source->url,
                'status' => $status,
            ]);
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function grabberAlerts(): array
    {
        if (! Schema::hasTable('sportsbot_epg_grabbers') || ! Schema::hasTable('sportsbot_epg_grabber_runs')) {
            return [];
        }

        $required = max(2, (int) config('plugins.SportsBot.epg.health.grabber_failure_runs', 3));
        $alerts = [];
        foreach (SportsBotEpgGrabber::query()->where('enabled', true)->get() as $grabber) {
            $runs = SportsBotEpgGrabberRun::query()
                ->where('grabber_id', $grabber->id)
                ->latest('id')
                ->limit($required)
                ->pluck('status')
                ->all();

            if (count($runs) >= $required && count(array_filter($runs, fn (mixed $status): bool => (string) $status === 'failed')) === $required) {
                $alerts[] = $this->alert('grabber:' . $grabber->id . ':failed', 'critical', 'EPG grabber failed repeatedly: ' . $grabber->name . '.', [
                    'grabber_id' => $grabber->id,
                    'failure_runs' => $required,
                ]);
            }
        }

        return $alerts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function coverageAlerts(): array
    {
        $missing = $this->grabbers->missingUkSportsChannels();
        if ($missing === []) {
            return [];
        }

        return [$this->alert('uk_coverage_missing', 'warning', count($missing) . ' expected UK EPG channels have no future coverage.', [
            'missing_channels' => $missing,
        ])];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importVolumeAlerts(): array
    {
        $latestRuns = SportsBotEpgImportRun::query()
            ->whereIn('status', ['working', 'stale', 'empty'])
            ->latest('id')
            ->limit(40)
            ->get();
        $minimum = max(0.05, min(0.95, (float) config('plugins.SportsBot.epg.health.programme_drop_ratio', 0.25)));
        $alerts = [];
        $seen = [];

        foreach ($latestRuns as $latest) {
            $sourceKey = (string) ($latest->source_id ?: $latest->source_url ?: '');
            if ($sourceKey === '' || isset($seen[$sourceKey])) {
                continue;
            }
            $seen[$sourceKey] = true;

            $previous = SportsBotEpgImportRun::query()
                ->whereIn('status', ['working', 'stale', 'empty'])
                ->where('id', '<', $latest->id)
                ->when($latest->source_id, fn ($query) => $query->where('source_id', $latest->source_id), fn ($query) => $query->where('source_url', $latest->source_url))
                ->latest('id')
                ->first();
            $previousCount = (int) ($previous?->programme_count ?? 0);
            $latestCount = (int) ($latest->programme_count ?? 0);
            $ratio = $previousCount > 0 ? $latestCount / $previousCount : 1.0;

            if ($previousCount < 100 || $ratio >= $minimum) {
                continue;
            }

            $alerts[] = $this->alert('import_volume_drop:' . $sourceKey, 'warning', 'Latest EPG import volume dropped sharply.', [
                'source_id' => $latest->source_id,
                'source_url' => $latest->source_url,
                'latest_programmes' => $latestCount,
                'previous_programmes' => $previousCount,
                'ratio' => round($ratio, 2),
            ]);

            if (count($alerts) >= 5) {
                break;
            }
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(string $key, string $severity, string $message, array $context = []): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ];
    }

    private function message(array $alert): string
    {
        $severity = strtoupper((string) ($alert['severity'] ?? 'warning'));

        return implode("\n", [
            '<b>EPG ' . e($severity) . '</b>',
            e((string) ($alert['message'] ?? 'EPG health alert')),
            '<code>' . e((string) ($alert['key'] ?? 'unknown')) . '</code>',
        ]);
    }
}
