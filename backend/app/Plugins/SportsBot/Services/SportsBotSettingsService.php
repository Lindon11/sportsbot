<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SportsBotSettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            if (!Schema::hasTable('sportsbot_settings')) {
                return $default;
            }

            $setting = SportsBotSetting::query()->where('key', $key)->first();

            return $setting?->value ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value): SportsBotSetting
    {
        return SportsBotSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public function has(string $key): bool
    {
        try {
            if (!Schema::hasTable('sportsbot_settings')) {
                return false;
            }

            return SportsBotSetting::query()->where('key', $key)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            if (!Schema::hasTable('sportsbot_settings')) {
                return [];
            }

            return SportsBotSetting::query()
                ->get()
                ->mapWithKeys(fn (SportsBotSetting $setting): array => [$setting->key => $setting->value])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function resolveBotToken(): string
    {
        $fromDb = trim((string) $this->get('telegram_bot_token', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
    }

    public function resolveWebhookEnabled(): bool
    {
        $fromDb = $this->get('telegram_webhook_enabled');
        if ($fromDb !== null) {
            return (bool) $fromDb;
        }

        return (bool) config('plugins.SportsBot.telegram.webhook_enabled', false);
    }
}
