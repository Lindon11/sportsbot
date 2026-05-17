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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
                'captions_enabled_default' => false,
                'data_fetch_window'      => 14,
                'asset_cache_window'     => 14,
                'card_prepare_window'    => 2,
                'publish_window'         => 0,
            ],
            'fights' => [
                'sport'                  => 'fights',
                'emoji'                  => '🥊',
                'topic_key'              => TelegramRouteKeys::FIGHTS,
                'provider_sport'         => 'Fighting',
                'card_template'          => self::CARD_TEMPLATE_FIGHTER_VS_FIGHTER,
                'caption_formatter'      => self::CAPTION_COMBAT,
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
                'default_card_version'   => 'v2',
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
        return (string) (self::for($sportKey)['default_card_version'] ?? 'v2');
    }

    public static function captionsEnabledDefault(string $sportKey): bool
    {
        return (bool) (self::for($sportKey)['captions_enabled_default'] ?? false);
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
