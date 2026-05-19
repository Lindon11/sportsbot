<?php

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sportsbot_settings')) {
            return;
        }

        $row = DB::table('sportsbot_settings')
            ->where('key', 'discord_route_webhooks')
            ->first();

        if (!$row) {
            return;
        }

        $value = $this->decodeValue($row->value ?? null);
        if ($value === []) {
            return;
        }

        $supported = array_fill_keys(TelegramRouteKeys::all(), true);
        $legacy = TelegramRouteKeys::legacyGroupRouteMap();
        $routes = [];

        foreach ($value as $key => $url) {
            $routeKey = TelegramRouteKeys::normalize((string) $key);
            $webhookUrl = trim((string) $url);

            if ($webhookUrl === '') {
                continue;
            }

            if (isset($legacy[$routeKey])) {
                foreach ($legacy[$routeKey] as $expandedRouteKey) {
                    $routes[$expandedRouteKey] ??= $webhookUrl;
                }

                continue;
            }

            if (isset($supported[$routeKey])) {
                $routes[$routeKey] = $webhookUrl;
            }
        }

        DB::table('sportsbot_settings')
            ->where('key', 'discord_route_webhooks')
            ->update([
                'value' => json_encode($routes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
