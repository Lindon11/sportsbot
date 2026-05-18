<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotAssetCache;
use Illuminate\Console\Command;

class SportsBotAssetsCleanupCommand extends Command
{
    protected $signature = 'sportsbot:assets-cleanup {--days= : Delete cached assets older than this many days}';

    protected $description = 'Prune stale SportsBot media renderer assets.';

    public function handle(SportsBotAssetCache $assets): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        $result = $assets->pruneStale($days);

        $this->info(sprintf(
            'Deleted %d stale assets (%d bytes) older than %d days from %s.',
            $result['deleted'] ?? 0,
            $result['bytes'] ?? 0,
            $result['days'] ?? 0,
            $result['root'] ?? ''
        ));

        return self::SUCCESS;
    }
}
