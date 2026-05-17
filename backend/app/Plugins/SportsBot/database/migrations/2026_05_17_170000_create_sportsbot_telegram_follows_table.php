<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_telegram_follows', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_user_id', 64)->index();
            $table->string('telegram_username')->nullable();
            $table->string('chat_id', 64)->nullable()->index();
            $table->string('followable_type', 32);
            $table->string('followable_id', 64);
            $table->string('name')->nullable();
            $table->string('sport')->nullable()->index();
            $table->json('alerts')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['telegram_user_id', 'followable_type', 'followable_id'], 'sportsbot_follow_unique');
            $table->index(['followable_type', 'followable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_telegram_follows');
    }
};
