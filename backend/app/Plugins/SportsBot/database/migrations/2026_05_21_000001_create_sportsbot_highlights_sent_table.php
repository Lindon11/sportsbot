<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_highlights_sent', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64);
            $table->timestamp('sent_at')->nullable();

            $table->unique('event_id');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_highlights_sent');
    }
};
