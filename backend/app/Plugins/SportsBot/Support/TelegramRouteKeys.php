<?php

namespace App\Plugins\SportsBot\Support;

class TelegramRouteKeys
{
    public const DEFAULT = 'default';
    public const FIXTURES_TODAY = 'FIXTURES_TODAY';
    public const FOOTBALL = 'FOOTBALL';
    public const BASKETBALL = 'BASKETBALL';
    public const BASEBALL = 'BASEBALL';
    public const AMERICAN_FOOTBALL = 'AMERICAN_FOOTBALL';
    public const USA_SPORTS = 'USA_SPORTS';
    public const TENNIS = 'TENNIS';
    public const MMA = 'MMA';
    public const FIGHTS = 'FIGHTS';
    public const RUGBY = 'RUGBY';
    public const CRICKET = 'CRICKET';
    public const FORMULA_1 = 'FORMULA_1';
    public const FORMULA_2 = 'FORMULA_2';
    public const FORMULA_3 = 'FORMULA_3';
    public const FORMULA_E = 'FORMULA_E';
    public const MOTOGP = 'MOTOGP';
    public const MOTO2 = 'MOTO2';
    public const MOTO3 = 'MOTO3';
    public const INDYCAR = 'INDYCAR';
    public const NASCAR = 'NASCAR';
    public const WRC = 'WRC';
    public const BTCC = 'BTCC';
    public const WEC = 'WEC';
    public const DTM = 'DTM';
    public const DAKAR = 'DAKAR';
    public const IMSA = 'IMSA';
    public const WORLDSBK = 'WORLDSBK';
    public const SPEEDWAY = 'SPEEDWAY';
    public const MOTORSPORT_OTHER = 'MOTORSPORT_OTHER';
    public const ICE_HOCKEY = 'ICE_HOCKEY';
    public const GOLF = 'GOLF';
    public const OTHER_SPORTS = 'OTHER_SPORTS';
    public const MOTORSPORT = 'MOTORSPORT';
    public const BOXING = 'BOXING';
    public const COMBAT_OTHER = 'COMBAT_OTHER';
    public const HIGHLIGHTS = 'HIGHLIGHTS';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::DEFAULT,
            self::FIXTURES_TODAY,
            self::FOOTBALL,
            self::BASKETBALL,
            self::BASEBALL,
            self::AMERICAN_FOOTBALL,
            self::TENNIS,
            self::MMA,
            self::RUGBY,
            self::CRICKET,
            self::FORMULA_1,
            self::FORMULA_2,
            self::FORMULA_3,
            self::FORMULA_E,
            self::MOTOGP,
            self::MOTO2,
            self::MOTO3,
            self::INDYCAR,
            self::NASCAR,
            self::WRC,
            self::BTCC,
            self::WEC,
            self::DTM,
            self::DAKAR,
            self::IMSA,
            self::WORLDSBK,
            self::SPEEDWAY,
            self::MOTORSPORT_OTHER,
            self::ICE_HOCKEY,
            self::GOLF,
            self::BOXING,
            self::COMBAT_OTHER,
            self::HIGHLIGHTS,
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

        $normalized = strtoupper(str_replace([' ', '-'], '_', $value));
        $compact = str_replace('_', '', $normalized);

        return match ($compact) {
            'F1', 'FORMULA1' => self::FORMULA_1,
            'F2', 'FORMULA2' => self::FORMULA_2,
            'F3', 'FORMULA3' => self::FORMULA_3,
            'FORMULAE', 'FE' => self::FORMULA_E,
            'MOTOGP', 'MOTO_GP' => self::MOTOGP,
            'AMERICANFOOTBALL', 'NFL' => self::AMERICAN_FOOTBALL,
            'ICEHOCKEY', 'NHL' => self::ICE_HOCKEY,
            'USASPORTS' => self::USA_SPORTS,
            'OTHERSPORTS' => self::OTHER_SPORTS,
            default => $normalized,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function fallbackRouteKeys(string $routeKey): array
    {
        return [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function legacyGroupRouteMap(): array
    {
        return [
            self::USA_SPORTS => [
                self::BASKETBALL,
                self::BASEBALL,
                self::AMERICAN_FOOTBALL,
                self::ICE_HOCKEY,
            ],
            self::OTHER_SPORTS => [
                self::TENNIS,
                self::CRICKET,
                self::GOLF,
            ],
            self::MOTORSPORT => [
                self::FORMULA_1,
                self::FORMULA_2,
                self::FORMULA_3,
                self::FORMULA_E,
                self::MOTOGP,
                self::MOTO2,
                self::MOTO3,
                self::INDYCAR,
                self::NASCAR,
                self::WRC,
                self::MOTORSPORT_OTHER,
            ],
            self::FIGHTS => [
                self::MMA,
                self::BOXING,
                self::COMBAT_OTHER,
            ],
        ];
    }
}
