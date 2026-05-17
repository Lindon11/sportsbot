<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use App\Plugins\SportsBot\Models\SportsBotMatchState;
use App\Plugins\SportsBot\Models\SportsBotRun;
use App\Plugins\SportsBot\Models\SportsBotSentAlert;
use App\Plugins\SportsBot\Services\SportsBotRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SportsBotController extends Controller
{
    public function status(SportsBotRunner $runner): JsonResponse
    {
        $latestRun = SportsBotRun::latest('id')->first();

        return response()->json([
            'health' => $runner->health(),
            'latest_run' => $latestRun,
            'counts' => [
                'runs' => SportsBotRun::count(),
                'tracked_matches' => SportsBotMatchState::count(),
                'sent_alerts' => SportsBotSentAlert::count(),
            ],
            'recent_runs' => SportsBotRun::latest('id')->limit(10)->get(),
            'recent_alerts' => SportsBotSentAlert::latest('id')->limit(10)->get(),
        ]);
    }

    public function run(Request $request, SportsBotRunner $runner): JsonResponse
    {
        $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'send' => ['sometimes', 'boolean'],
        ]);

        $send = $request->boolean('send', false);
        $dryRun = $send ? false : $request->boolean('dry_run', true);
        $summary = $runner->run($dryRun, $send ? true : null);

        return response()->json([
            'summary' => $summary,
        ]);
    }
}
