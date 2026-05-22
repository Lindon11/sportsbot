<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sportsbot_epg_channel_aliases')
            && ! Schema::hasColumn('sportsbot_epg_channel_aliases', 'logo_url')) {
            Schema::table('sportsbot_epg_channel_aliases', function (Blueprint $table): void {
                $table->string('logo_url', 1000)->nullable()->after('display_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sportsbot_epg_channel_aliases')
            && Schema::hasColumn('sportsbot_epg_channel_aliases', 'logo_url')) {
            Schema::table('sportsbot_epg_channel_aliases', function (Blueprint $table): void {
                $table->dropColumn('logo_url');
            });
        }
    }
};
