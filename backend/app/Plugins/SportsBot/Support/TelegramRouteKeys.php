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
    public const MMA = 'MMA';
    public const RUGBY = 'RUGBY';

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
            self::MMA,
            self::RUGBY,
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
