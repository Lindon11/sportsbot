<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use App\Core\Models\PlayerProfile;
use App\Core\Models\User;
use App\Core\Services\MetricsRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'players' => [
                'total' => User::count(),
                'online' => User::where('updated_at', '>=', now()->subMinutes(15))->count(),
                'active_today' => User::where('updated_at', '>=', now()->startOfDay())->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ],
            'activity' => [
                'crimes_today' => MetricsRegistry::get('crimes_today'),
                'combat_today' => MetricsRegistry::get('combats_today'),
                'gangs' => MetricsRegistry::get('active_gangs'),
                'users' => User::count(),
            ],
            'economy' => [
                'total_cash' => PlayerProfile::sum('cash'),
                'total_bank' => PlayerProfile::sum('bank'),
                'average_level' => round(PlayerProfile::avg('level'), 1),
                'total_respect' => PlayerProfile::sum('respect'),
            ],
            'top_players' => User::with('profile')->latest()->take(5)->get(),
            'recent_signups' => User::latest()->take(5)->get(),
        ];

        return response()->json([
            'stats' => $stats,
        ]);
    }

    public function playerActivity()
    {
        $hourly = DB::table('players')
            ->select(DB::raw('HOUR(updated_at) as hour'), DB::raw('COUNT(*) as count'))
            ->where('updated_at', '>=', now()->subDay())
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $daily = DB::table('players')
            ->select(DB::raw('DATE(updated_at) as date'), DB::raw('COUNT(DISTINCT id) as count'))
            ->where('updated_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'hourly' => $hourly,
            'daily' => $daily,
        ]);
    }

    public function serverHealth()
    {
        $health = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'storage' => $this->checkStorageHealth(),
        ];

        return response()->json($health);
    }

    protected function checkDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
            ];
        } catch (\Exception $e) {
            Log::error('Database health check failed: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => app()->environment('local') ? $e->getMessage() : 'Database connection failed.',
            ];
        }
    }

    protected function checkCacheHealth(): array
    {
        try {
            Cache::put('health_check', true, 60);
            $result = Cache::get('health_check');

            return [
                'status' => $result ? 'healthy' : 'warning',
            ];
        } catch (\Exception $e) {
            Log::error('Cache health check failed: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => app()->environment('local') ? $e->getMessage() : 'Cache connection failed.',
            ];
        }
    }

    protected function checkStorageHealth(): array
    {
        try {
            $path = storage_path();
            $total = disk_total_space($path);
            $free = disk_free_space($path);
            $used = $total - $free;
            $percentage = round(($used / $total) * 100, 2);

            return [
                'status' => $percentage < 90 ? 'healthy' : 'warning',
                'used_percentage' => $percentage,
                'free_gb' => round($free / 1024 / 1024 / 1024, 2),
            ];
        } catch (\Exception $e) {
            Log::error('Storage health check failed: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => app()->environment('local') ? $e->getMessage() : 'Storage check failed.',
            ];
        }
    }
}
