<?php

namespace App\Plugins\SportsBot\Services;

class SportsBotInlineKeyboardBuilder
{
    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function matchKeyboard(string $eventId, array $event = []): array
    {
        $keyboard = [
            [
                ['text' => '📊 Stats', 'callback_data' => 'stats:' . $eventId],
                ['text' => '👥 Lineups', 'callback_data' => 'lineups:' . $eventId],
            ],
            [
                ['text' => '▶ Highlights', 'callback_data' => 'highlights:' . $eventId],
                ['text' => '📺 TV', 'callback_data' => 'tv:' . $eventId],
            ],
        ];

        $homeId = trim((string) ($event['idHomeTeam'] ?? $event['home_team_id'] ?? ''));
        $awayId = trim((string) ($event['idAwayTeam'] ?? $event['away_team_id'] ?? ''));
        if ($homeId !== '' || $awayId !== '') {
            $row = [];
            if ($homeId !== '') {
                $row[] = ['text' => '🏠 Home', 'callback_data' => 'team:' . $homeId];
            }
            if ($awayId !== '') {
                $row[] = ['text' => '✈️ Away', 'callback_data' => 'team:' . $awayId];
            }
            $keyboard[] = $row;
        }

        $followHome = trim((string) ($event['idHomeTeam'] ?? $event['home_team_id'] ?? ''));
        if ($followHome !== '') {
            $keyboard[] = [
                ['text' => '⭐ Follow Home', 'callback_data' => 'follow_team:' . $followHome],
            ];
        }

        $leagueId = trim((string) ($event['idLeague'] ?? $event['league_id'] ?? ''));
        if ($leagueId !== '') {
            $keyboard[] = [
                ['text' => '🏆 Table', 'callback_data' => 'table:' . $leagueId . ':1'],
                ['text' => '🎯 Top Scorers', 'callback_data' => 'scorers:' . $leagueId . ':1'],
            ];
        }

        return $keyboard;
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function tableKeyboard(string $leagueId, int $page = 1): array
    {
        return [
            [
                ['text' => '◀ Prev', 'callback_data' => 'table:' . $leagueId . ':' . max(1, $page - 1)],
                ['text' => 'Next ▶', 'callback_data' => 'table:' . $leagueId . ':' . ($page + 1)],
            ],
            [
                ['text' => '🎯 Top Scorers', 'callback_data' => 'scorers:' . $leagueId . ':1'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function teamKeyboard(string $teamId): array
    {
        return [
            [
                ['text' => '⭐ Follow', 'callback_data' => 'follow_team:' . $teamId],
                ['text' => '📅 Next', 'callback_data' => 'team_next:' . $teamId . ':1'],
            ],
            [
                ['text' => '📋 Previous', 'callback_data' => 'team_prev:' . $teamId . ':1'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function fixturesKeyboard(): array
    {
        return [
            [
                ['text' => '⚽ Football', 'callback_data' => 'fixtures:football:1'],
                ['text' => '🏀 Basketball', 'callback_data' => 'fixtures:basketball:1'],
            ],
            [
                ['text' => '🏒 Ice Hockey', 'callback_data' => 'fixtures:ice_hockey:1'],
                ['text' => '⚾ Baseball', 'callback_data' => 'fixtures:baseball:1'],
            ],
            [
                ['text' => '🎾 Tennis', 'callback_data' => 'fixtures:tennis:1'],
                ['text' => '🥊 MMA', 'callback_data' => 'fixtures:mma:1'],
            ],
            [
                ['text' => '🏏 Cricket', 'callback_data' => 'fixtures:cricket:1'],
                ['text' => '🏎 F1', 'callback_data' => 'fixtures:formula_1:1'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function liveKeyboard(): array
    {
        return [
            [
                ['text' => '⚽ Football', 'callback_data' => 'live:football'],
                ['text' => '🏀 Basketball', 'callback_data' => 'live:basketball'],
            ],
            [
                ['text' => '🏒 Ice Hockey', 'callback_data' => 'live:ice_hockey'],
                ['text' => '🎾 Tennis', 'callback_data' => 'live:tennis'],
            ],
            [
                ['text' => '🥊 MMA', 'callback_data' => 'live:mma'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function tvKeyboard(): array
    {
        return [
            [
                ['text' => '🔴 Live Now', 'callback_data' => 'live_now'],
                ['text' => '⚽ Fixtures', 'callback_data' => 'fixtures_today'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function paginationKeyboard(string $prefix, string $id, int $page): array
    {
        return [
            [
                ['text' => '◀ Prev', 'callback_data' => $prefix . ':' . $id . ':' . max(1, $page - 1)],
                ['text' => 'Next ▶', 'callback_data' => $prefix . ':' . $id . ':' . ($page + 1)],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function callbackDataAudit(): array
    {
        return [
            'fixtures_today',
            'tv_guide',
            'live_now',
            'match:{event_id}',
            'stats:{event_id}',
            'lineups:{event_id}',
            'highlights:{event_id}',
            'tv:{event_id}',
            'table:{league_id}:{page}',
            'scorers:{league_id}:{page}',
            'team:{team_id}',
            'follow_team:{team_id}',
            'unfollow_team:{team_id}',
            'follow_league:{league_id}',
            'unfollow_league:{league_id}',
            'team_next:{team_id}:{page}',
            'team_prev:{team_id}:{page}',
            'fixtures:{sport_key}:{page}',
            'live:{sport_key}',
            'top_teams:{sport_key}',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function inlineKeyboardMarkup(array $keyboard): array
    {
        return [
            'inline_keyboard' => $keyboard,
        ];
    }

    public static function fixturesTodayReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::fixturesKeyboard());
    }

    public static function matchReplyMarkup(string $eventId, array $event = []): array
    {
        return self::inlineKeyboardMarkup(self::matchKeyboard($eventId, $event));
    }

    public static function tableReplyMarkup(string $leagueId, int $page = 1): array
    {
        return self::inlineKeyboardMarkup(self::tableKeyboard($leagueId, $page));
    }

    public static function teamReplyMarkup(string $teamId): array
    {
        return self::inlineKeyboardMarkup(self::teamKeyboard($teamId));
    }

    public static function liveReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::liveKeyboard());
    }

    public static function tvReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::tvKeyboard());
    }
}
