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
                $localPath = (string) ($result['local_path'] ?? '');
                $dataUri = $this->dataUriForLocalImage($localPath);

                if ($dataUri !== null) {
                    $rewritten[$field] = $dataUri['uri'];
                    $assets[] = [
                        'field' => $field,
                        'type' => $type,
                        'source_url' => $url,
                        'local_path' => $localPath,
                        'bytes' => $result['bytes'],
                        'sha256' => $result['sha256'],
                        'cached' => $result['cached'],
                        'render_source' => 'data_uri',
                        'mime_type' => $dataUri['mime_type'],
                    ];
                    $this->writeCollectionAsset('fixture', $fixture, $field, $type, $url, $localPath, $result, $dataUri['mime_type']);
                    continue;
                }

                $rewritten[$field] = '';
                $failures[] = [
                    'field' => $field,
                    'type' => $type,
                    'source_url' => $url,
                    'local_path' => $localPath,
                    'reason' => 'cached_asset_data_uri_failed',
                    'render_source' => 'missing',
                ];
            } else {
                $renderSource = preg_match('#^https?://#i', $url) ? 'remote_url' : 'missing';
                $failures[] = [
                    'field' => $field,
                    'type' => $type,
                    'source_url' => $url,
                    'reason' => (string) ($result['reason'] ?? 'asset_cache_failed'),
                    'render_source' => $renderSource,
                ];

                if ($renderSource === 'remote_url') {
                    $rewritten[$field] = $url;
                    $assets[] = [
                        'field' => $field,
                        'type' => $type,
                        'source_url' => $url,
                        'local_path' => null,
                        'bytes' => null,
                        'sha256' => null,
                        'cached' => false,
                        'render_source' => 'remote_url',
                        'mime_type' => null,
                    ];
                } else {
                    $rewritten[$field] = '';
                }
            }
        }

        $renderSources = array_count_values(array_map(
            static fn (array $asset): string => (string) ($asset['render_source'] ?? 'missing'),
            $assets
        ));

        return [
            'fixture' => $rewritten,
            'assets' => $assets,
            'failures' => $failures,
            'summary' => [
                'cached' => (int) ($renderSources['data_uri'] ?? 0),
                'renderable' => count($assets),
                'failed' => count($failures),
                'data_uri' => (int) ($renderSources['data_uri'] ?? 0),
                'remote_url' => (int) ($renderSources['remote_url'] ?? 0),
                'missing' => count(array_filter($failures, static fn (array $failure): bool => ($failure['render_source'] ?? null) === 'missing')),
                'status' => $failures === [] ? 'cached' : 'failed',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{items:int,assets:int,cached:int,failed:int,failures:array<int,array<string,string>>}
     */
    public function cacheProviderRows(array $rows, string $entityType, array $context = []): array
    {
        $summary = [
            'items' => 0,
            'assets' => 0,
            'cached' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result = $this->cacheProviderArtwork($row, $entityType, $context);
            $summary['items']++;
            $summary['assets'] += count($result['assets']);
            $summary['cached'] += count(array_filter($result['assets'], static fn (array $asset): bool => (bool) ($asset['cached'] ?? false)));
            $summary['failed'] += count($result['failures']);
            $summary['failures'] = array_merge($summary['failures'], $result['failures']);
        }

        return $summary;
    }

    /**
     * @return array{entity_type:string,entity_id:string,assets:array<int,array<string,mixed>>,failures:array<int,array<string,string>>}
     */
    public function cacheProviderArtwork(array $row, string $entityType, array $context = []): array
    {
        $entityType = $this->normalizeType($entityType);
        $assets = [];
        $failures = [];

        foreach ($this->providerArtworkFields($row) as $field => $url) {
            $assetType = $this->providerAssetType($entityType, $field);
            $result = $this->cacheUrl($url, $assetType);

            if (($result['ok'] ?? false) === true) {
                $asset = [
                    'field' => $field,
                    'type' => $assetType,
                    'source_url' => $url,
                    'local_path' => (string) ($result['local_path'] ?? ''),
                    'bytes' => $result['bytes'] ?? null,
                    'sha256' => $result['sha256'] ?? null,
                    'cached' => (bool) ($result['cached'] ?? false),
                ];
                $assets[] = $asset;
                $this->writeCollectionAsset($entityType, $row, $field, $assetType, $url, $asset['local_path'], $result, null, $context);
                continue;
            }

            $failures[] = [
                'field' => $field,
                'type' => $assetType,
                'source_url' => $url,
                'reason' => (string) ($result['reason'] ?? 'asset_cache_failed'),
            ];
        }

        return [
            'entity_type' => $entityType,
            'entity_id' => $this->entityId($entityType, $row),
            'assets' => $assets,
            'failures' => $failures,
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
            'collection_items' => $this->collectionItemCount(),
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
            'bytes' => (int) filesize($path),
            'sha256' => (string) ($data['sha256'] ?? (hash_file('sha256', $path) ?: '')),
        ];
    }

    /**
     * @return array{uri:string,mime_type:string}|null
     */
    private function dataUriForLocalImage(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $body = @file_get_contents($path);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $mimeType = $this->mimeTypeForLocalImage($path, $body);
        if ($mimeType === null) {
            return null;
        }

        return [
            'uri' => 'data:' . $mimeType . ';base64,' . base64_encode($body),
            'mime_type' => $mimeType,
        ];
    }

    private function mimeTypeForLocalImage(string $path, string $body): ?string
    {
        $info = @getimagesizefromstring($body);
        if (is_array($info) && isset($info['mime']) && str_starts_with((string) $info['mime'], 'image/')) {
            return (string) $info['mime'];
        }

        $mime = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            return $mime;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => null,
        };
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

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $result
     * @param array<string, mixed> $context
     */
    private function writeCollectionAsset(
        string $entityType,
        array $row,
        string $field,
        string $assetType,
        string $sourceUrl,
        string $localPath,
        array $result,
        ?string $mimeType = null,
        array $context = []
    ): void {
        $entityId = $this->entityId($entityType, $row);
        if ($entityId === '') {
            $entityId = sha1(json_encode($row));
        }

        $path = $this->collectionPath($entityType, $entityId);
        $dir = dirname($path);
        if (!@is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $data = is_array($existing) ? $existing : [];
        $data['entity_type'] = $entityType;
        $data['entity_id'] = $entityId;
        $data['name'] = $this->entityName($row);
        $data['provider'] = 'thesportsdb';
        $data['context'] = array_merge((array) ($data['context'] ?? []), $context);
        $data['source_row'] = array_merge((array) ($data['source_row'] ?? []), $this->compactSourceRow($row));
        $data['updated_at'] = now()->toIso8601String();

        $assets = (array) ($data['assets'] ?? []);
        $assetKey = $field . ':' . sha1($sourceUrl);
        $assets[$assetKey] = [
            'field' => $field,
            'type' => $assetType,
            'source_url' => $sourceUrl,
            'local_path' => $localPath,
            'bytes' => $result['bytes'] ?? null,
            'sha256' => $result['sha256'] ?? null,
            'mime_type' => $mimeType,
            'cached_at' => now()->toIso8601String(),
        ];
        $data['assets'] = $assets;

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @return array<string, string>
     */
    private function providerArtworkFields(array $row): array
    {
        $fields = [];

        foreach ($row as $field => $value) {
            $url = trim((string) $value);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            if (!preg_match('/(badge|logo|icon|banner|poster|thumb|fanart|cutout|render|jersey|equipment|trophy|image|art|map|flag)/i', (string) $field)) {
                continue;
            }

            $fields[(string) $field] = $url;
        }

        return $fields;
    }

    private function providerAssetType(string $entityType, string $field): string
    {
        $field = strtolower($field);

        if (str_contains($field, 'equipment') || str_contains($field, 'jersey')) {
            return 'team_equipment';
        }

        if (str_contains($field, 'player') || str_contains($field, 'cutout') || str_contains($field, 'render')) {
            return 'player_image';
        }

        if (str_contains($field, 'venue') || str_contains($field, 'map')) {
            return 'venue_image';
        }

        if (str_contains($field, 'poster') || str_contains($field, 'thumb')) {
            return $entityType . '_poster';
        }

        if (str_contains($field, 'fanart') || str_contains($field, 'banner')) {
            return $entityType . '_fanart';
        }

        if (str_contains($field, 'badge') || str_contains($field, 'logo') || str_contains($field, 'icon') || str_contains($field, 'trophy')) {
            return $entityType . '_logo';
        }

        return $entityType . '_image';
    }

    private function entityId(string $entityType, array $row): string
    {
        $candidates = match ($entityType) {
            'league' => ['idLeague', 'league_id', 'id'],
            'team' => ['idTeam', 'team_id', 'id'],
            'player' => ['idPlayer', 'player_id', 'id'],
            'event', 'fixture' => ['idEvent', 'event_id', 'id'],
            'venue' => ['idVenue', 'venue_id', 'id'],
            default => ['id', 'idLeague', 'idTeam', 'idPlayer', 'idEvent', 'idVenue'],
        };

        foreach ($candidates as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function entityName(array $row): string
    {
        foreach (['strLeague', 'strTeam', 'strPlayer', 'strEvent', 'strVenue', 'name', 'title'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function compactSourceRow(array $row): array
    {
        return array_filter($row, static function (mixed $value, mixed $key): bool {
            $key = (string) $key;

            return is_scalar($value) && (
                str_starts_with($key, 'id')
                || str_starts_with($key, 'str')
                || in_array($key, ['event_id', 'league_id', 'team_id', 'player_id'], true)
            );
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function collectionPath(string $entityType, string $entityId): string
    {
        return $this->rootDirectory() . '/collection/' . $this->normalizeType($entityType) . '/' . preg_replace('/[^a-z0-9_.-]+/i', '-', $entityId) . '.json';
    }

    private function collectionItemCount(): int
    {
        $root = $this->rootDirectory() . '/collection';
        $files = is_dir($root) ? glob($root . '/*/*.json') ?: [] : [];

        return count(array_filter($files, 'is_file'));
    }

    private function sourceMapPath(string $url): string
    {
        return $this->rootDirectory() . '/sources/' . sha1($url) . '.json';
    }

    private function assetDirectory(string $type): string
    {
        return $this->rootDirectory() . '/' . $this->normalizeType($type);
    }

    private function normalizeType(string $type): string
    {
        return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($type)), '-') ?: 'image';
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

}
