<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sportsbot_telegram_routes')) {
            return;
        }

        if (!Schema::hasColumn('sportsbot_telegram_routes', 'branding')) {
            Schema::table('sportsbot_telegram_routes', function (Blueprint $table): void {
                $table->json('branding')->nullable()->after('fallback');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('sportsbot_telegram_routes')) {
            return;
        }

        if (Schema::hasColumn('sportsbot_telegram_routes', 'branding')) {
            Schema::table('sportsbot_telegram_routes', function (Blueprint $table): void {
                $table->dropColumn('branding');
            });
        }
    }
};
