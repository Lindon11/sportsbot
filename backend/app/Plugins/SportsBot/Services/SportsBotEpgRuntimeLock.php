<?php

namespace App\Plugins\SportsBot\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class SportsBotEpgRuntimeLock
{
    public const LOCK_KEY = 'sportsbot:epg:runtime-lock';
    public const MARKER_KEY = 'sportsbot:epg:runtime-marker';

    /**
     * @return array<string, mixed>
     */
    public function run(string $owner, Closure $callback, int $seconds = 1800): array
    {
        $lock = Cache::lock(self::LOCK_KEY, $seconds);

        try {
            if (! $lock->get()) {
                return [
                    'locked' => true,
                    'status' => 'locked',
                    'owner' => Cache::get(self::MARKER_KEY . ':owner'),
                    'started_at' => Cache::get(self::MARKER_KEY . ':started_at'),
                ];
            }
        } catch (LockTimeoutException) {
            return [
                'locked' => true,
                'status' => 'locked',
                'owner' => Cache::get(self::MARKER_KEY . ':owner'),
                'started_at' => Cache::get(self::MARKER_KEY . ':started_at'),
            ];
        }

        Cache::put(self::MARKER_KEY, true, $seconds);
        Cache::put(self::MARKER_KEY . ':owner', $owner, $seconds);
        Cache::put(self::MARKER_KEY . ':started_at', now()->toIso8601String(), $seconds);

        try {
            return (array) $callback();
        } finally {
            Cache::forget(self::MARKER_KEY);
            Cache::forget(self::MARKER_KEY . ':owner');
            Cache::forget(self::MARKER_KEY . ':started_at');
            optional($lock)->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'locked' => (bool) Cache::get(self::MARKER_KEY, false),
            'owner' => Cache::get(self::MARKER_KEY . ':owner'),
            'started_at' => Cache::get(self::MARKER_KEY . ':started_at'),
        ];
    }
}
