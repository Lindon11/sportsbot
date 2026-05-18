<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class UpdateController extends Controller
{
    public function check(): JsonResponse
    {
        if ($response = $this->adminOnlyResponse()) {
            return $response;
        }

        return response()->json($this->buildStatus(true));
    }

    public function update(): JsonResponse
    {
        if ($response = $this->adminOnlyResponse()) {
            return $response;
        }

        $logs = [];
        $status = $this->buildStatus(true);

        if (!$status['enabled']) {
            return response()->json([
                'ok' => false,
                'message' => 'Admin updates are disabled. Set SPORTSBOT_UPDATER_ENABLED=true on the live server to enable them.',
                'status' => $status,
                'logs' => [],
            ], 403);
        }

        if (!$status['repository_ready']) {
            return response()->json([
                'ok' => false,
                'message' => $status['message'] ?: 'This install is not ready for Git updates.',
                'status' => $status,
                'logs' => [],
            ], 422);
        }

        if ($status['has_tracked_changes']) {
            return response()->json([
                'ok' => false,
                'message' => 'The live checkout has local tracked file changes. Commit, stash, or remove those before updating.',
                'status' => $status,
                'logs' => [],
            ], 409);
        }

        if ($status['commits_ahead'] > 0) {
            return response()->json([
                'ok' => false,
                'message' => 'The live checkout has commits that are not on the remote branch. The updater will not overwrite them.',
                'status' => $status,
                'logs' => [],
            ], 409);
        }

        if (!$status['update_available']) {
            return response()->json([
                'ok' => true,
                'message' => 'Already up to date.',
                'status' => $status,
                'logs' => [],
            ]);
        }

        $lock = Cache::lock('sportsbot_admin_update', 600);

        if (!$lock->get()) {
            return response()->json([
                'ok' => false,
                'message' => 'Another update is already running.',
                'status' => $status,
                'logs' => [],
            ], 409);
        }

        $root = $this->repositoryRoot();
        $remote = $this->remoteName();
        $adminFrontend = base_path(config('plugins.SportsBot.updater.admin_frontend_path', 'resources/admin'));

        $steps = [
            ['name' => 'Fetch latest code', 'cmd' => ['git', 'fetch', '--prune', $remote], 'cwd' => $root, 'timeout' => 120],
            ['name' => 'Pull update', 'cmd' => ['git', 'pull', '--ff-only'], 'cwd' => $root, 'timeout' => 120],
            ['name' => 'Install PHP dependencies', 'cmd' => ['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'], 'cwd' => base_path(), 'timeout' => 300],
            ['name' => 'Clear optimized Laravel caches', 'cmd' => ['php', 'artisan', 'optimize:clear'], 'cwd' => base_path(), 'timeout' => 120],
            ['name' => 'Run database migrations', 'cmd' => ['php', 'artisan', 'migrate', '--force'], 'cwd' => base_path(), 'timeout' => 300],
            ['name' => 'Ensure storage link', 'cmd' => ['php', 'artisan', 'storage:link'], 'cwd' => base_path(), 'timeout' => 120],
            ['name' => 'Install admin frontend dependencies', 'cmd' => ['npm', 'ci'], 'cwd' => $adminFrontend, 'timeout' => 300],
            ['name' => 'Build admin frontend', 'cmd' => ['npm', 'run', 'build'], 'cwd' => $adminFrontend, 'timeout' => 600],
            ['name' => 'Cache config', 'cmd' => ['php', 'artisan', 'config:cache'], 'cwd' => base_path(), 'timeout' => 120],
            ['name' => 'Cache views', 'cmd' => ['php', 'artisan', 'view:cache'], 'cwd' => base_path(), 'timeout' => 120],
        ];

        $ok = true;

        try {
            foreach ($steps as $step) {
                $result = $this->runCommand($step['cmd'], $step['cwd'], $step['timeout']);

                $logs[] = [
                    'step' => $step['name'],
                    'ok' => $result['ok'],
                    'exit_code' => $result['exit_code'],
                    'output' => $result['output'],
                ];

                if (!$result['ok']) {
                    $ok = false;
                    break;
                }
            }
        } finally {
            optional($lock)->release();
        }

        $finalStatus = $this->buildStatus(false);

        Log::info('sportsbot.admin.update_completed', [
            'ok' => $ok,
            'steps' => count($logs),
            'from' => $status['current_commit'],
            'to' => $finalStatus['current_commit'],
        ]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok ? 'Update applied successfully.' : 'Update stopped because a step failed.',
            'status' => $finalStatus,
            'logs' => $logs,
        ], $ok ? 200 : 500);
    }

    private function buildStatus(bool $fetch): array
    {
        $root = $this->repositoryRoot();
        $remote = $this->remoteName();
        $status = [
            'ok' => true,
            'enabled' => $this->enabled(),
            'repository_ready' => false,
            'can_update' => false,
            'message' => null,
            'root' => $root,
            'remote' => $remote,
            'branch' => null,
            'upstream' => null,
            'current_commit' => null,
            'remote_commit' => null,
            'commits_behind' => 0,
            'commits_ahead' => 0,
            'tracked_changes' => [],
            'has_tracked_changes' => false,
            'untracked_count' => 0,
            'update_available' => false,
            'requirements' => [
                'git' => false,
                'php' => false,
                'composer' => false,
                'npm' => false,
            ],
        ];

        foreach (array_keys($status['requirements']) as $binary) {
            $status['requirements'][$binary] = $this->binaryAvailable($binary, $root);
        }

        if (!$status['requirements']['git']) {
            $status['ok'] = false;
            $status['message'] = 'Git is not available to the web server user.';
            return $status;
        }

        if (!is_dir($root . '/.git')) {
            $status['ok'] = false;
            $status['message'] = 'The live install is not a Git checkout.';
            return $status;
        }

        $branch = $this->runCommand(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $root);
        if (!$branch['ok']) {
            $status['ok'] = false;
            $status['message'] = $branch['output'] ?: 'Could not read the current Git branch.';
            return $status;
        }

        $status['branch'] = trim($branch['output']);
        if ($status['branch'] === 'HEAD') {
            $status['ok'] = false;
            $status['message'] = 'The live checkout is in detached HEAD mode. Check out a branch before using the updater.';
            return $status;
        }

        $current = $this->runCommand(['git', 'rev-parse', '--short', 'HEAD'], $root);
        $status['current_commit'] = $current['ok'] ? trim($current['output']) : null;

        $upstream = $this->runCommand(['git', 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $root);
        if (!$upstream['ok']) {
            $status['ok'] = false;
            $status['message'] = 'This branch does not have an upstream remote branch set.';
            return $status;
        }
        $status['upstream'] = trim($upstream['output']);

        if ($fetch && $status['enabled']) {
            $fetchResult = $this->runCommand(['git', 'fetch', '--prune', $remote], $root, 120);
            if (!$fetchResult['ok']) {
                $status['ok'] = false;
                $status['message'] = $fetchResult['output'] ?: 'Failed to fetch the latest Git updates.';
                return $status;
            }
        }

        $remoteCommit = $this->runCommand(['git', 'rev-parse', '--short', $status['upstream']], $root);
        $status['remote_commit'] = $remoteCommit['ok'] ? trim($remoteCommit['output']) : null;

        $aheadBehind = $this->runCommand(['git', 'rev-list', '--left-right', '--count', 'HEAD...' . $status['upstream']], $root);
        if ($aheadBehind['ok']) {
            $parts = preg_split('/\s+/', trim($aheadBehind['output']));
            $status['commits_ahead'] = (int) ($parts[0] ?? 0);
            $status['commits_behind'] = (int) ($parts[1] ?? 0);
        }

        $trackedChanges = $this->runCommand(['git', 'status', '--porcelain', '--untracked-files=no'], $root);
        if ($trackedChanges['ok']) {
            $status['tracked_changes'] = array_values(array_filter(explode("\n", trim($trackedChanges['output']))));
            $status['has_tracked_changes'] = count($status['tracked_changes']) > 0;
        }

        $untracked = $this->runCommand(['git', 'ls-files', '--others', '--exclude-standard'], $root);
        if ($untracked['ok'] && trim($untracked['output']) !== '') {
            $status['untracked_count'] = count(array_filter(explode("\n", trim($untracked['output']))));
        }

        $status['repository_ready'] = true;
        $status['update_available'] = $status['commits_behind'] > 0;
        $status['can_update'] = $status['enabled']
            && $status['repository_ready']
            && $status['update_available']
            && !$status['has_tracked_changes']
            && $status['commits_ahead'] === 0
            && !in_array(false, $status['requirements'], true);

        if (!$status['enabled']) {
            $status['message'] = 'Admin updates are disabled until SPORTSBOT_UPDATER_ENABLED=true is set.';
        } elseif (in_array(false, $status['requirements'], true)) {
            $status['message'] = 'One or more required command-line tools are missing.';
        } elseif ($status['has_tracked_changes']) {
            $status['message'] = 'Local tracked file changes must be cleaned up before updating.';
        } elseif ($status['commits_ahead'] > 0) {
            $status['message'] = 'The live checkout has commits ahead of the remote branch.';
        } elseif (!$status['update_available']) {
            $status['message'] = 'Already up to date.';
        }

        return $status;
    }

    private function enabled(): bool
    {
        return filter_var(config('plugins.SportsBot.updater.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function repositoryRoot(): string
    {
        return realpath(base_path('..')) ?: base_path('..');
    }

    private function remoteName(): string
    {
        return (string) config('plugins.SportsBot.updater.remote', 'origin');
    }

    private function binaryAvailable(string $binary, string $cwd): bool
    {
        return $this->runCommand([$binary, '--version'], $cwd, 20)['ok'];
    }

    private function adminOnlyResponse(): ?JsonResponse
    {
        $user = request()->user();

        if ($user && method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'message' => 'Only admins can run live updates.',
        ], 403);
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
                'exit_code' => $process->getExitCode(),
                'output' => trim($process->getOutput() . "\n" . $process->getErrorOutput()),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => null,
                'output' => $e->getMessage(),
            ];
        }
    }
}
