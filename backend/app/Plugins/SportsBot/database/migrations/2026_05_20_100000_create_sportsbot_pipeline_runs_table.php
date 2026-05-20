<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sportsbot_pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->string('stage', 40);
            $table->string('status', 30)->default('running');
            $table->json('options')->nullable();
            $table->json('counts')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['stage', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_pipeline_runs');
    }
};
