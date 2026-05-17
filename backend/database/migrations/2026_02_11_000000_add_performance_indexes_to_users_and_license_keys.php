<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add indexes only if they don't already exist to make this migration idempotent
        $this->addIndexIfMissing('users', 'rank_id');
        $this->addIndexIfMissing('users', 'location_id');
        $this->addIndexIfMissing('users', 'status');
        $this->addIndexIfMissing('users', 'last_active');
        $this->addIndexIfMissing('users', 'jail_until');

        $this->addIndexIfMissing('license_keys', 'is_activated');
        $this->addIndexIfMissing('license_keys', 'is_revoked');
        $this->addIndexIfMissing('license_keys', 'activated_domain');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('users', 'rank_id');
        $this->dropIndexIfExists('users', 'location_id');
        $this->dropIndexIfExists('users', 'status');
        $this->dropIndexIfExists('users', 'last_active');
        $this->dropIndexIfExists('users', 'jail_until');

        $this->dropIndexIfExists('license_keys', 'is_activated');
        $this->dropIndexIfExists('license_keys', 'is_revoked');
        $this->dropIndexIfExists('license_keys', 'activated_domain');
    }

    /**
     * Add a single-column index if it does not already exist.
     */
    protected function addIndexIfMissing(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $indexName = "{$table}_{$column}_index";

        $exists = (bool) count(DB::select('SHOW INDEX FROM `'. $table . '` WHERE Key_name = ?', [$indexName]));

        if (! $exists) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->index($column);
            });
        }
    }

    /**
     * Drop a single-column index if it exists.
     */
    protected function dropIndexIfExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $indexName = "{$table}_{$column}_index";

        $exists = (bool) count(DB::select('SHOW INDEX FROM `'. $table . '` WHERE Key_name = ?', [$indexName]));

        if ($exists) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropIndex([$column]);
            });
        }
    }
};
