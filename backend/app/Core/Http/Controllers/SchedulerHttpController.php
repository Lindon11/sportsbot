<?php

namespace App\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SchedulerHttpController extends Controller
{
    public function run(string $token): JsonResponse
    {
        $expected = trim((string) config('app.scheduler_http_token', ''));

        if ($expected === '' || !hash_equals($expected, $token)) {
            Log::warning('scheduler.http.token_mismatch', [
                'expected_length' => strlen($expected),
                'received_length' => strlen($token),
                'base_path' => base_path(),
                'config_cached' => app()->configurationIsCached(),
            ]);
            abort(404);
        }

        $lock = Cache::lock('http_scheduler_run', 55);

        if (!$lock->get()) {
            return response()->json([
                'ok' => false,
                'message' => 'Scheduler is already running.',
            ], 409);
        }

        try {
            Artisan::call('schedule:run');
            Cache::put('http_scheduler_last_run_at', now()->toISOString(), now()->addDays(7));

            return response()->json([
                'ok' => true,
                'message' => 'Scheduler ran.',
                'ran_at' => now()->toISOString(),
                'output' => trim(Artisan::output()),
            ]);
        } finally {
            optional($lock)->release();
        }
    }
}
