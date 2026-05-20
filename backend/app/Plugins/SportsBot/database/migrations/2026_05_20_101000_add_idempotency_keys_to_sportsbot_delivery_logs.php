<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sportsbot_telegram_messages') && !Schema::hasColumn('sportsbot_telegram_messages', 'idempotency_key')) {
            Schema::table('sportsbot_telegram_messages', function (Blueprint $table): void {
                $table->string('idempotency_key', 160)->nullable()->after('type');
                $table->index(['idempotency_key', 'chat_id', 'message_thread_id'], 'sportsbot_tg_msg_idempotency_target_idx');
            });
        }

        if (Schema::hasTable('sportsbot_deliveries') && !Schema::hasColumn('sportsbot_deliveries', 'idempotency_key')) {
            Schema::table('sportsbot_deliveries', function (Blueprint $table): void {
                $table->string('idempotency_key', 160)->nullable()->after('type');
                $table->index(['idempotency_key', 'platform', 'target'], 'sportsbot_delivery_idempotency_target_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sportsbot_deliveries') && Schema::hasColumn('sportsbot_deliveries', 'idempotency_key')) {
            Schema::table('sportsbot_deliveries', function (Blueprint $table): void {
                $table->dropIndex('sportsbot_delivery_idempotency_target_idx');
                $table->dropColumn('idempotency_key');
            });
        }

        if (Schema::hasTable('sportsbot_telegram_messages') && Schema::hasColumn('sportsbot_telegram_messages', 'idempotency_key')) {
            Schema::table('sportsbot_telegram_messages', function (Blueprint $table): void {
                $table->dropIndex('sportsbot_tg_msg_idempotency_target_idx');
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
