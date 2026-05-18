<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class UpdateController extends Controller
{
    public function check(): JsonResponse
    {
        $root = base_path('..');
        $current = trim($this->runCommand(['git', 'rev-parse', '--short', 'HEAD'], $root)['output']);
        $branch = trim($this->runCommand(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $root)['output']);

        $this->runCommand(['git', 'fetch', 'origin'], $root);
        $behind = trim($this->runCommand(['git', 'rev-list', '--count', 'HEAD..origin/' . $branch], $root)['output']);

        return response()->json([
            'ok' => true,
            'current_commit' => $current,
            'branch' => $branch,
            'commits_behind' => (int) $behind,
            'update_available' => (int) $behind > 0,
        ]);
    }

    public function update(): JsonResponse
    {
        $logs = [];
        $root = base_path('..');

        $steps = [
            ['name' => 'git pull', 'cmd' => ['git', 'pull'], 'cwd' => $root],
            ['name' => 'composer install', 'cmd' => ['composer', 'install', '--no-dev', '--optimize-autoloader'], 'cwd' => base_path()],
            ['name' => 'artisan migrate', 'cmd' => ['php', 'artisan', 'migrate', '--force'], 'cwd' => base_path()],
            ['name' => 'artisan config:cache', 'cmd' => ['php', 'artisan', 'config:cache'], 'cwd' => base_path()],
            ['name' => 'artisan route:cache', 'cmd' => ['php', 'artisan', 'route:cache'], 'cwd' => base_path()],
            ['name' => 'artisan view:cache', 'cmd' => ['php', 'artisan', 'view:cache'], 'cwd' => base_path()],
            ['name' => 'npm ci', 'cmd' => ['npm', 'ci'], 'cwd' => $root],
            ['name' => 'npm run build', 'cmd' => ['npm', 'run', 'build'], 'cwd' => $root],
        ];

        $ok = true;

        foreach ($steps as $step) {
            $result = $this->runCommand($step['cmd'], $step['cwd'], 120);

            $logs[] = [
                'step' => $step['name'],
                'ok' => $result['ok'],
                'output' => $result['output'],
            ];

            if (!$result['ok']) {
                $ok = false;
                break;
            }
        }

        Log::info('sportsbot.admin.update_completed', ['ok' => $ok, 'steps' => count($logs)]);

        return response()->json([
            'ok' => $ok,
            'logs' => $logs,
        ]);
    }

    /**
     * @param array<int, string> $command
     */
    private function runCommand(array $command, string $cwd, int $timeout = 60): array
    {
        try {
            $process = new Process($command, $cwd);
            $process->setTimeout($timeout);
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'output' => trim($process->getOutput() ?: $process->getErrorOutput()),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'output' => $e->getMessage(),
            ];
        }
    }
}
