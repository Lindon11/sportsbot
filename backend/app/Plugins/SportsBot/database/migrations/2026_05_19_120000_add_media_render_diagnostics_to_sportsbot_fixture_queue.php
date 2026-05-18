<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sportsbot_fixture_queue', function (Blueprint $table) {
            $table->string('renderer_used', 40)->nullable()->after('card_path');
            $table->unsignedInteger('render_duration_ms')->nullable()->after('renderer_used');
            $table->string('template_used', 80)->nullable()->after('render_duration_ms');
            $table->string('theme_used', 80)->nullable()->after('template_used');
            $table->string('fallback_reason', 500)->nullable()->after('theme_used');
            $table->string('browser_failure_reason', 1000)->nullable()->after('fallback_reason');
            $table->json('asset_failures')->nullable()->after('browser_failure_reason');
            $table->json('render_diagnostics')->nullable()->after('asset_failures');
        });
    }

    public function down(): void
    {
        Schema::table('sportsbot_fixture_queue', function (Blueprint $table) {
            $table->dropColumn([
                'renderer_used',
                'render_duration_ms',
                'template_used',
                'theme_used',
                'fallback_reason',
                'browser_failure_reason',
                'asset_failures',
                'render_diagnostics',
            ]);
        });
    }
};
