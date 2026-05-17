<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_banned')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_banned')->default(false)->after('remember_token');
            $table->index('is_banned');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_banned']);
            $table->dropColumn('is_banned');
        });
    }
};
