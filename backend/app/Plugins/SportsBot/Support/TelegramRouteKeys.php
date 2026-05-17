<?php

namespace App\Plugins\SportsBot\Support;

class TelegramRouteKeys
{
    public const DEFAULT = 'default';
    public const FIXTURES_TODAY = 'FIXTURES_TODAY';
    public const TV_GUIDE = 'TV_GUIDE';
    public const LIVE_NOW = 'LIVE_NOW';
    public const LEAGUE_TABLES = 'LEAGUE_TABLES';
    public const FOOTBALL = 'FOOTBALL';
    public const BASKETBALL = 'BASKETBALL';
    public const BASEBALL = 'BASEBALL';
    public const AMERICAN_FOOTBALL = 'AMERICAN_FOOTBALL';
    public const TENNIS = 'TENNIS';
    public const MMA = 'MMA';
    public const RUGBY = 'RUGBY';
    public const CRICKET = 'CRICKET';
    public const FORMULA_1 = 'FORMULA_1';
    public const ICE_HOCKEY = 'ICE_HOCKEY';
    public const GOLF = 'GOLF';
    public const MOTORSPORT = 'MOTORSPORT';
    public const BOXING = 'BOXING';
    public const MY_TEAMS = 'MY_TEAMS';
    public const NEWS = 'NEWS';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::DEFAULT,
            self::FIXTURES_TODAY,
            self::TV_GUIDE,
            self::LIVE_NOW,
            self::LEAGUE_TABLES,
            self::FOOTBALL,
            self::BASKETBALL,
            self::BASEBALL,
            self::AMERICAN_FOOTBALL,
            self::TENNIS,
            self::MMA,
            self::RUGBY,
            self::CRICKET,
            self::FORMULA_1,
            self::ICE_HOCKEY,
            self::GOLF,
            self::MOTORSPORT,
            self::BOXING,
            self::MY_TEAMS,
            self::NEWS,
        ];
    }

    public static function normalize(?string $routeKey): string
    {
        $value = trim((string) $routeKey);

        if ($value === '') {
            return self::DEFAULT;
        }

        if (strtolower($value) === self::DEFAULT) {
            return self::DEFAULT;
        }

        return strtoupper($value);
    }
}
