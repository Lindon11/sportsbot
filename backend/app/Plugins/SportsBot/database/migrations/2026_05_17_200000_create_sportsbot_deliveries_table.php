<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 40);
            $table->string('route_key', 60)->nullable();
            $table->string('type', 80)->nullable();
            $table->string('status', 30)->default('sent');
            $table->string('target', 255)->nullable();
            $table->string('message_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'status']);
            $table->index(['route_key', 'created_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_deliveries');
    }
};
