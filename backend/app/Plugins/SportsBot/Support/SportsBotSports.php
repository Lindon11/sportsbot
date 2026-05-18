<?php

namespace App\Plugins\SportsBot\Support;

class SportsBotSports
{
    /**
     * @return array<string, array{label:string, sport:string, icon:string, route_key:string, aliases:array<int,string>}>
     */
    public static function all(): array
    {
        return [
            'football' => ['label' => 'Football', 'sport' => 'Soccer', 'icon' => '⚽', 'route_key' => TelegramRouteKeys::FOOTBALL, 'aliases' => ['soccer', 'football']],
            'basketball' => ['label' => 'Basketball', 'sport' => 'Basketball', 'icon' => '🏀', 'route_key' => TelegramRouteKeys::USA_SPORTS, 'aliases' => ['basketball', 'nba']],
            'baseball' => ['label' => 'Baseball', 'sport' => 'Baseball', 'icon' => '⚾', 'route_key' => TelegramRouteKeys::USA_SPORTS, 'aliases' => ['baseball', 'mlb']],
            'american_football' => ['label' => 'American Football', 'sport' => 'American Football', 'icon' => '🏈', 'route_key' => TelegramRouteKeys::USA_SPORTS, 'aliases' => ['american football', 'nfl']],
            'tennis' => ['label' => 'Tennis', 'sport' => 'Tennis', 'icon' => '🎾', 'route_key' => TelegramRouteKeys::OTHER_SPORTS, 'aliases' => ['tennis']],
            'fights' => ['label' => 'Fights', 'sport' => 'Fighting', 'icon' => '🥊', 'route_key' => TelegramRouteKeys::FIGHTS, 'aliases' => ['fights', 'fight', 'fighting', 'combat', 'ppv']],
            'mma' => ['label' => 'MMA / UFC', 'sport' => 'Fighting', 'icon' => '🥊', 'route_key' => TelegramRouteKeys::MMA, 'aliases' => ['mma', 'ufc', 'fighting']],
            'rugby' => ['label' => 'Rugby', 'sport' => 'Rugby', 'icon' => '🏉', 'route_key' => TelegramRouteKeys::RUGBY, 'aliases' => ['rugby']],
            'cricket' => ['label' => 'Cricket', 'sport' => 'Cricket', 'icon' => '🏏', 'route_key' => TelegramRouteKeys::OTHER_SPORTS, 'aliases' => ['cricket']],
            'formula_1' => ['label' => 'Formula 1', 'sport' => 'Motorsport', 'icon' => '🏎', 'route_key' => TelegramRouteKeys::MOTORSPORT, 'aliases' => ['formula 1', 'f1', 'motorsport']],
            'ice_hockey' => ['label' => 'Ice Hockey', 'sport' => 'Ice Hockey', 'icon' => '🏒', 'route_key' => TelegramRouteKeys::USA_SPORTS, 'aliases' => ['ice hockey', 'hockey', 'nhl']],
            'golf' => ['label' => 'Golf', 'sport' => 'Golf', 'icon' => '⛳', 'route_key' => TelegramRouteKeys::OTHER_SPORTS, 'aliases' => ['golf']],
            'motorsport' => ['label' => 'Motorsport', 'sport' => 'Motorsport', 'icon' => '🏁', 'route_key' => TelegramRouteKeys::MOTORSPORT, 'aliases' => ['motorsport', 'racing']],
            'boxing' => ['label' => 'Boxing', 'sport' => 'Fighting', 'icon' => '🥊', 'route_key' => TelegramRouteKeys::BOXING, 'aliases' => ['boxing']],
        ];
    }

    public static function normalize(string $sport): string
    {
        $needle = strtolower(trim(str_replace('_', ' ', $sport)));

        foreach (self::all() as $key => $definition) {
            if ($needle === strtolower($definition['label']) || $needle === strtolower($definition['sport'])) {
                return $key;
            }

            foreach ($definition['aliases'] as $alias) {
                if ($needle === strtolower($alias)) {
                    return $key;
                }
            }
        }

        return str_replace(' ', '_', $needle);
    }

    public static function label(string $sport): string
    {
        $definition = self::all()[self::normalize($sport)] ?? null;

        return $definition['label'] ?? ucwords(str_replace('_', ' ', $sport));
    }

    public static function providerSport(string $sport): string
    {
        $definition = self::all()[self::normalize($sport)] ?? null;

        return $definition['sport'] ?? ucwords(str_replace('_', ' ', $sport));
    }

    public static function icon(string $sport): string
    {
        $definition = self::all()[self::normalize($sport)] ?? null;

        return $definition['icon'] ?? '🏟';
    }

    public static function routeKey(string $sport): string
    {
        $definition = self::all()[self::normalize($sport)] ?? null;

        return $definition['route_key'] ?? TelegramRouteKeys::DEFAULT;
    }
}
