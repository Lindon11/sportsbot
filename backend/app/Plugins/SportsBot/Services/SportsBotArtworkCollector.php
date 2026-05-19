<?php

namespace App\Plugins\SportsBot\Services;

use Throwable;

class SportsBotArtworkCollector
{
    public function __construct(
        private readonly TheSportsDbClient $provider = new TheSportsDbClient(),
        private readonly SportsBotAssetCache $assets = new SportsBotAssetCache(),
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $leagueIds = $this->leagueIds((array) ($options['league_ids'] ?? []));
        $includeTeams = (bool) ($options['teams'] ?? true);
        $includeEquipment = (bool) ($options['equipment'] ?? true);
        $includePlayers = (bool) ($options['players'] ?? false);
        $includeEvents = (bool) ($options['events'] ?? true);
        $teamLimit = max(0, (int) ($options['team_limit'] ?? 0));
        $playerLimit = max(0, (int) ($options['player_limit'] ?? 0));
        $eventLimit = max(0, (int) ($options['event_limit'] ?? 40));

        $summary = [
            'leagues_seen' => 0,
            'teams_seen' => 0,
            'players_seen' => 0,
            'events_seen' => 0,
            'assets' => 0,
            'cached' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($leagueIds as $leagueId) {
            try {
                $league = $this->provider->lookupLeague($leagueId);
                if (is_array($league)) {
                    $this->mergeAssetSummary($summary, $this->assets->cacheProviderArtwork($league, 'league', [
                        'league_id' => $leagueId,
                    ]));
                    $summary['leagues_seen']++;
                }
            } catch (Throwable $error) {
                $summary['failures'][] = $this->failure('league', $leagueId, $error);
                $summary['failed']++;
            }

            $teams = [];
            if ($includeTeams || $includePlayers || $includeEquipment) {
                try {
                    $teams = $this->limitRows($this->provider->listTeams($leagueId), $teamLimit);
                    $summary['teams_seen'] += count($teams);
                    if ($includeTeams) {
                        $this->mergeRowsSummary($summary, $this->assets->cacheProviderRows($teams, 'team', [
                            'league_id' => $leagueId,
                        ]));
                    }
                } catch (Throwable $error) {
                    $summary['failures'][] = $this->failure('teams', $leagueId, $error);
                    $summary['failed']++;
                }
            }

            if ($includeEquipment) {
                foreach ($teams as $team) {
                    $teamId = trim((string) ($team['idTeam'] ?? ''));
                    if ($teamId === '') {
                        continue;
                    }

                    try {
                        $this->mergeRowsSummary($summary, $this->assets->cacheProviderRows(
                            $this->provider->lookupTeamEquipment($teamId),
                            'team_equipment',
                            ['league_id' => $leagueId, 'team_id' => $teamId]
                        ));
                    } catch (Throwable $error) {
                        $summary['failures'][] = $this->failure('team_equipment', $teamId, $error);
                        $summary['failed']++;
                    }
                }
            }

            if ($includePlayers) {
                foreach ($teams as $team) {
                    $teamId = trim((string) ($team['idTeam'] ?? ''));
                    if ($teamId === '') {
                        continue;
                    }

                    try {
                        $players = $this->limitRows($this->provider->listPlayers($teamId), $playerLimit);
                        $summary['players_seen'] += count($players);
                        $this->mergeRowsSummary($summary, $this->assets->cacheProviderRows($players, 'player', [
                            'league_id' => $leagueId,
                            'team_id' => $teamId,
                        ]));
                    } catch (Throwable $error) {
                        $summary['failures'][] = $this->failure('players', $teamId, $error);
                        $summary['failed']++;
                    }
                }
            }

            if ($includeEvents) {
                try {
                    $events = $this->limitRows(array_merge(
                        $this->provider->nextLeagueEvents($leagueId),
                        $this->provider->previousLeagueEvents($leagueId)
                    ), $eventLimit);
                    $summary['events_seen'] += count($events);
                    $this->mergeRowsSummary($summary, $this->assets->cacheProviderRows($events, 'event', [
                        'league_id' => $leagueId,
                    ]));
                } catch (Throwable $error) {
                    $summary['failures'][] = $this->failure('events', $leagueId, $error);
                    $summary['failed']++;
                }
            }
        }

        $summary['asset_cache'] = $this->assets->diagnostics();

        return $summary;
    }

    /**
     * @param array<int, mixed> $explicitLeagueIds
     * @return array<int, string>
     */
    private function leagueIds(array $explicitLeagueIds): array
    {
        $ids = array_map('strval', $explicitLeagueIds);

        if ($ids === []) {
            foreach ((array) config('plugins.SportsBot.fixtures_today', []) as $key => $value) {
                if (str_ends_with((string) $key, '_league_ids')) {
                    $ids = array_merge($ids, array_map('strval', (array) $value));
                }
            }

            $ids = array_merge($ids, array_map('strval', (array) config('plugins.SportsBot.coverage.allowed_league_ids', [])));
        }

        return array_values(array_unique(array_filter(array_map('trim', $ids))));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function limitRows(array $rows, int $limit): array
    {
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $result
     */
    private function mergeAssetSummary(array &$summary, array $result): void
    {
        $assets = (array) ($result['assets'] ?? []);
        $failures = (array) ($result['failures'] ?? []);

        $summary['assets'] += count($assets);
        $summary['cached'] += count(array_filter($assets, static fn (array $asset): bool => (bool) ($asset['cached'] ?? false)));
        $summary['failed'] += count($failures);
        $summary['failures'] = array_merge($summary['failures'], $failures);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $result
     */
    private function mergeRowsSummary(array &$summary, array $result): void
    {
        $summary['assets'] += (int) ($result['assets'] ?? 0);
        $summary['cached'] += (int) ($result['cached'] ?? 0);
        $summary['failed'] += (int) ($result['failed'] ?? 0);
        $summary['failures'] = array_merge($summary['failures'], (array) ($result['failures'] ?? []));
    }

    /**
     * @return array<string, string>
     */
    private function failure(string $type, string $id, Throwable $error): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'reason' => $error->getMessage(),
        ];
    }
}
