<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sportsbot_epg_sources')) {
            Schema::table('sportsbot_epg_sources', function (Blueprint $table): void {
                if (! Schema::hasColumn('sportsbot_epg_sources', 'etag')) {
                    $table->string('etag')->nullable()->after('last_error');
                }
                if (! Schema::hasColumn('sportsbot_epg_sources', 'last_modified_header')) {
                    $table->string('last_modified_header')->nullable()->after('etag');
                }
                if (! Schema::hasColumn('sportsbot_epg_sources', 'content_hash')) {
                    $table->string('content_hash', 64)->nullable()->after('last_modified_header');
                }
                if (! Schema::hasColumn('sportsbot_epg_sources', 'bytes_downloaded')) {
                    $table->unsignedBigInteger('bytes_downloaded')->default(0)->after('content_hash');
                }
            });
        }

        if (Schema::hasTable('sportsbot_xmltv_programmes')) {
            Schema::table('sportsbot_xmltv_programmes', function (Blueprint $table): void {
                $table->index(['source_id', 'start_time'], 'sportsbot_xmltv_source_start_idx');
                $table->index(['canonical_channel_id', 'start_time'], 'sportsbot_xmltv_channel_start_idx');
                $table->index(['fixture_id', 'start_time'], 'sportsbot_xmltv_fixture_start_idx');
            });
        }

        if (Schema::hasTable('sportsbot_epg_fixture_matches')) {
            Schema::table('sportsbot_epg_fixture_matches', function (Blueprint $table): void {
                $table->index(['event_id', 'status'], 'sportsbot_epg_match_event_status_idx');
            });
        }

        if (! Schema::hasTable('sportsbot_epg_grabbers')) {
            Schema::create('sportsbot_epg_grabbers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('type', 60)->index();
                $table->string('region', 40)->nullable()->index();
                $table->string('command', 1000)->nullable();
                $table->json('arguments')->nullable();
                $table->string('working_directory', 1000)->nullable();
                $table->string('output_path', 1000)->nullable();
                $table->boolean('enabled')->default(false)->index();
                $table->boolean('installed')->default(false)->index();
                $table->string('status', 40)->default('missing')->index();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['type', 'name', 'region'], 'sportsbot_epg_grabber_unique');
            });
        }

        if (! Schema::hasTable('sportsbot_epg_grabber_runs')) {
            Schema::create('sportsbot_epg_grabber_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grabber_id')->nullable()->constrained('sportsbot_epg_grabbers')->nullOnDelete();
                $table->string('type', 60)->nullable()->index();
                $table->string('region', 40)->nullable()->index();
                $table->string('status', 40)->index();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->unsignedBigInteger('output_bytes')->default(0);
                $table->string('output_path', 1000)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sportsbot_epg_grabber_outputs')) {
            Schema::create('sportsbot_epg_grabber_outputs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grabber_id')->nullable()->constrained('sportsbot_epg_grabbers')->nullOnDelete();
                $table->foreignId('run_id')->nullable()->constrained('sportsbot_epg_grabber_runs')->nullOnDelete();
                $table->string('region', 40)->nullable()->index();
                $table->string('path', 1000);
                $table->string('source_url', 1000)->nullable();
                $table->unsignedBigInteger('bytes')->default(0);
                $table->string('content_hash', 64)->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_epg_grabber_outputs');
        Schema::dropIfExists('sportsbot_epg_grabber_runs');
        Schema::dropIfExists('sportsbot_epg_grabbers');

        if (Schema::hasTable('sportsbot_epg_sources')) {
            Schema::table('sportsbot_epg_sources', function (Blueprint $table): void {
                foreach (['etag', 'last_modified_header', 'content_hash', 'bytes_downloaded'] as $column) {
                    if (Schema::hasColumn('sportsbot_epg_sources', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
