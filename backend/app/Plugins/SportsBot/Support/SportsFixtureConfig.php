<?php

namespace App\Plugins\SportsBot\Support;

class SportsFixtureConfig
{
    private const CARD_TEMPLATE_TEAM_VS_TEAM = 'team_vs_team';
    private const CARD_TEMPLATE_FIGHTER_VS_FIGHTER = 'fighter_vs_fighter';
    private const CARD_TEMPLATE_RACE_EVENT = 'race_event';
    private const CARD_TEMPLATE_GENERIC = 'generic_fixture';

    private const CAPTION_FOOTBALL = 'football';
    private const CAPTION_COMBAT = 'combat';
    private const CAPTION_RUGBY = 'rugby';
    private const CAPTION_GENERIC = 'generic';

    public static function all(): array
    {
        return [
            'football' => [
                'sport'                  => 'football',
                'emoji'                  => '⚽',
                'topic_key'              => TelegramRouteKeys::FOOTBALL,
                'provider_sport'         => 'Soccer',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_FOOTBALL,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'rugby' => [
                'sport'                  => 'rugby',
                'emoji'                  => '🏉',
                'topic_key'              => TelegramRouteKeys::RUGBY,
                'provider_sport'         => 'Rugby',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_RUGBY,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 14,
                'asset_cache_window'     => 14,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'fights' => [
                'sport'                  => 'fights',
                'emoji'                  => '🥊',
                'topic_key'              => TelegramRouteKeys::COMBAT_OTHER,
                'provider_sport'         => 'Fighting',
                'card_template'          => self::CARD_TEMPLATE_FIGHTER_VS_FIGHTER,
                'caption_formatter'      => self::CAPTION_COMBAT,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => true,
                'data_fetch_window'      => 45,
                'asset_cache_window'     => 45,
                'card_prepare_window'    => 7,
                'publish_window'         => 0,
            ],
            'mma' => [
                'sport'                  => 'mma',
                'emoji'                  => '🥊',
                'topic_key'              => TelegramRouteKeys::MMA,
                'provider_sport'         => 'Fighting',
                'card_template'          => self::CARD_TEMPLATE_FIGHTER_VS_FIGHTER,
                'caption_formatter'      => self::CAPTION_COMBAT,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => true,
                'data_fetch_window'      => 45,
                'asset_cache_window'     => 45,
                'card_prepare_window'    => 7,
                'publish_window'         => 0,
            ],
            'boxing' => [
                'sport'                  => 'boxing',
                'emoji'                  => '🥊',
                'topic_key'              => TelegramRouteKeys::BOXING,
                'provider_sport'         => 'Fighting',
                'card_template'          => self::CARD_TEMPLATE_FIGHTER_VS_FIGHTER,
                'caption_formatter'      => self::CAPTION_COMBAT,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => true,
                'data_fetch_window'      => 45,
                'asset_cache_window'     => 45,
                'card_prepare_window'    => 7,
                'publish_window'         => 0,
            ],
            'basketball' => [
                'sport'                  => 'basketball',
                'emoji'                  => '🏀',
                'topic_key'              => TelegramRouteKeys::BASKETBALL,
                'provider_sport'         => 'Basketball',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'baseball' => [
                'sport'                  => 'baseball',
                'emoji'                  => '⚾',
                'topic_key'              => TelegramRouteKeys::BASEBALL,
                'provider_sport'         => 'Baseball',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'tennis' => [
                'sport'                  => 'tennis',
                'emoji'                  => '🎾',
                'topic_key'              => TelegramRouteKeys::TENNIS,
                'provider_sport'         => 'Tennis',
                'card_template'          => self::CARD_TEMPLATE_GENERIC,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'cricket' => [
                'sport'                  => 'cricket',
                'emoji'                  => '🏏',
                'topic_key'              => TelegramRouteKeys::CRICKET,
                'provider_sport'         => 'Cricket',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'golf' => [
                'sport'                  => 'golf',
                'emoji'                  => '⛳',
                'topic_key'              => TelegramRouteKeys::GOLF,
                'provider_sport'         => 'Golf',
                'card_template'          => self::CARD_TEMPLATE_GENERIC,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'other_sports' => [
                'sport'                  => 'other_sports',
                'emoji'                  => '🏟',
                'topic_key'              => TelegramRouteKeys::OTHER_SPORTS,
                'provider_sport'         => '',
                'card_template'          => self::CARD_TEMPLATE_GENERIC,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'formula_1' => [
                'sport'                  => 'formula_1',
                'emoji'                  => '🏎',
                'topic_key'              => TelegramRouteKeys::FORMULA_1,
                'provider_sport'         => 'Motorsport',
                'card_template'          => self::CARD_TEMPLATE_RACE_EVENT,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 14,
                'asset_cache_window'     => 14,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'american_football' => [
                'sport'                  => 'american_football',
                'emoji'                  => '🏈',
                'topic_key'              => TelegramRouteKeys::AMERICAN_FOOTBALL,
                'provider_sport'         => 'American Football',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'ice_hockey' => [
                'sport'                  => 'ice_hockey',
                'emoji'                  => '🏒',
                'topic_key'              => TelegramRouteKeys::ICE_HOCKEY,
                'provider_sport'         => 'Ice Hockey',
                'card_template'          => self::CARD_TEMPLATE_TEAM_VS_TEAM,
                'caption_formatter'      => self::CAPTION_GENERIC,
                'default_card_version'   => 'v3',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 7,
                'asset_cache_window'     => 7,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
        ];
    }

    public static function for(string $sportKey): ?array
    {
        $normalized = SportsBotSports::normalize($sportKey);

        return self::all()[$normalized] ?? null;
    }

    public static function cardTemplate(string $sportKey): string
    {
        return (string) (self::for($sportKey)['card_template'] ?? 'generic_fixture');
    }

    public static function captionFormatter(string $sportKey): string
    {
        return (string) (self::for($sportKey)['caption_formatter'] ?? 'generic');
    }

    public static function topicKey(string $sportKey): string
    {
        return (string) (self::for($sportKey)['topic_key'] ?? TelegramRouteKeys::DEFAULT);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    public static function routeKeyForFixture(string $sportKey, array $fixture): string
    {
        $explicitRoute = trim((string) ($fixture['route_key'] ?? ''));
        if ($explicitRoute !== '') {
            $routeKey = TelegramRouteKeys::normalize($explicitRoute);
            if (in_array($routeKey, TelegramRouteKeys::all(), true)) {
                return $routeKey;
            }
        }

        $normalized = SportsBotSports::normalize($sportKey);

        if (in_array($normalized, ['fights', 'mma', 'boxing'], true)) {
            return self::combatRouteKey($fixture, $normalized);
        }

        if (in_array($normalized, ['formula_1', 'motorsport'], true)) {
            return self::motorsportRouteKey($fixture);
        }

        return TelegramRouteKeys::normalize(self::topicKey($normalized));
    }

    public static function emoji(string $sportKey): string
    {
        return (string) (self::for($sportKey)['emoji'] ?? '🏟');
    }

    public static function providerSport(string $sportKey): string
    {
        return (string) (self::for($sportKey)['provider_sport'] ?? '');
    }

    public static function defaultCardVersion(string $sportKey): string
    {
        return (string) (self::for($sportKey)['default_card_version'] ?? 'v3');
    }

    public static function captionsEnabledDefault(string $sportKey): bool
    {
        return (bool) (self::for($sportKey)['captions_enabled_default'] ?? false);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function motorsportRouteKey(array $fixture): string
    {
        $leagueId = trim((string) ($fixture['league_id'] ?? $fixture['idLeague'] ?? ''));

        $byLeagueId = [
            '4370' => TelegramRouteKeys::FORMULA_1,
            '4486' => TelegramRouteKeys::FORMULA_2,
            '4487' => TelegramRouteKeys::FORMULA_3,
            '4371' => TelegramRouteKeys::FORMULA_E,
            '4407' => TelegramRouteKeys::MOTOGP,
            '4436' => TelegramRouteKeys::MOTO2,
            '4437' => TelegramRouteKeys::MOTO3,
            '4372' => TelegramRouteKeys::BTCC,
            '4373' => TelegramRouteKeys::INDYCAR,
            '4393' => TelegramRouteKeys::NASCAR,
            '4409' => TelegramRouteKeys::WRC,
            '4413' => TelegramRouteKeys::WEC,
            '4438' => TelegramRouteKeys::DTM,
            '4447' => TelegramRouteKeys::DAKAR,
            '4488' => TelegramRouteKeys::IMSA,
            '5264' => TelegramRouteKeys::WORLDSBK,
            '5600' => TelegramRouteKeys::SPEEDWAY,
        ];

        if ($leagueId !== '' && isset($byLeagueId[$leagueId])) {
            return $byLeagueId[$leagueId];
        }

        $league = strtolower((string) ($fixture['league'] ?? $fixture['strLeague'] ?? $fixture['event_name'] ?? $fixture['strEvent'] ?? ''));

        return match (true) {
            str_contains($league, 'formula 2') || str_contains($league, 'f2') => TelegramRouteKeys::FORMULA_2,
            str_contains($league, 'formula 3') || str_contains($league, 'f3') => TelegramRouteKeys::FORMULA_3,
            str_contains($league, 'formula e') => TelegramRouteKeys::FORMULA_E,
            str_contains($league, 'motogp') => TelegramRouteKeys::MOTOGP,
            str_contains($league, 'moto2') => TelegramRouteKeys::MOTO2,
            str_contains($league, 'moto3') => TelegramRouteKeys::MOTO3,
            str_contains($league, 'indycar') => TelegramRouteKeys::INDYCAR,
            str_contains($league, 'nascar') => TelegramRouteKeys::NASCAR,
            str_contains($league, 'wrc') || str_contains($league, 'world rally') => TelegramRouteKeys::WRC,
            str_contains($league, 'formula 1') || str_contains($league, 'f1') => TelegramRouteKeys::FORMULA_1,
            default => TelegramRouteKeys::MOTORSPORT_OTHER,
        };
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private static function combatRouteKey(array $fixture, string $sportKey): string
    {
        if ($sportKey === 'mma') {
            return TelegramRouteKeys::MMA;
        }

        if ($sportKey === 'boxing') {
            return TelegramRouteKeys::BOXING;
        }

        $leagueId = trim((string) ($fixture['league_id'] ?? $fixture['idLeague'] ?? ''));
        $byLeagueId = [
            '4443' => TelegramRouteKeys::MMA,
            '4445' => TelegramRouteKeys::BOXING,
            '4567' => TelegramRouteKeys::COMBAT_OTHER,
        ];

        if ($leagueId !== '' && isset($byLeagueId[$leagueId])) {
            return $byLeagueId[$leagueId];
        }

        $league = strtolower((string) ($fixture['league'] ?? $fixture['strLeague'] ?? $fixture['event_name'] ?? $fixture['strEvent'] ?? ''));

        return match (true) {
            str_contains($league, 'ufc') || str_contains($league, 'mma') => TelegramRouteKeys::MMA,
            str_contains($league, 'boxing') => TelegramRouteKeys::BOXING,
            default => TelegramRouteKeys::COMBAT_OTHER,
        };
    }

    public static function dataFetchWindow(string $sportKey): int
    {
        return (int) (self::for($sportKey)['data_fetch_window'] ?? 7);
    }

    public static function assetCacheWindow(string $sportKey): int
    {
        return (int) (self::for($sportKey)['asset_cache_window'] ?? 7);
    }

    public static function cardPrepareWindow(string $sportKey): int
    {
        return (int) (self::for($sportKey)['card_prepare_window'] ?? 2);
    }

    public static function publishWindow(string $sportKey): int
    {
        return (int) (self::for($sportKey)['publish_window'] ?? 0);
    }

    public static function enabledSportKeys(): array
    {
        return array_keys(self::all());
    }
}
