<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sportsbot_epg_sources')) {
            Schema::create('sportsbot_epg_sources', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('url', 1000)->unique();
                $table->string('type', 40)->default('xmltv')->index();
                $table->string('region', 40)->nullable()->index();
                $table->unsignedSmallInteger('priority')->default(100);
                $table->boolean('enabled')->default(true)->index();
                $table->string('status', 40)->default('unchecked')->index();
                $table->boolean('stale')->default(false)->index();
                $table->unsignedInteger('programme_count')->default(0);
                $table->unsignedInteger('channel_count')->default(0);
                $table->unsignedInteger('match_count')->default(0);
                $table->decimal('average_confidence', 5, 2)->default(0);
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sportsbot_epg_import_runs')) {
            Schema::create('sportsbot_epg_import_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('source_id')->nullable()->constrained('sportsbot_epg_sources')->nullOnDelete();
                $table->string('source_url', 1000)->nullable();
                $table->string('status', 40)->index();
                $table->unsignedInteger('programme_count')->default(0);
                $table->unsignedInteger('channel_count')->default(0);
                $table->unsignedInteger('matched_fixture_count')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('sportsbot_xmltv_programmes')) {
            Schema::table('sportsbot_xmltv_programmes', function (Blueprint $table): void {
                if (! Schema::hasColumn('sportsbot_xmltv_programmes', 'source_id')) {
                    $table->foreignId('source_id')->nullable()->after('id')->constrained('sportsbot_epg_sources')->nullOnDelete();
                }
                if (! Schema::hasColumn('sportsbot_xmltv_programmes', 'source_url')) {
                    $table->string('source_url', 1000)->nullable()->after('source_id');
                }
                if (! Schema::hasColumn('sportsbot_xmltv_programmes', 'confidence')) {
                    $table->decimal('confidence', 5, 2)->default(0)->after('fixture_id');
                }
                if (! Schema::hasColumn('sportsbot_xmltv_programmes', 'canonical_channel_id')) {
                    $table->string('canonical_channel_id')->nullable()->after('channel')->index();
                }
            });
        }

        if (! Schema::hasTable('sportsbot_epg_channel_aliases')) {
            Schema::create('sportsbot_epg_channel_aliases', function (Blueprint $table): void {
                $table->id();
                $table->string('canonical_channel_id')->index();
                $table->string('alias');
                $table->string('normalized_alias')->index();
                $table->string('display_name')->nullable();
                $table->string('region', 40)->nullable();
                $table->string('source', 40)->default('system');
                $table->decimal('confidence', 5, 2)->default(1);
                $table->boolean('accepted')->default(true)->index();
                $table->timestamps();
                $table->unique(['normalized_alias', 'region'], 'sportsbot_epg_alias_unique');
            });
        }

        if (! Schema::hasTable('sportsbot_epg_fixture_matches')) {
            Schema::create('sportsbot_epg_fixture_matches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fixture_queue_id')->nullable()->constrained('sportsbot_fixture_queue')->cascadeOnDelete();
                $table->string('event_id')->nullable()->index();
                $table->foreignId('programme_id')->nullable()->constrained('sportsbot_xmltv_programmes')->nullOnDelete();
                $table->string('canonical_channel_id')->nullable()->index();
                $table->string('channel')->nullable();
                $table->decimal('confidence', 5, 2)->default(0);
                $table->string('status', 40)->default('needs_review')->index();
                $table->json('evidence')->nullable();
                $table->json('source_urls')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamps();
                $table->index(['fixture_queue_id', 'status'], 'sportsbot_epg_fixture_match_status');
            });
        }

        if (! Schema::hasTable('sportsbot_epg_corrections')) {
            Schema::create('sportsbot_epg_corrections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fixture_queue_id')->nullable()->constrained('sportsbot_fixture_queue')->nullOnDelete();
                $table->string('event_id')->nullable()->index();
                $table->string('canonical_channel_id')->nullable()->index();
                $table->string('channel')->nullable();
                $table->string('action', 40)->index();
                $table->text('notes')->nullable();
                $table->json('payload')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sportsbot_epg_corrections');
        Schema::dropIfExists('sportsbot_epg_fixture_matches');
        Schema::dropIfExists('sportsbot_epg_channel_aliases');

        if (Schema::hasTable('sportsbot_xmltv_programmes')) {
            $driver = Schema::getConnection()->getDriverName();
            Schema::table('sportsbot_xmltv_programmes', function (Blueprint $table) use ($driver): void {
                if (Schema::hasColumn('sportsbot_xmltv_programmes', 'source_id')) {
                    if ($driver !== 'sqlite') {
                        $table->dropForeign(['source_id']);
                    }
                    $table->dropColumn('source_id');
                }

                foreach (['source_url', 'confidence', 'canonical_channel_id'] as $column) {
                    if (Schema::hasColumn('sportsbot_xmltv_programmes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('sportsbot_epg_import_runs');
        Schema::dropIfExists('sportsbot_epg_sources');
    }
};
