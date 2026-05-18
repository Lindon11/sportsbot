<?php

namespace App\Plugins\SportsBot\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $adminFrontend = $this->adminFrontendPath();

        $steps = array_merge([
            ['name' => 'Clean generated admin assets before Git update', 'run' => fn (): array => $this->normalizeAdminBuildArtifacts($root)],
            ['name' => 'Fetch latest code', 'cmd' => ['git', 'fetch', '--prune', $remote], 'cwd' => $root, 'timeout' => 120],
            ['name' => 'Pull update', 'cmd' => ['git', 'pull', '--ff-only'], 'cwd' => $root, 'timeout' => 120],
        ], $this->postUpdateSteps($adminFrontend));

        try {
            $ok = $this->runSteps($steps, $logs);
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
            'message' => $ok ? 'Update applied successfully.' : $this->failedStepMessage($logs, 'Update stopped because a step failed.'),
            'status' => $finalStatus,
            'logs' => $logs,
        ], $ok ? 200 : 500);
    }

    public function forceSync(Request $request): JsonResponse
    {
        if ($response = $this->adminOnlyResponse()) {
            return $response;
        }

        if ((string) $request->input('confirmation') !== 'RESET_AND_CLEAN') {
            return response()->json([
                'ok' => false,
                'message' => 'Type RESET_AND_CLEAN to confirm force sync.',
                'logs' => [],
            ], 422);
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

        if (!$status['requirements']['git']) {
            return response()->json([
                'ok' => false,
                'message' => 'Git is not available to the web server user.',
                'status' => $status,
                'logs' => [],
            ], 422);
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
        $target = $this->forceSyncTarget($remote);
        $adminFrontend = $this->adminFrontendPath();

        $steps = array_merge([
            ['name' => 'Fetch latest code', 'cmd' => ['git', 'fetch', '--prune', $remote], 'cwd' => $root, 'timeout' => 120],
            ['name' => 'Reset checkout to ' . $target, 'cmd' => ['git', 'reset', '--hard', $target], 'cwd' => $root, 'timeout' => 120],
            ['name' => 'Remove untracked files', 'cmd' => ['git', 'clean', '-fd'], 'cwd' => $root, 'timeout' => 120],
        ], $this->postUpdateSteps($adminFrontend));

        try {
            $ok = $this->runSteps($steps, $logs);
        } finally {
            optional($lock)->release();
        }

        $finalStatus = $this->buildStatus(false);

        Log::warning('sportsbot.admin.force_sync_completed', [
            'ok' => $ok,
            'target' => $target,
            'steps' => count($logs),
            'from' => $status['current_commit'],
            'to' => $finalStatus['current_commit'],
        ]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok ? 'Force sync completed successfully.' : $this->failedStepMessage($logs, 'Force sync stopped because a step failed.'),
            'status' => $finalStatus,
            'logs' => $logs,
        ], $ok ? 200 : 500);
    }

    public function rebuildAdminUi(): JsonResponse
    {
        if ($response = $this->adminOnlyResponse()) {
            return $response;
        }

        $logs = [];
        $status = $this->buildStatus(false);

        if (!$status['enabled']) {
            return response()->json([
                'ok' => false,
                'message' => 'Admin updates are disabled. Set SPORTSBOT_UPDATER_ENABLED=true on the live server to enable admin UI rebuilds.',
                'status' => $status,
                'logs' => [],
            ], 403);
        }

        if (!$status['requirements']['php'] || !$status['requirements']['npm']) {
            return response()->json([
                'ok' => false,
                'message' => 'PHP and NPM must be available to rebuild the admin UI.',
                'status' => $status,
                'logs' => [],
            ], 422);
        }

        $lock = Cache::lock('sportsbot_admin_update', 600);

        if (!$lock->get()) {
            return response()->json([
                'ok' => false,
                'message' => 'Another update or admin UI rebuild is already running.',
                'status' => $status,
                'logs' => [],
            ], 409);
        }

        try {
            $ok = $this->runSteps($this->adminRebuildSteps($this->adminFrontendPath()), $logs);
        } finally {
            optional($lock)->release();
        }

        $finalStatus = $this->buildStatus(false);

        Log::info('sportsbot.admin.rebuild_admin_ui_completed', [
            'ok' => $ok,
            'steps' => count($logs),
            'commit' => $finalStatus['current_commit'],
        ]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok ? 'Admin UI rebuilt successfully.' : $this->failedStepMessage($logs, 'Admin UI rebuild stopped because a step failed.'),
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
            'force_sync_target' => $this->forceSyncTarget($remote),
            'branch' => null,
            'upstream' => null,
            'current_commit' => null,
            'remote_commit' => null,
            'commits_behind' => 0,
            'commits_ahead' => 0,
            'tracked_changes' => [],
            'ignored_tracked_changes' => [],
            'has_tracked_changes' => false,
            'untracked_count' => 0,
            'ignored_untracked_count' => 0,
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
            $tracked = array_values(array_filter(explode("\n", trim($trackedChanges['output']))));
            $status['ignored_tracked_changes'] = array_values(array_filter($tracked, fn (string $line): bool => $this->isAdminBuildArtifactStatusLine($line)));
            $status['tracked_changes'] = array_values(array_filter($tracked, fn (string $line): bool => !$this->isAdminBuildArtifactStatusLine($line)));
            $status['has_tracked_changes'] = count($status['tracked_changes']) > 0;
        }

        $untracked = $this->runCommand(['git', 'ls-files', '--others', '--exclude-standard'], $root);
        if ($untracked['ok'] && trim($untracked['output']) !== '') {
            $untrackedFiles = array_values(array_filter(explode("\n", trim($untracked['output']))));
            $status['ignored_untracked_count'] = count(array_filter($untrackedFiles, fn (string $path): bool => $this->isAdminBuildArtifactPath($path)));
            $status['untracked_count'] = count(array_filter($untrackedFiles, fn (string $path): bool => !$this->isAdminBuildArtifactPath($path)));
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

    private function adminFrontendPath(): string
    {
        $path = trim((string) config('plugins.SportsBot.updater.admin_frontend_path', 'resources/admin'));

        if ($path !== '' && str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path !== '' ? $path : 'resources/admin');
    }

    private function forceSyncTarget(string $remote): string
    {
        return trim((string) config('plugins.SportsBot.updater.force_sync_target', "{$remote}/main")) ?: "{$remote}/main";
    }

    private function postUpdateSteps(string $adminFrontend): array
    {
        return [
            ['name' => 'Install PHP dependencies', 'cmd' => ['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'], 'cwd' => base_path(), 'timeout' => 300],
            ['name' => 'Clear optimized Laravel caches', 'cmd' => ['php', 'artisan', 'optimize:clear'], 'cwd' => base_path(), 'timeout' => 120],
            ['name' => 'Run database migrations', 'cmd' => ['php', 'artisan', 'migrate', '--force'], 'cwd' => base_path(), 'timeout' => 300],
            ['name' => 'Ensure storage link', 'cmd' => ['php', 'artisan', 'storage:link'], 'cwd' => base_path(), 'timeout' => 120],
            ...$this->adminRebuildSteps($adminFrontend),
        ];
    }

    private function adminRebuildSteps(string $adminFrontend): array
    {
        $before = [];

        return [
            ['name' => 'Check admin frontend source', 'run' => fn (): array => $this->checkAdminFrontend($adminFrontend)],
            ['name' => 'Install admin frontend dependencies', 'cmd' => ['npm', 'ci', '--include=dev'], 'cwd' => $adminFrontend, 'timeout' => 300],
            ['name' => 'Snapshot admin assets before build', 'run' => function () use (&$before): array {
                $before = $this->adminAssetsSnapshot();

                return [
                    'ok' => true,
                    'exit_code' => 0,
                    'output' => $this->formatAdminAssetsSnapshot('Before build', $before),
                ];
            }],
            ['name' => 'Build admin frontend', 'cmd' => ['npm', 'run', 'build'], 'cwd' => $adminFrontend, 'timeout' => 600],
            ['name' => 'Verify admin assets changed', 'run' => fn (): array => $this->verifyAdminAssetsChanged($before)],
            ['name' => 'Clear Laravel caches after admin rebuild', 'cmd' => ['php', 'artisan', 'optimize:clear'], 'cwd' => base_path(), 'timeout' => 120],
            ['name' => 'Cache config', 'cmd' => ['php', 'artisan', 'config:cache'], 'cwd' => base_path(), 'timeout' => 120],
            ['name' => 'Cache views', 'cmd' => ['php', 'artisan', 'view:cache'], 'cwd' => base_path(), 'timeout' => 120],
        ];
    }

    private function normalizeAdminBuildArtifacts(string $root): array
    {
        $restore = $this->runCommand([
            'git',
            'restore',
            '--',
            ...$this->adminBuildArtifactPaths(),
        ], $root, 120);

        $clean = $this->runCommand([
            'git',
            'clean',
            '-fd',
            '--',
            'backend/public/admin/assets',
        ], $root, 120);

        $output = [
            'git restore -- ' . implode(' ', $this->adminBuildArtifactPaths()),
            $restore['output'] !== '' ? $restore['output'] : '(no output)',
            '',
            'git clean -fd -- backend/public/admin/assets',
            $clean['output'] !== '' ? $clean['output'] : '(no output)',
        ];

        return [
            'ok' => $restore['ok'] && $clean['ok'],
            'exit_code' => $restore['ok'] ? $clean['exit_code'] : $restore['exit_code'],
            'output' => implode("\n", $output),
        ];
    }

    private function checkAdminFrontend(string $adminFrontend): array
    {
        $required = [
            $adminFrontend,
            $adminFrontend . '/package.json',
            $adminFrontend . '/package-lock.json',
            $adminFrontend . '/vite.config.js',
        ];

        $missing = array_values(array_filter($required, static fn (string $path): bool => !file_exists($path)));

        if ($missing !== []) {
            return [
                'ok' => false,
                'exit_code' => null,
                'output' => 'Missing admin frontend path(s):' . "\n" . implode("\n", $missing),
            ];
        }

        return [
            'ok' => true,
            'exit_code' => 0,
            'output' => 'Admin frontend path: ' . $adminFrontend . "\n" . 'Build output path: ' . public_path('admin'),
        ];
    }

    private function verifyAdminAssetsChanged(array $before): array
    {
        clearstatcache();

        $after = $this->adminAssetsSnapshot();
        $indexExists = is_file(public_path('admin/index.html'));
        $assetsExist = is_dir(public_path('admin/assets')) && (int) ($after['asset_count'] ?? 0) > 0;
        $changed = ($after['signature'] ?? '') !== ($before['signature'] ?? '')
            || (int) ($after['latest_mtime'] ?? 0) > (int) ($before['latest_mtime'] ?? 0)
            || (int) ($after['index_mtime'] ?? 0) > (int) ($before['index_mtime'] ?? 0);

        $output = $this->formatAdminAssetsSnapshot('Before build', $before)
            . "\n\n"
            . $this->formatAdminAssetsSnapshot('After build', $after);

        if (!$indexExists || !$assetsExist) {
            return [
                'ok' => false,
                'exit_code' => null,
                'output' => $output . "\n\n" . 'Admin build did not produce public/admin/index.html and public/admin/assets.',
            ];
        }

        if (!$changed) {
            return [
                'ok' => false,
                'exit_code' => null,
                'output' => $output . "\n\n" . 'Admin assets did not change after npm run build.',
            ];
        }

        return [
            'ok' => true,
            'exit_code' => 0,
            'output' => $output . "\n\n" . 'Admin assets changed and are ready to serve.',
        ];
    }

    private function adminAssetsSnapshot(): array
    {
        $assetsPath = public_path('admin/assets');
        $indexPath = public_path('admin/index.html');
        $files = is_dir($assetsPath) ? glob($assetsPath . '/*') : [];
        $files = is_array($files) ? array_values(array_filter($files, 'is_file')) : [];
        sort($files);

        $latestMtime = 0;
        $totalBytes = 0;
        $parts = [];

        foreach ($files as $file) {
            $mtime = (int) filemtime($file);
            $bytes = (int) filesize($file);
            $latestMtime = max($latestMtime, $mtime);
            $totalBytes += $bytes;
            $parts[] = basename($file) . ':' . $bytes . ':' . $mtime;
        }

        $indexMtime = is_file($indexPath) ? (int) filemtime($indexPath) : 0;
        $indexBytes = is_file($indexPath) ? (int) filesize($indexPath) : 0;
        $signature = sha1(implode('|', $parts) . '|index:' . $indexBytes . ':' . $indexMtime);

        return [
            'asset_path' => $assetsPath,
            'index_path' => $indexPath,
            'asset_count' => count($files),
            'total_bytes' => $totalBytes,
            'latest_mtime' => $latestMtime,
            'latest_mtime_label' => $latestMtime > 0 ? date('c', $latestMtime) : 'missing',
            'index_mtime' => $indexMtime,
            'index_mtime_label' => $indexMtime > 0 ? date('c', $indexMtime) : 'missing',
            'signature' => $signature,
        ];
    }

    private function formatAdminAssetsSnapshot(string $label, array $snapshot): string
    {
        return $label . ':'
            . "\n" . 'Assets path: ' . (string) ($snapshot['asset_path'] ?? public_path('admin/assets'))
            . "\n" . 'Index path: ' . (string) ($snapshot['index_path'] ?? public_path('admin/index.html'))
            . "\n" . 'Asset count: ' . (string) ($snapshot['asset_count'] ?? 0)
            . "\n" . 'Total bytes: ' . (string) ($snapshot['total_bytes'] ?? 0)
            . "\n" . 'Latest asset mtime: ' . (string) ($snapshot['latest_mtime_label'] ?? 'missing')
            . "\n" . 'Index mtime: ' . (string) ($snapshot['index_mtime_label'] ?? 'missing')
            . "\n" . 'Signature: ' . (string) ($snapshot['signature'] ?? '');
    }

    private function binaryAvailable(string $binary, string $cwd): bool
    {
        return $this->runCommand([$binary, '--version'], $cwd, 20)['ok'];
    }

    private function isAdminBuildArtifactStatusLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        $path = trim(substr($line, 3));
        if (str_contains($path, ' -> ')) {
            [$from, $to] = array_pad(explode(' -> ', $path, 2), 2, '');

            return $this->isAdminBuildArtifactPath($from) || $this->isAdminBuildArtifactPath($to);
        }

        return $this->isAdminBuildArtifactPath($path);
    }

    private function isAdminBuildArtifactPath(string $path): bool
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");

        foreach ($this->adminBuildArtifactPaths() as $artifactPath) {
            if ($path === $artifactPath || str_starts_with($path, rtrim($artifactPath, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function adminBuildArtifactPaths(): array
    {
        return [
            'backend/public/admin/index.html',
            'backend/public/admin/assets',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @param array<int, array{step:string,ok:bool,exit_code:int|null,output:string}> $logs
     */
    private function runSteps(array $steps, array &$logs): bool
    {
        foreach ($steps as $step) {
            if (isset($step['run']) && is_callable($step['run'])) {
                $result = $step['run']();
            } else {
                $result = $this->runCommand($step['cmd'], $step['cwd'], $step['timeout']);
            }

            $logs[] = [
                'step' => (string) $step['name'],
                'ok' => (bool) ($result['ok'] ?? false),
                'exit_code' => $result['exit_code'] ?? null,
                'output' => (string) ($result['output'] ?? ''),
            ];

            if (!($result['ok'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{step:string,ok:bool,exit_code:int|null,output:string}> $logs
     */
    private function failedStepMessage(array $logs, string $fallback): string
    {
        foreach (array_reverse($logs) as $log) {
            if (($log['ok'] ?? true) === true) {
                continue;
            }

            $output = trim((string) ($log['output'] ?? ''));
            $firstLine = trim((string) preg_split('/\R/', $output)[0]);

            if ($firstLine !== '') {
                return 'Failed at "' . $log['step'] . '": ' . substr($firstLine, 0, 220);
            }

            return 'Failed at "' . $log['step'] . '".';
        }

        return $fallback;
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
