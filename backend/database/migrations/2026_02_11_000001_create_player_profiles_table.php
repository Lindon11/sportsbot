<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Economic
            $table->bigInteger('cash')->default(1000);
            $table->bigInteger('bank')->default(0);
            $table->integer('respect')->default(0);
            $table->integer('bullets')->default(10);
            $table->integer('points')->default(0);

            // Combat stats
            $table->integer('strength')->default(10);
            $table->integer('defense')->default(10);
            $table->integer('speed')->default(10);

            // Resource stats
            $table->integer('health')->default(100);
            $table->integer('max_health')->default(100);
            $table->integer('energy')->default(100);
            $table->integer('max_energy')->default(100);
            $table->integer('nerve')->default(100);
            $table->integer('max_nerve')->default(100);

            // Progression
            $table->integer('level')->default(1);
            $table->integer('experience')->default(0);

            // Note: rank fields removed from core — provided by Progression plugin

            // Location
            // Note: location fields removed from core — provided by Travel plugin

            // Game state
            $table->string('status')->default('alive');
            $table->timestamp('jail_until')->nullable();

            // Activity tracking
            $table->timestamp('last_crime_at')->nullable();
            $table->timestamp('last_gta_at')->nullable();

            $table->timestamps();

            // Indexes for common game queries
            // rank/location indexes removed; plugins may add their own indexes
            $table->index('status');
            $table->index('jail_until');
            $table->index('experience');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profiles');
    }
};
