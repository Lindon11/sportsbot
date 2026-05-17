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
                ['text' => '⚽ Football', 'callback_data' => 'fixtures_football'],
                ['text' => '🏀 Basketball', 'callback_data' => 'fixtures_basketball'],
            ],
            [
                ['text' => '📺 TV Guide', 'callback_data' => 'tv_guide'],
                ['text' => '🔴 Live Now', 'callback_data' => 'live_now'],
            ],
            [
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
                ['text' => '⬅ Back', 'callback_data' => 'back_main'],
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
}
