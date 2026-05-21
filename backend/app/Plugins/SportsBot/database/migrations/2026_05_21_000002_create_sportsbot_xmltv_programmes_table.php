<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_xmltv_programmes', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 120);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->unsignedBigInteger('fixture_id')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index('channel');
            $table->index('start_time');
            $table->index('fixture_id');
            $table->index(['start_time', 'end_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_xmltv_programmes');
    }
};
