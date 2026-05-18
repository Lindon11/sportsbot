<?php

namespace App\Plugins\SportsBot\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SportsBotAssetCache
{
    private const IMAGE_FIELDS = [
        'home_badge' => 'team_logo',
        'away_badge' => 'team_logo',
        'home_logo' => 'team_logo',
        'away_logo' => 'team_logo',
        'strHomeTeamBadge' => 'team_logo',
        'strAwayTeamBadge' => 'team_logo',
        'league_badge' => 'league_badge',
        'league_logo' => 'league_badge',
        'strLeagueBadge' => 'league_badge',
        'strLeagueLogo' => 'league_badge',
        'event_thumb' => 'event_poster',
        'event_poster' => 'event_poster',
        'strThumb' => 'event_poster',
        'strPoster' => 'event_poster',
        'tv_channel_logo' => 'tv_channel_logo',
        'channel_logo' => 'tv_channel_logo',
        'sponsor_logo' => 'sponsor_logo',
        'background_image' => 'background',
        'venue_image' => 'venue_image',
        'fighter_home_image' => 'fighter_image',
        'fighter_away_image' => 'fighter_image',
        'highlight_thumbnail' => 'highlight_thumbnail',
        'youtube_thumbnail' => 'highlight_thumbnail',
    ];

    /**
     * @return array{fixture:array<string,mixed>,assets:array<int,array<string,mixed>>,failures:array<int,array<string,string>>,summary:array<string,mixed>}
     */
    public function cacheFixtureAssets(array $fixture): array
    {
        $assets = [];
        $failures = [];
        $rewritten = $fixture;

        foreach (self::IMAGE_FIELDS as $field => $type) {
            $url = trim((string) ($fixture[$field] ?? ''));
            if ($url === '') {
                continue;
            }

            $result = $this->cacheUrl($url, $type);
            if (($result['ok'] ?? false) === true) {
                $rewritten[$field] = $result['file_uri'];
                $assets[] = [
                    'field' => $field,
                    'type' => $type,
                    'source_url' => $url,
                    'local_path' => $result['local_path'],
                    'file_uri' => $result['file_uri'],
                    'bytes' => $result['bytes'],
                    'sha256' => $result['sha256'],
                    'cached' => $result['cached'],
                ];
            } else {
                $failures[] = [
                    'field' => $field,
                    'type' => $type,
                    'source_url' => $url,
                    'reason' => (string) ($result['reason'] ?? 'asset_cache_failed'),
                ];
            }
        }

        return [
            'fixture' => $rewritten,
            'assets' => $assets,
            'failures' => $failures,
            'summary' => [
                'cached' => count($assets),
                'failed' => count($failures),
                'status' => $failures === [] ? 'cached' : 'failed',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cacheUrl(string $url, string $type = 'image'): array
    {
        if ($this->isLocalReadable($url)) {
            $path = $this->localPathFromUri($url);

            return [
                'ok' => true,
                'cached' => true,
                'local_path' => $path,
                'file_uri' => $this->fileUri($path),
                'bytes' => (int) @filesize($path),
                'sha256' => hash_file('sha256', $path) ?: '',
            ];
        }

        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'reason' => 'unsupported_asset_url'];
        }

        $existing = $this->lookupBySource($url);
        if ($existing !== null) {
            return $existing + ['ok' => true, 'cached' => true];
        }

        try {
            $response = Http::retry(
                max(0, (int) config('plugins.SportsBot.cards.asset_cache_retries', 2)),
                max(50, (int) config('plugins.SportsBot.cards.asset_cache_retry_delay_ms', 250))
            )
                ->timeout(max(1, (int) config('plugins.SportsBot.cards.asset_cache_timeout', 12)))
                ->withHeaders(['User-Agent' => (string) config('plugins.SportsBot.scrapers.user_agent', 'SportsBot Renderer')])
                ->get($url);

            if (!$response->successful()) {
                return ['ok' => false, 'reason' => 'http_' . $response->status()];
            }

            $body = $response->body();
            if ($body === '') {
                return ['ok' => false, 'reason' => 'empty_asset_body'];
            }

            if (@getimagesizefromstring($body) === false) {
                return ['ok' => false, 'reason' => 'invalid_image_asset'];
            }

            $sha256 = hash('sha256', $body);
            $extension = $this->extensionFor($response->header('Content-Type'), $url);
            $dir = $this->assetDirectory($type);
            $path = $dir . '/' . $sha256 . '.' . $extension;

            if (!@is_dir($dir) && !@mkdir($dir, 0775, true) && !@is_dir($dir)) {
                return ['ok' => false, 'reason' => 'asset_directory_unwritable'];
            }

            if (!@is_file($path) && @file_put_contents($path, $body, LOCK_EX) === false) {
                return ['ok' => false, 'reason' => 'asset_write_failed'];
            }

            @touch($path);
            $this->writeSourceMap($url, $path, $sha256, $type);

            return [
                'ok' => true,
                'cached' => false,
                'local_path' => $path,
                'file_uri' => $this->fileUri($path),
                'bytes' => strlen($body),
                'sha256' => $sha256,
            ];
        } catch (Throwable $error) {
            Log::warning('sportsbot.assets.cache_failed', [
                'url' => $url,
                'type' => $type,
                'error' => $error->getMessage(),
            ]);

            return ['ok' => false, 'reason' => $error->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $root = $this->rootDirectory();
        $files = is_dir($root) ? glob($root . '/*/*') ?: [] : [];
        $bytes = 0;
        $missing = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $bytes += (int) filesize($file);
            if (!is_readable($file)) {
                $missing[] = $file;
            }
        }

        return [
            'root' => $root,
            'files' => count(array_filter($files, 'is_file')),
            'bytes' => $bytes,
            'unreadable' => $missing,
            'cleanup_after_days' => (int) config('plugins.SportsBot.cards.asset_cache_stale_days', 30),
        ];
    }

    public function pruneStale(?int $days = null): array
    {
        $days ??= max(1, (int) config('plugins.SportsBot.cards.asset_cache_stale_days', 30));
        $cutoff = time() - ($days * 86400);
        $root = $this->rootDirectory();
        $files = is_dir($root) ? glob($root . '/*/*') ?: [] : [];
        $deleted = 0;
        $bytes = 0;

        foreach ($files as $file) {
            if (!is_file($file) || str_ends_with($file, '.json')) {
                continue;
            }

            if ((int) @filemtime($file) >= $cutoff) {
                continue;
            }

            $bytes += (int) @filesize($file);
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'bytes' => $bytes, 'days' => $days, 'root' => $root];
    }

    private function lookupBySource(string $url): ?array
    {
        $map = $this->sourceMapPath($url);
        if (!is_file($map)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($map), true);
        $path = is_array($data) ? (string) ($data['local_path'] ?? '') : '';

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        @touch($path);

        return [
            'local_path' => $path,
            'file_uri' => $this->fileUri($path),
            'bytes' => (int) filesize($path),
            'sha256' => (string) ($data['sha256'] ?? (hash_file('sha256', $path) ?: '')),
        ];
    }

    private function writeSourceMap(string $url, string $path, string $sha256, string $type): void
    {
        $map = $this->sourceMapPath($url);
        $dir = dirname($map);
        if (!@is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($map, json_encode([
            'source_url' => $url,
            'local_path' => $path,
            'sha256' => $sha256,
            'type' => $type,
            'cached_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function sourceMapPath(string $url): string
    {
        return $this->rootDirectory() . '/sources/' . sha1($url) . '.json';
    }

    private function assetDirectory(string $type): string
    {
        return $this->rootDirectory() . '/' . preg_replace('/[^a-z0-9_-]+/i', '-', $type);
    }

    private function rootDirectory(): string
    {
        return storage_path('app/sportsbot/assets');
    }

    private function extensionFor(?string $contentType, string $url): string
    {
        $contentType = strtolower((string) $contentType);
        if (str_contains($contentType, 'png')) {
            return 'png';
        }
        if (str_contains($contentType, 'webp')) {
            return 'webp';
        }
        if (str_contains($contentType, 'gif')) {
            return 'gif';
        }
        if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
            return 'jpg';
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'img';
    }

    private function isLocalReadable(string $url): bool
    {
        $path = $this->localPathFromUri($url);

        return $path !== '' && is_file($path) && is_readable($path);
    }

    private function localPathFromUri(string $url): string
    {
        if (str_starts_with($url, 'file://')) {
            return (string) parse_url($url, PHP_URL_PATH);
        }

        return str_starts_with($url, '/') ? $url : '';
    }

    private function fileUri(string $path): string
    {
        return 'file://' . str_replace('%2F', '/', rawurlencode($path));
    }
}
