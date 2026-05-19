<?php

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sportsbot_telegram_routes')) {
            return;
        }

        if ($this->hasIndex('sportsbot_telegram_routes', 'sportsbot_telegram_routes_route_key_unique')) {
            Schema::table('sportsbot_telegram_routes', function (Blueprint $table): void {
                $table->dropUnique('sportsbot_telegram_routes_route_key_unique');
            });
        }

        if (!$this->hasIndex('sportsbot_telegram_routes', 'sportsbot_telegram_routes_route_key_index')) {
            Schema::table('sportsbot_telegram_routes', function (Blueprint $table): void {
                $table->index('route_key', 'sportsbot_telegram_routes_route_key_index');
            });
        }

        foreach (TelegramRouteKeys::legacyGroupRouteMap() as $sourceRouteKey => $routeKeys) {
            $this->copyGroupedRoutes($sourceRouteKey, $routeKeys);
        }

        DB::table('sportsbot_telegram_routes')
            ->whereIn('route_key', array_keys(TelegramRouteKeys::legacyGroupRouteMap()))
            ->delete();
    }

    public function down(): void
    {
        if (!Schema::hasTable('sportsbot_telegram_routes')) {
            return;
        }

        if ($this->hasIndex('sportsbot_telegram_routes', 'sportsbot_telegram_routes_route_key_index')) {
            Schema::table('sportsbot_telegram_routes', function (Blueprint $table): void {
                $table->dropIndex('sportsbot_telegram_routes_route_key_index');
            });
        }
    }

    /**
     * @param array<int, string> $routeKeys
     */
    private function copyGroupedRoutes(string $sourceRouteKey, array $routeKeys): void
    {
        $sourceRows = DB::table('sportsbot_telegram_routes')
            ->where('route_key', $sourceRouteKey)
            ->get();

        foreach ($sourceRows as $sourceRow) {
            foreach ($routeKeys as $routeKey) {
                if ($this->routeAssignmentExists(
                    $routeKey,
                    (string) $sourceRow->chat_id,
                    $sourceRow->message_thread_id !== null ? (int) $sourceRow->message_thread_id : null
                )) {
                    continue;
                }

                DB::table('sportsbot_telegram_routes')->insert([
                    'route_key' => $routeKey,
                    'label' => (string) ($sourceRow->label ?: $routeKey),
                    'chat_id' => (string) $sourceRow->chat_id,
                    'message_thread_id' => $sourceRow->message_thread_id,
                    'enabled' => (bool) $sourceRow->enabled,
                    'fallback' => false,
                ]);
            }
        }
    }

    private function routeAssignmentExists(string $routeKey, string $chatId, ?int $threadId): bool
    {
        $query = DB::table('sportsbot_telegram_routes')
            ->where('route_key', $routeKey)
            ->where('chat_id', $chatId);

        if ($threadId === null) {
            $query->whereNull('message_thread_id');
        } else {
            $query->where('message_thread_id', $threadId);
        }

        return $query->exists();
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? '') === $indexName) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
