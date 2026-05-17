<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: Remove game-stat columns from the users table.
 *
 * Prerequisites (run these before applying this migration):
 *   1. php artisan migrate (runs create_player_profiles + seed migrations)
 *   2. php artisan users:verify-profile-migration  — must show all checks PASS
 *
 * What this does:
 *   - Drops 24 game-stat columns that now live in player_profiles.
 *   - Drops the FK constraints on rank_id → ranks and location_id → locations first.
 *   - Drops the performance indexes added in the 000000 migration (MySQL drops
 *     column indexes automatically, but we handle named FKs explicitly).
 *
 * Rollback:
 *   The down() method re-adds the columns with their original defaults so that
 *   the seed migration can re-populate them if needed.
 *
 *   NOTE: Rolling back does NOT restore data. Re-run
 *   2026_02_11_000002_seed_player_profiles_from_users if you need the data back.
 */
return new class extends Migration
{
    /** Columns to remove from users. */
    private const GAME_COLUMNS = [
        'cash', 'bank', 'respect', 'bullets', 'points',
        'strength', 'defense', 'speed',
        'health', 'max_health', 'energy', 'max_energy', 'nerve', 'max_nerve',
        'level', 'experience', 'rank', 'rank_id',
        'location', 'location_id',
        'status', 'jail_until', 'last_crime_at', 'last_gta_at',
    ];

    public function up(): void
    {
        // Idempotency: if none of the game columns remain in users, this migration
        // has already run (or never applied). Return immediately. This also makes
        // migrate:fresh safe in test environments where player_profiles is empty.
        $existingColumns = Schema::getColumnListing('users');
        $toDrop = array_values(array_intersect(self::GAME_COLUMNS, $existingColumns));

        if (empty($toDrop)) {
            return;
        }

        // Production safety guard: refuse to drop columns if player_profiles has
        // not been populated. Skip in test environments where migrate:fresh
        // legitimately creates an empty player_profiles table.
        // Also skip if there are no users (fresh database with no data to migrate).
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? app()->environment();
        $userCount = DB::table('users')->count();
        if ($env !== 'testing' && $userCount > 0) {
            if (! Schema::hasTable('player_profiles') || DB::table('player_profiles')->count() === 0) {
                throw new \RuntimeException(
                    'player_profiles table is empty or missing. ' .
                    'Run the seeder migration and verify with: php artisan users:verify-profile-migration'
                );
            }
        }

        Schema::table('users', function (Blueprint $table) use ($toDrop) {
            // Drop FK constraints before dropping the columns they reference.
            $this->dropFkIfExists($table, 'users', 'rank_id');
            $this->dropFkIfExists($table, 'users', 'location_id');

            $table->dropColumn($toDrop);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Economic
            $table->bigInteger('cash')->default(1000)->after('id');
            $table->bigInteger('bank')->default(0)->after('cash');
            $table->integer('respect')->default(0)->after('bank');
            $table->integer('bullets')->default(10)->after('respect');
            $table->integer('points')->default(0)->after('bullets');

            // Combat
            $table->integer('strength')->default(10)->after('points');
            $table->integer('defense')->default(10)->after('strength');
            $table->integer('speed')->default(10)->after('defense');

            // Resources
            $table->integer('health')->default(100)->after('speed');
            $table->integer('max_health')->default(100)->after('health');
            $table->integer('energy')->default(100)->after('max_health');
            $table->integer('max_energy')->default(100)->after('energy');
            $table->integer('nerve')->default(100)->after('max_energy');
            $table->integer('max_nerve')->default(100)->after('nerve');

            // Progression
            $table->integer('level')->default(1)->after('max_nerve');
            $table->integer('experience')->default(0)->after('level');

            // Note: rank/location columns are provided by plugins; not restored here.

            // Game state
            $table->string('status')->default('alive')->after('location_id');
            $table->timestamp('jail_until')->nullable()->after('status');
            $table->timestamp('last_crime_at')->nullable()->after('jail_until');
            $table->timestamp('last_gta_at')->nullable()->after('last_crime_at');
        });
    }

    /**
     * Drop a foreign key constraint if it exists.
     * Laravel's convention: {table}_{column}_foreign
     */
    private function dropFkIfExists(Blueprint $table, string $tableName, string $column): void
    {
        $fkName = "{$tableName}_{$column}_foreign";

        $exists = (bool) count(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$tableName, $fkName]
        ));

        if ($exists) {
            $table->dropForeign($fkName);
        }
    }
};
