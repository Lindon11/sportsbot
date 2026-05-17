<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfill existing users with username from name field.
     * This ensures data consistency for users created before the username column existed.
     */
    public function up(): void
    {
        // Only run if the username column exists
        if (\Schema::hasColumn('users', 'username')) {
            // Backfill users with null username using their name
            DB::statement('UPDATE users SET username = name WHERE username IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     *
     * No need to reverse this data migration - it's a one-way fix.
     */
    public function down(): void
    {
        // No rollback needed - this is a data fix
    }
};
