<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sportsbot_fixture_queue')) {
            return;
        }

        DB::table('sportsbot_fixture_queue')
            ->whereNotNull('card_path')
            ->where('card_path', 'like', '%/storage/%')
            ->orderBy('id')
            ->select(['id', 'card_path'])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $normalized = $this->normalizeStoragePath((string) $row->card_path);

                    if ($normalized === null || $normalized === (string) $row->card_path) {
                        continue;
                    }

                    DB::table('sportsbot_fixture_queue')
                        ->where('id', $row->id)
                        ->update(['card_path' => $normalized]);
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function normalizeStoragePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $needle = '/storage/';
        $position = strpos($path, $needle);

        if ($position === false) {
            return null;
        }

        $relative = substr($path, $position + strlen($needle));

        return $relative !== '' ? storage_path($relative) : null;
    }
};
