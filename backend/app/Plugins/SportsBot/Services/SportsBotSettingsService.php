<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotSetting;
use Illuminate\Support\Facades\Schema;

class SportsBotSettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        if (!Schema::hasTable('sportsbot_settings')) {
            return $default;
        }

        $setting = SportsBotSetting::query()->where('key', $key)->first();

        return $setting?->value ?? $default;
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
        if (!Schema::hasTable('sportsbot_settings')) {
            return false;
        }

        return SportsBotSetting::query()->where('key', $key)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (!Schema::hasTable('sportsbot_settings')) {
            return [];
        }

        return SportsBotSetting::query()
            ->get()
            ->mapWithKeys(fn (SportsBotSetting $setting): array => [$setting->key => $setting->value])
            ->all();
    }
}
