<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_fixture_queue', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64);
            $table->string('sport_key', 40);
            $table->date('publish_date');
            $table->string('status', 20)->default('draft');
            $table->string('card_path', 500)->nullable();
            $table->text('caption')->nullable();
            $table->string('route_key', 40)->nullable();
            $table->string('topic_id', 64)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('asset_status', 20)->nullable()->default('pending');
            $table->string('payload_hash', 64)->nullable();
            $table->json('fixture_data')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'sport_key', 'publish_date'], 'fixture_queue_unique');
            $table->index(['status', 'publish_date']);
            $table->index('sport_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_fixture_queue');
    }
};
