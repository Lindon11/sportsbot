<?php

namespace App\Plugins\SportsBot\Services;

class SportsBotInlineKeyboardBuilder
{
    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function mainKeyboard(): array
    {
        return [
            [
                ['text' => '⚽ Football', 'callback_data' => 'football'],
                ['text' => '🏀 Basketball', 'callback_data' => 'basketball'],
            ],
            [
                ['text' => '🎾 Tennis', 'callback_data' => 'tennis'],
                ['text' => '🥊 MMA/UFC', 'callback_data' => 'mma'],
            ],
            [
                ['text' => '🏏 Cricket', 'callback_data' => 'cricket'],
                ['text' => '🏎 F1', 'callback_data' => 'f1'],
            ],
            [
                ['text' => '📺 TV Guide', 'callback_data' => 'tv_guide'],
                ['text' => '🔴 Live Now', 'callback_data' => 'live_now'],
            ],
            [
                ['text' => '🏆 Tables', 'callback_data' => 'tables'],
                ['text' => '⭐ My Teams', 'callback_data' => 'my_teams'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function backButton(): array
    {
        return [
            [
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function fixturesTodayKeyboard(): array
    {
        return [
            [
                ['text' => '⚽ Football', 'callback_data' => 'fixtures_football'],
                ['text' => '🏀 Basketball', 'callback_data' => 'fixtures_basketball'],
            ],
            [
                ['text' => '🎾 Tennis', 'callback_data' => 'fixtures_tennis'],
                ['text' => '🥊 MMA/UFC', 'callback_data' => 'fixtures_mma'],
            ],
            [
                ['text' => '📺 TV Guide', 'callback_data' => 'tv_guide'],
                ['text' => '🔴 Live Now', 'callback_data' => 'live_now'],
            ],
            [
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function sportMenuKeyboard(string $sportKey): array
    {
        return [
            [
                ['text' => 'Today’s Fixtures', 'callback_data' => 'fixtures_' . $sportKey],
                ['text' => 'Live Now', 'callback_data' => 'live:' . $sportKey],
            ],
            [
                ['text' => 'Tables', 'callback_data' => 'league_table'],
                ['text' => 'Top Teams', 'callback_data' => 'top_teams:' . $sportKey],
            ],
            [
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function myTeamsKeyboard(): array
    {
        return [
            [
                ['text' => 'Add Team', 'callback_data' => 'add_team'],
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function callbackDataAudit(): array
    {
        return [
            'football',
            'basketball',
            'tennis',
            'mma',
            'cricket',
            'f1',
            'tv_guide',
            'live_now',
            'tables',
            'my_teams',
            'fixtures_today',
            'fixtures_football',
            'fixtures_basketball',
            'fixtures_tennis',
            'fixtures_mma',
            'fixtures_cricket',
            'fixtures_formula_1',
            'back_main',
            'match_stats:{event_id}',
            'match_lineups:{event_id}',
            'match_highlights:{event_id}',
            'match_tv:{event_id}',
            'league_table:{league_id}:{page}',
            'table:{league_id}:{page}',
            'scorers:{league_id}:{page}',
            'team:{team_id}',
            'follow_team:{team_id}',
            'team_next:{team_id}:{page}',
            'team_prev:{team_id}:{page}',
            'top_teams:{sport}',
            'add_team',
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function matchKeyboard(string $eventId, array $event = []): array
    {
        $keyboard = [
            [
                ['text' => '📊 Match Stats', 'callback_data' => 'match_stats:' . $eventId],
                ['text' => '👥 Lineups', 'callback_data' => 'match_lineups:' . $eventId],
            ],
            [
                ['text' => '▶ Highlights', 'callback_data' => 'match_highlights:' . $eventId],
                ['text' => '📺 TV Guide', 'callback_data' => 'match_tv:' . $eventId],
            ],
        ];

        $homeId = trim((string) ($event['idHomeTeam'] ?? $event['home_team_id'] ?? ''));
        $awayId = trim((string) ($event['idAwayTeam'] ?? $event['away_team_id'] ?? ''));
        if ($homeId !== '' || $awayId !== '') {
            $row = [];
            if ($homeId !== '') {
                $row[] = ['text' => 'Home Team', 'callback_data' => 'team:' . $homeId];
            }
            if ($awayId !== '') {
                $row[] = ['text' => 'Away Team', 'callback_data' => 'team:' . $awayId];
            }
            $keyboard[] = $row;
        }

        $leagueId = trim((string) ($event['idLeague'] ?? $event['league_id'] ?? ''));
        if ($leagueId !== '') {
            $keyboard[] = [
                ['text' => '🏆 League Table', 'callback_data' => 'league_table:' . $leagueId . ':1'],
                ['text' => '🎯 Top Scorers', 'callback_data' => 'scorers:' . $leagueId . ':1'],
            ];
        }

        $keyboard[] = [
            ['text' => '⬅ Back', 'callback_data' => 'back_main'],
        ];

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
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
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
                ['text' => '⭐ Follow Team', 'callback_data' => 'follow_team:' . $teamId],
                ['text' => 'Next Fixtures', 'callback_data' => 'team_next:' . $teamId . ':1'],
            ],
            [
                ['text' => 'Previous Results', 'callback_data' => 'team_prev:' . $teamId . ':1'],
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public static function paginationKeyboard(string $prefix, string $id, int $page, string $back = 'back_main'): array
    {
        return [
            [
                ['text' => '◀ Prev', 'callback_data' => $prefix . ':' . $id . ':' . max(1, $page - 1)],
                ['text' => 'Next ▶', 'callback_data' => $prefix . ':' . $id . ':' . ($page + 1)],
            ],
            [
                ['text' => '⬅ Back', 'callback_data' => $back],
            ],
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

    /**
     * @return array<string, mixed>
     */
    public static function mainReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::mainKeyboard());
    }

    /**
     * @return array<string, mixed>
     */
    public static function backReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::backButton());
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixturesTodayReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::fixturesTodayKeyboard());
    }

    /**
     * @return array<string, mixed>
     */
    public static function matchReplyMarkup(string $eventId, array $event = []): array
    {
        return self::inlineKeyboardMarkup(self::matchKeyboard($eventId, $event));
    }

    /**
     * @return array<string, mixed>
     */
    public static function tableReplyMarkup(string $leagueId, int $page = 1): array
    {
        return self::inlineKeyboardMarkup(self::tableKeyboard($leagueId, $page));
    }

    /**
     * @return array<string, mixed>
     */
    public static function teamReplyMarkup(string $teamId): array
    {
        return self::inlineKeyboardMarkup(self::teamKeyboard($teamId));
    }

    /**
     * @return array<string, mixed>
     */
    public static function sportMenuReplyMarkup(string $sportKey): array
    {
        return self::inlineKeyboardMarkup(self::sportMenuKeyboard($sportKey));
    }

    /**
     * @return array<string, mixed>
     */
    public static function myTeamsReplyMarkup(): array
    {
        return self::inlineKeyboardMarkup(self::myTeamsKeyboard());
    }
}
