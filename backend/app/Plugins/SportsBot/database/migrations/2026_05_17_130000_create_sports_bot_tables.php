<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_bot_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->default('native');
            $table->boolean('dry_run')->default(false);
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sports_bot_match_states', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('live_score_id')->nullable()->index();
            $table->string('sport')->nullable()->index();
            $table->string('league_id')->nullable()->index();
            $table->string('league_name')->nullable();
            $table->string('home_team_id')->nullable();
            $table->string('away_team_id')->nullable();
            $table->string('home_team');
            $table->string('away_team');
            $table->text('home_badge')->nullable();
            $table->text('away_badge')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('progress')->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->string('raw_hash', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sports_bot_sent_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_key')->unique();
            $table->string('event_id')->index();
            $table->string('sport')->nullable()->index();
            $table->string('alert_type')->index();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_bot_sent_alerts');
        Schema::dropIfExists('sports_bot_match_states');
        Schema::dropIfExists('sports_bot_runs');
    }
};
