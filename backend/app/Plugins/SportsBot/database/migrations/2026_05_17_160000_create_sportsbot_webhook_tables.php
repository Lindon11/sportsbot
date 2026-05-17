<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_telegram_update_states', function (Blueprint $table) {
            $table->id();
            $table->string('update_id', 64)->unique();
            $table->string('type')->nullable()->index(); // message, callback_query
            $table->string('chat_id', 64)->nullable()->index();
            $table->string('message_thread_id', 64)->nullable()->index();
            $table->string('callback_data', 255)->nullable();
            $table->string('callback_query_id', 128)->nullable();
            $table->string('telegram_message_id', 64)->nullable();
            $table->string('status', 32)->default('received')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_telegram_update_states');
    }
};
