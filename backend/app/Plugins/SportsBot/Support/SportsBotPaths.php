<?php

namespace App\Plugins\SportsBot\Support;

final class SportsBotPaths
{
    public static function v3RendererScript(): string
    {
        return self::configuredFilePath(
            (string) config('plugins.SportsBot.cards.v3_renderer_script', ''),
            base_path('resources/sportsbot/v3-card-renderer.cjs')
        );
    }

    public static function cardPath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (@is_file($path)) {
            return $path;
        }

        $relocated = self::relocateStoragePath($path);

        return $relocated !== null ? $relocated : $path;
    }

    private static function configuredFilePath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }

        if (!self::isAbsolutePath($path)) {
            $path = base_path($path);
        }

        if (@is_file($path)) {
            return $path;
        }

        $normalizedFallback = ltrim(str_replace('\\', '/', str_replace(base_path(), '', $fallback)), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_ends_with($normalizedPath, $normalizedFallback)) {
            return $fallback;
        }

        return $path;
    }

    private static function relocateStoragePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $needle = '/storage/';
        $position = strpos($normalized, $needle);

        if ($position === false) {
            return null;
        }

        $relative = substr($normalized, $position + strlen($needle));
        if ($relative === false || $relative === '') {
            return null;
        }

        return storage_path($relative);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
