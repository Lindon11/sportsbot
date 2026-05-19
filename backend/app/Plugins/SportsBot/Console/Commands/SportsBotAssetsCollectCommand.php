<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\SportsBotArtworkCollector;
use Illuminate\Console\Command;

class SportsBotAssetsCollectCommand extends Command
{
    protected $signature = 'sportsbot:assets-collect
        {--league=* : One or more TheSportsDB league IDs to collect}
        {--players : Also collect player portraits/cutouts from team rosters}
        {--no-teams : Skip team badge/logo collection}
        {--no-equipment : Skip team equipment artwork collection}
        {--no-events : Skip event poster/thumb collection}
        {--team-limit=0 : Limit teams per league, 0 for no limit}
        {--player-limit=0 : Limit players per team, 0 for no limit}
        {--event-limit=40 : Limit next/previous events per league, 0 for no limit}
        {--json : Output machine-readable JSON}';

    protected $description = 'Collect TheSportsDB artwork into the local SportsBot asset library.';

    public function handle(SportsBotArtworkCollector $collector): int
    {
        $summary = $collector->collect([
            'league_ids' => (array) $this->option('league'),
            'teams' => !(bool) $this->option('no-teams'),
            'equipment' => !(bool) $this->option('no-equipment'),
            'players' => (bool) $this->option('players'),
            'events' => !(bool) $this->option('no-events'),
            'team_limit' => (int) $this->option('team-limit'),
            'player_limit' => (int) $this->option('player-limit'),
            'event_limit' => (int) $this->option('event-limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('SportsBot artwork collection complete.');
        $this->line('Leagues: ' . (int) ($summary['leagues_seen'] ?? 0));
        $this->line('Teams: ' . (int) ($summary['teams_seen'] ?? 0));
        $this->line('Players: ' . (int) ($summary['players_seen'] ?? 0));
        $this->line('Events: ' . (int) ($summary['events_seen'] ?? 0));
        $this->line('Assets found: ' . (int) ($summary['assets'] ?? 0));
        $this->line('Failures: ' . (int) ($summary['failed'] ?? 0));
        $this->line('Library: ' . (string) ($summary['asset_cache']['root'] ?? ''));
        $this->line('Collection items: ' . (int) ($summary['asset_cache']['collection_items'] ?? 0));

        return self::SUCCESS;
    }
}
