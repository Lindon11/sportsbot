<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use App\Core\Models\PlayerProfile;
use App\Core\Models\User;
use App\Core\Services\GameHooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardStatsController extends Controller
{
    public function index()
    {
        $days     = request()->get('days', 7);
        $cacheKey = "dashboard_stats_{$days}";

        return response()->json(Cache::remember($cacheKey, 300, function () use ($days) {
            $startDate         = Carbon::now()->subDays($days);
            $endDate           = Carbon::now();
            $previousStartDate = Carbon::now()->subDays($days * 2);
            $previousEndDate   = $startDate->copy();

            return [
                // ── Platform-level metrics (Core owns these) ─────────────────
                'totalUsers'        => $this->getTotalUsers(),
                'newUsers'          => $this->getNewUsers($startDate, $endDate, $previousStartDate, $previousEndDate),
                'activeUsers'       => $this->getActiveUsers(),
                'activePercentage'  => $this->getActivePercentage(),
                'totalMoney'        => $this->getTotalMoney(),

                // ── Charts & engagement ───────────────────────────────────────
                'activityChart'     => $this->getActivityChart($days),
                'retention'         => $this->getRetentionData(),
                'hourlyActivity'    => $this->getHourlyActivity(),
                'topActivities'     => $this->getTopActivities($startDate, $endDate),
                'levelDistribution' => $this->getLevelDistribution(),

                // ── Economy summary ───────────────────────────────────────────
                'economy'           => $this->getEconomyStats(),

                // ── Plugin-contributed widgets ────────────────────────────────
                // Plugins register listeners on 'admin.dashboard.widgets' in their
                // hooks.php to append their own stats keyed by plugin slug.
                // Core never calls plugin code directly here.
                'widgets'           => GameHooks::apply('admin.dashboard.widgets', []),
            ];
        }));
    }

    private function getTotalUsers(): int
    {
        return User::count();
    }

    private function getNewUsers($startDate, $endDate, $previousStartDate, $previousEndDate): array
    {
        $current  = User::whereBetween('created_at', [$startDate, $endDate])->count();
        $previous = User::whereBetween('created_at', [$previousStartDate, $previousEndDate])->count();

        return [
            'count'  => $current,
            'change' => $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : 0,
        ];
    }

    private function getActiveUsers(): int
    {
        return User::where('last_login_at', '>=', Carbon::now()->subDay())->count();
    }

    private function getActivePercentage(): float
    {
        $total  = User::count();
        $active = User::where('last_login_at', '>=', Carbon::now()->subDay())->count();
        return $total > 0 ? round(($active / $total) * 100, 1) : 0;
    }

    private function getTotalMoney(): int
    {
        // Read from player_profiles — source of truth after Phase 1 separation
        return (int) (PlayerProfile::sum('cash') + PlayerProfile::sum('bank'));
    }

    private function getActivityChart(int $days): array
    {
        $labels          = [];
        $activeUsersData = [];
        $newSignupsData  = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date              = Carbon::now()->subDays($i);
            $labels[]          = $days <= 7 ? $date->format('D') : $date->format('M d');
            $activeUsersData[] = User::whereDate('last_login_at', $date->toDateString())->count();
            $newSignupsData[]  = User::whereDate('created_at', $date->toDateString())->count();
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => 'Active Users',
                    'data'            => $activeUsersData,
                    'borderColor'     => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ],
                [
                    'label'           => 'New Signups',
                    'data'            => $newSignupsData,
                    'borderColor'     => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
            ],
        ];
    }

    private function getEconomyStats(): array
    {
        // Read from player_profiles — source of truth after Phase 1 separation
        $totalCash = (int) PlayerProfile::sum('cash');
        $totalBank = (int) PlayerProfile::sum('bank');

        return [
            'total_cash' => $totalCash,
            'total_bank' => $totalBank,
            'total'      => $totalCash + $totalBank,
        ];
    }

    private function getRetentionData(): array
    {
        $cohorts = [];

        for ($week = 0; $week < 4; $week++) {
            $cohortStart = Carbon::now()->subWeeks($week + 1)->startOfWeek();
            $cohortEnd   = $cohortStart->copy()->endOfWeek();

            $cohortUsers = DB::table('users')
                ->whereBetween('created_at', [$cohortStart, $cohortEnd])
                ->pluck('id');

            if ($cohortUsers->isEmpty()) {
                continue;
            }

            $total      = $cohortUsers->count();
            $day1Active = $this->getReturnedUsers($cohortUsers, $cohortStart, 1);
            $day7Active = $this->getReturnedUsers($cohortUsers, $cohortStart, 7);

            $cohorts[] = [
                'week'  => $week === 0 ? 'This Week' : ($week === 1 ? 'Last Week' : "{$week} Weeks Ago"),
                'users' => $total,
                'day1'  => round(($day1Active / $total) * 100),
                'day7'  => round(($day7Active / $total) * 100),
            ];
        }

        return $cohorts;
    }

    private function getReturnedUsers($userIds, $cohortStart, $days): int
    {
        $targetDate = $cohortStart->copy()->addDays($days);

        if ($targetDate->isFuture()) {
            return 0;
        }

        return DB::table('users')
            ->whereIn('id', $userIds)
            ->whereDate('last_login_at', '>=', $targetDate)
            ->count();
    }

    private function getHourlyActivity(): array
    {
        $hourlyData = [];

        if (DB::getSchemaBuilder()->hasTable('activity_logs')) {
            $activities = DB::table('activity_logs')
                ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->groupBy(DB::raw('HOUR(created_at)'))
                ->pluck('count', 'hour')
                ->toArray();

            $maxActivity = max($activities ?: [1]);

            for ($hour = 0; $hour < 24; $hour++) {
                $count        = $activities[$hour] ?? 0;
                $hourlyData[] = [
                    'hour'  => $hour,
                    'value' => $maxActivity > 0 ? round(($count / $maxActivity) * 100) : 0,
                ];
            }
        } else {
            for ($hour = 0; $hour < 24; $hour++) {
                $hourlyData[] = ['hour' => $hour, 'value' => 0];
            }
        }

        return $hourlyData;
    }

    private function getTopActivities($startDate, $endDate): array
    {
        if (!DB::getSchemaBuilder()->hasTable('activity_logs')) {
            return [];
        }

        $activities = DB::table('activity_logs')
            ->select('type', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('type')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $maxCount = $activities->max('count') ?? 1;

        return $activities->map(function ($activity) use ($maxCount) {
            return [
                'name'       => ucfirst(str_replace('_', ' ', $activity->type)),
                'count'      => $activity->count,
                'percentage' => round(($activity->count / $maxCount) * 100),
            ];
        })->toArray();
    }

    private function getLevelDistribution(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('player_profiles')) {
            return [];
        }

        $total = PlayerProfile::count();
        if ($total === 0) return [];

        $ranges = [
            ['min' =>  1, 'max' =>   10, 'label' => '1-10'],
            ['min' => 11, 'max' =>   25, 'label' => '11-25'],
            ['min' => 26, 'max' =>   50, 'label' => '26-50'],
            ['min' => 51, 'max' =>   75, 'label' => '51-75'],
            ['min' => 76, 'max' => 9999, 'label' => '76+'],
        ];

        return array_map(function ($range) use ($total) {
            $count = PlayerProfile::whereBetween('level', [$range['min'], $range['max']])->count();
            return [
                'range'      => $range['label'],
                'percentage' => round(($count / $total) * 100, 1),
            ];
        }, $ranges);
    }
}
