<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show($id)
    {
        $player = User::findOrFail($id);

        // Crime stats — only available when Crimes plugin is loaded
        $crimeStats = [
            'total_attempts'       => 0,
            'successful'           => 0,
            'failed'               => 0,
            'success_rate'         => 0,
            'total_earnings'       => 0,
            'total_respect_earned' => 0,
        ];
        $recentCrimes = collect();

        if (app()->bound('crimes.service')) {
            $svc = app('crimes.service');
            $raw = $svc->getPlayerStats($player->id);
            $crimeStats = array_merge($crimeStats, $raw);
            $crimeStats['success_rate'] = $crimeStats['total_attempts'] > 0
                ? round(($crimeStats['successful'] / $crimeStats['total_attempts']) * 100, 2)
                : 0;
            $recentCrimes = $svc->getRecentAttempts($player->id, 10)
                ->map(function ($attempt) {
                    return [
                        'id'         => $attempt->id,
                        'crime_name' => $attempt->crime->name ?? 'Unknown',
                        'success'    => $attempt->success,
                        'cash_earned' => $attempt->cash_earned,
                        'time_ago'   => $attempt->attempted_at->diffForHumans(),
                    ];
                });
        }

        // Get rank title
        $rank = DB::table('ranks')
            ->where('required_level', '<=', $player->level)
            ->orderBy('required_level', 'desc')
            ->first();

        return response()->json([
            'player' => [
                'id' => $player->id,
                'username' => $player->username,
                'level' => $player->level,
                'experience' => $player->experience,
                'rank_title' => $rank->name ?? 'Thug',
                'respect' => $player->respect,
                'cash' => $player->cash,
                'bank' => $player->bank,
                'networth' => $player->cash + $player->bank,
                'health' => $player->health,
                'max_health' => $player->max_health,
                'energy' => $player->energy,
                'max_energy' => $player->max_energy,
                'bullets' => $player->bullets,
                'location' => $player->location,
                'created_at' => $player->created_at->toIso8601String(),
                'last_active' => $player->last_active ? $player->last_active->toIso8601String() : null,
            ],
            'stats' => $crimeStats,
            'recent_crimes' => $recentCrimes,
            'is_own_profile' => Auth::id() === $player->id,
        ]);
    }
}
