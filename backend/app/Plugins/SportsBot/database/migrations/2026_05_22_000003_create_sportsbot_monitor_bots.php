<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sportsbot_monitor_bots')) {
            Schema::create('sportsbot_monitor_bots', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('owner_label', 120)->nullable();
                $table->text('telegram_token')->nullable();
                $table->string('telegram_chat_id', 120);
                $table->unsignedBigInteger('telegram_message_thread_id')->nullable();
                $table->text('telegram_extra_targets')->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('sportsbot_uptime_sites')
            && ! Schema::hasColumn('sportsbot_uptime_sites', 'monitor_bot_id')) {
            Schema::table('sportsbot_uptime_sites', function (Blueprint $table): void {
                $table->foreignId('monitor_bot_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sportsbot_monitor_bots')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sportsbot_uptime_sites')
            && Schema::hasColumn('sportsbot_uptime_sites', 'monitor_bot_id')) {
            Schema::table('sportsbot_uptime_sites', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('monitor_bot_id');
            });
        }

        Schema::dropIfExists('sportsbot_monitor_bots');
    }
};
