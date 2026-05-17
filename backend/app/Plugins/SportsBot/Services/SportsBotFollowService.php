<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotTelegramFollow;

class SportsBotFollowService
{
    /**
     * @param array<string, mixed> $telegramUser
     * @param array<string, mixed> $target
     */
    public function follow(array $telegramUser, string $type, array $target, ?string $chatId = null): SportsBotTelegramFollow
    {
        $telegramUserId = (string) ($telegramUser['id'] ?? '');

        return SportsBotTelegramFollow::query()->updateOrCreate(
            [
                'telegram_user_id' => $telegramUserId,
                'followable_type' => $type,
                'followable_id' => (string) ($target['id'] ?? $target['idTeam'] ?? $target['idLeague'] ?? ''),
            ],
            [
                'telegram_username' => $telegramUser['username'] ?? null,
                'chat_id' => $chatId,
                'name' => (string) ($target['name'] ?? $target['strTeam'] ?? $target['strLeague'] ?? 'Followed item'),
                'sport' => $target['sport'] ?? $target['strSport'] ?? null,
                'alerts' => [
                    'goals' => true,
                    'fixtures' => true,
                    'tv' => true,
                    'live' => true,
                    'news' => true,
                ],
                'enabled' => true,
            ]
        );
    }

    public function unfollow(string $telegramUserId, string $type, string $id): int
    {
        return SportsBotTelegramFollow::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('followable_type', $type)
            ->where('followable_id', $id)
            ->delete();
    }

    /**
     * @return array<int, SportsBotTelegramFollow>
     */
    public function listForUser(string $telegramUserId): array
    {
        return SportsBotTelegramFollow::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('enabled', true)
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->all();
    }
}
