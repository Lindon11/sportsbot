<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_uptime_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->string('expected_keyword', 255)->nullable();
            $table->unsignedSmallInteger('check_interval_seconds')->default(300);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedTinyInteger('failure_threshold')->default(3);
            $table->string('alert_route_key', 60)->nullable();
            $table->boolean('alerts_enabled')->default(true);
            $table->boolean('enabled')->default(true);
            $table->string('status', 20)->default('unknown');
            $table->unsignedSmallInteger('uptime_percentage')->default(100);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->timestamp('last_offline_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('total_failures')->default(0);
            $table->timestamps();

            $table->index('enabled');
            $table->index('status');
        });

        Schema::create('sportsbot_uptime_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedMediumInteger('response_time_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->timestamp('checked_at');

            $table->index('site_id');
            $table->index('checked_at');

            $table->foreign('site_id')->references('id')->on('sportsbot_uptime_sites')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_uptime_logs');
        Schema::dropIfExists('sportsbot_uptime_sites');
    }
};
