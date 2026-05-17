<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_telegram_topics', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->string('title')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->index(['chat_id', 'message_thread_id']);
        });

        Schema::create('sportsbot_telegram_routes', function (Blueprint $table) {
            $table->id();
            $table->string('route_key')->unique();
            $table->string('label');
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('fallback')->default(false);

            $table->index(['enabled', 'fallback']);
        });

        Schema::create('sportsbot_telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->string('route_key');
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('type');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->index(['route_key', 'status']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_telegram_messages');
        Schema::dropIfExists('sportsbot_telegram_routes');
        Schema::dropIfExists('sportsbot_telegram_topics');
    }
};
