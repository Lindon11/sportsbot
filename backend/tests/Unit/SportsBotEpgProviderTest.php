<?php

namespace Tests\Unit;

use App\Plugins\SportsBot\Models\SportsBotEpgCorrection;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabber;
use App\Plugins\SportsBot\Models\SportsBotEpgGrabberOutput;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use App\Plugins\SportsBot\Services\SportsBotEpgChannelNormalizer;
use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotEpgGrabberRuntime;
use App\Plugins\SportsBot\Services\SportsBotEpgMaintenance;
use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use App\Plugins\SportsBot\Services\SportsBotEpgRuntimeLock;
use App\Plugins\SportsBot\Services\SportsBotEpgSourceImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SportsBotEpgProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createTables();
    }

    public function test_channel_aliases_resolve_to_canonical_channels(): void
    {
        $normalizer = new SportsBotEpgChannelNormalizer();

        $this->assertSame('sky_sports_main_event', $normalizer->canonicalIdFor('SkySpMainEvent.uk', 'UK'));
        $this->assertSame('sky_sports_main_event', $normalizer->canonicalIdFor('Sky Sports Main Event HD', 'UK'));

        $normalizer->rememberAlias('TNT Sports Ultimate', 'tnt_sports_ultimate', 'UK', 'manual', 'TNT Sports Ultimate');

        $this->assertSame('tnt_sports_ultimate', $normalizer->canonicalIdFor('TNT Sports Ultimate HD', 'UK'));
    }

    public function test_two_agreeing_sources_auto_apply_high_confidence_fixture_match(): void
    {
        $fixture = $this->fixture([
            'event_name' => 'Arsenal vs Chelsea',
            'home_team' => 'Arsenal',
            'away_team' => 'Chelsea',
            'league' => 'Premier League',
            'dateEvent' => now()->toDateString(),
            'time' => '20:00',
        ]);

        $sourceOne = $this->source('https://example.test/uk.xml.gz', 10);
        $sourceTwo = $this->source('https://mirror.test/uk.xml.gz', 20);

        $this->programme($sourceOne, 'Sky Sports Main Event HD', 'Arsenal vs Chelsea Live', 'Premier League coverage', '19:45');
        $this->programme($sourceTwo, 'SkySpMainEvent.uk', 'Arsenal v Chelsea', 'Live Premier League', '19:50');

        $result = (new SportsBotEpgMatcher())->matchFixture($fixture, true);
        $fresh = $fixture->fresh();

        $this->assertSame('auto_applied', $result['status']);
        $this->assertGreaterThanOrEqual(0.85, $result['confidence']);
        $this->assertSame('Sky Sports Main Event HD', $fresh->fixture_data['tv_channel']);
        $this->assertSame('auto_applied', $fresh->payload['epg_match']['status']);
    }

    public function test_low_confidence_match_is_review_only_and_does_not_fill_fixture_tv(): void
    {
        $fixture = $this->fixture([
            'event_name' => 'Arsenal vs Chelsea',
            'home_team' => 'Arsenal',
            'away_team' => 'Chelsea',
            'league' => 'Premier League',
            'dateEvent' => now()->toDateString(),
            'time' => '20:00',
        ]);

        $source = $this->source('https://example.test/uk.xml.gz', 10);
        $this->programme($source, 'Sky Sports Main Event', 'Arsenal Live', 'Premier League coverage', '20:00');

        $result = (new SportsBotEpgMatcher())->matchFixture($fixture, true);
        $fresh = $fixture->fresh();

        $this->assertSame('needs_review', $result['status']);
        $this->assertArrayNotHasKey('tv_channel', $fresh->fixture_data);
        $this->assertSame('needs_review', $fresh->payload['epg_review']['status']);
    }

    public function test_rejected_correction_blocks_future_channel_match(): void
    {
        $fixture = $this->fixture([
            'event_name' => 'Arsenal vs Chelsea',
            'home_team' => 'Arsenal',
            'away_team' => 'Chelsea',
            'league' => 'Premier League',
            'dateEvent' => now()->toDateString(),
            'time' => '20:00',
        ]);

        SportsBotEpgCorrection::query()->create([
            'fixture_queue_id' => $fixture->id,
            'event_id' => $fixture->event_id,
            'canonical_channel_id' => 'sky_sports_main_event',
            'channel' => 'Sky Sports Main Event',
            'action' => 'rejected',
        ]);

        $source = $this->source('https://example.test/uk.xml.gz', 10);
        $this->programme($source, 'Sky Sports Main Event', 'Arsenal vs Chelsea Live', 'Premier League coverage', '19:45');

        $result = (new SportsBotEpgMatcher())->matchFixture($fixture, true);

        $this->assertSame('no_candidate', $result['status']);
        $this->assertArrayNotHasKey('tv_channel', $fixture->fresh()->fixture_data);
    }

    public function test_xmltv_and_json_exports_use_canonical_channel_ids(): void
    {
        $source = $this->source('https://example.test/uk.xml.gz', 10);
        $this->programme($source, 'Sky Sports Main Event', 'Arsenal vs Chelsea Live', 'Premier League coverage', '19:45');

        $exporter = new SportsBotEpgExporter();
        $xml = $exporter->exportXmltv();
        $json = $exporter->exportJson();

        $this->assertStringContainsString('<channel id="sky_sports_main_event">', $xml);
        $this->assertStringContainsString('start="', $xml);
        $this->assertSame('sky_sports_main_event', $json['programmes'][0]['channel_id']);
    }

    public function test_exports_dedupe_equivalent_programmes_and_prefer_primary_source(): void
    {
        $mirror = $this->source('https://mirror.test/uk.xml.gz', 90);
        $primary = $this->source('https://primary.test/uk.xml.gz', 10);
        $this->programme($mirror, 'SkySpMainEvent.uk', 'Arsenal v Chelsea Coverage', 'Mirror description', '19:45');
        $this->programme($primary, 'Sky Sports Main Event', 'Arsenal vs Chelsea Live', 'Primary description', '19:45');

        $exporter = new SportsBotEpgExporter();
        $json = $exporter->exportJson();
        $xml = $exporter->exportXmltv();

        $this->assertCount(1, $json['programmes']);
        $this->assertSame($primary->url, $json['programmes'][0]['source_url']);
        $this->assertSame(2, $json['programmes'][0]['source_count']);
        $this->assertSame(1, $json['stats']['duplicates_removed']);
        $this->assertSame(1, substr_count($xml, '<programme '));
    }

    public function test_uk_sports_policy_disables_non_uk_epgshare_sources(): void
    {
        $uk = $this->source('https://epgshare01.online/epgshare01/epg_ripper_UK1.xml.gz', 50);
        $us = $this->source('https://epgshare01.online/epgshare01/epg_ripper_US1.xml.gz', 60);
        $us->fill(['region' => 'US'])->save();

        $result = (new SportsBotEpgSourceImporter())->applyUkSportsPolicy();

        $this->assertSame(1, $result['enabled_uk_sources']);
        $this->assertSame(1, $result['disabled_non_uk_epgshare_sources']);
        $this->assertTrue($uk->fresh()->enabled);
        $this->assertFalse($us->fresh()->enabled);
    }

    public function test_streaming_import_reads_local_xmltv_and_skips_unchanged_output(): void
    {
        $path = storage_path('app/sportsbot/epg/testing-' . uniqid() . '.xml');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->xmltvFixture());

        try {
            $source = $this->source('file://' . $path, 5);
            $source->fill(['type' => 'grabber_output'])->save();
            $importer = new SportsBotEpgSourceImporter();

            $first = $importer->importSource($source, 3, [
                'chunk_size' => 2000,
                'max_programmes' => 50,
                'skip_unchanged' => true,
            ]);
            $second = $importer->importSource($source->fresh(), 3, [
                'chunk_size' => 2000,
                'max_programmes' => 50,
                'skip_unchanged' => true,
            ]);

            $this->assertSame('working', $first['status']);
            $this->assertSame(1, $first['programme_count']);
            $this->assertSame('skipped_unchanged', $second['status']);
            $this->assertSame(1, SportsBotXmltvProgramme::query()->where('source_id', $source->id)->count());
        } finally {
            File::delete($path);
        }
    }

    public function test_remote_import_blocks_private_feed_addresses_by_default(): void
    {
        $source = $this->source('http://127.0.0.1/internal-guide.xml', 10);

        $result = (new SportsBotEpgSourceImporter())->importSource($source, 3);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Private EPG feed address', (string) $result['error']);
    }

    public function test_grabber_discovery_registers_enabled_public_feed_sources(): void
    {
        $source = $this->source('https://example.test/public-uk.xml.gz', 10);

        $discovered = (new SportsBotEpgGrabberRuntime())->discover('UK');
        $grabber = SportsBotEpgGrabber::query()
            ->where('type', 'public_xmltv_feed')
            ->where('command', $source->url)
            ->first();

        $this->assertSame(1, $discovered['public_xmltv_feed']['found']);
        $this->assertNotNull($grabber);
        $this->assertTrue($grabber->enabled);
        $this->assertTrue($grabber->installed);
    }

    public function test_batched_matcher_skips_fixtures_with_existing_tv_unless_forced(): void
    {
        $covered = $this->fixture([
            'event_name' => 'Arsenal vs Chelsea',
            'home_team' => 'Arsenal',
            'away_team' => 'Chelsea',
            'league' => 'Premier League',
            'dateEvent' => now()->toDateString(),
            'time' => '20:00',
            'tv_channel' => 'Sky Sports Main Event',
        ]);
        $missing = $this->fixture([
            'event_name' => 'Liverpool vs Spurs',
            'home_team' => 'Liverpool',
            'away_team' => 'Spurs',
            'league' => 'Premier League',
            'dateEvent' => now()->toDateString(),
            'time' => '20:00',
        ]);

        $matcher = new SportsBotEpgMatcher();
        $normal = $matcher->matchFixtures(3, 20, false);
        $forced = $matcher->matchFixtures(3, 20, false, ['force' => true]);

        $this->assertSame(1, $normal['checked']);
        $this->assertSame($missing->id, $normal['rows'][0]['fixture_queue_id']);
        $this->assertSame(2, $forced['checked']);
        $this->assertContains($covered->id, array_column($forced['rows'], 'fixture_queue_id'));
    }

    public function test_schedule_verifier_adds_public_scraper_evidence_to_match(): void
    {
        $fixture = $this->fixture([
            'event_name' => 'Arsenal vs Chelsea',
            'home_team' => 'Arsenal',
            'away_team' => 'Chelsea',
            'league' => 'Premier League',
            'dateEvent' => now()->toDateString(),
            'time' => '20:00',
        ]);
        $fixture->payload = [
            'accepted_scraped_data' => [
                'fields' => [
                    'tv_channel' => 'Sky Sports Main Event HD',
                ],
                'confidence' => 0.94,
                'source_urls' => ['https://official.example.test/schedule'],
            ],
        ];
        $fixture->save();

        $source = $this->source('https://example.test/uk.xml.gz', 10);
        $this->programme($source, 'Sky Sports Main Event', 'Arsenal Live', 'Premier League coverage', '20:00');

        $result = (new SportsBotEpgMatcher())->matchFixture($fixture, true);

        $this->assertTrue($result['evidence']['schedule_verifier']['verified']);
        $this->assertArrayHasKey('public_schedule_verifier', $result['evidence']['score_parts']);
        $this->assertContains('https://official.example.test/schedule', $result['evidence']['schedule_verifier']['source_urls']);
    }

    public function test_runtime_lock_blocks_nested_epg_jobs(): void
    {
        $lock = new SportsBotEpgRuntimeLock();

        $result = $lock->run('outer-test', function () use ($lock): array {
            return [
                'inner' => $lock->run('inner-test', fn (): array => ['ran' => true]),
                'status' => $lock->status(),
            ];
        });

        $this->assertTrue($result['status']['locked']);
        $this->assertTrue($result['inner']['locked']);
        $this->assertFalse($lock->status()['locked']);
    }

    public function test_cleanup_prunes_old_grabber_outputs_and_local_import_sources(): void
    {
        $root = storage_path('app/sportsbot/epg/grabber-cleanup-' . uniqid());
        $oldPath = $root . '/old.xml';
        $freshPath = $root . '/fresh.xml';
        File::ensureDirectoryExists($root);
        File::put($oldPath, $this->xmltvFixture());
        File::put($freshPath, $this->xmltvFixture());
        config()->set('plugins.SportsBot.epg.grabbers.output_path', $root);

        try {
            $grabber = SportsBotEpgGrabber::query()->create([
                'name' => 'cleanup-test',
                'type' => 'xmltv_command',
                'region' => 'UK',
                'enabled' => true,
                'installed' => true,
                'status' => 'available',
            ]);
            $source = $this->source('file://' . $oldPath, 5);
            $source->fill(['type' => 'grabber_output', 'enabled' => false])->save();
            $this->programme($source, 'Sky Sports Main Event', 'Old generated output', 'Old local source', '19:45');

            $old = SportsBotEpgGrabberOutput::query()->create([
                'grabber_id' => $grabber->id,
                'region' => 'UK',
                'path' => $oldPath,
                'source_url' => 'file://' . $oldPath,
                'bytes' => filesize($oldPath),
                'generated_at' => now()->subDays(7),
            ]);
            SportsBotEpgGrabberOutput::query()->create([
                'grabber_id' => $grabber->id,
                'region' => 'UK',
                'path' => $freshPath,
                'source_url' => 'file://' . $freshPath,
                'bytes' => filesize($freshPath),
                'generated_at' => now(),
            ]);

            $result = (new SportsBotEpgMaintenance())->cleanup(3, 21, 2);

            $this->assertSame(1, $result['grabber_outputs_deleted']);
            $this->assertSame(1, $result['grabber_output_files_deleted']);
            $this->assertFalse(is_file($oldPath));
            $this->assertNull($old->fresh());
            $this->assertNull($source->fresh());
            $this->assertTrue(is_file($freshPath));
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function fixture(array $fixtureData): SportsBotFixtureQueue
    {
        return SportsBotFixtureQueue::query()->create([
            'event_id' => 'event-' . uniqid(),
            'sport_key' => 'football',
            'publish_date' => now()->toDateString(),
            'status' => SportsBotFixtureQueue::STATUS_DRAFT,
            'asset_status' => SportsBotFixtureQueue::ASSET_PENDING,
            'fixture_data' => $fixtureData,
            'payload' => [],
        ]);
    }

    private function source(string $url, int $priority): SportsBotEpgSource
    {
        return SportsBotEpgSource::query()->create([
            'name' => parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'type' => 'xmltv',
            'region' => 'UK',
            'priority' => $priority,
            'enabled' => true,
            'status' => 'working',
            'stale' => false,
        ]);
    }

    private function programme(SportsBotEpgSource $source, string $channel, string $title, string $description, string $time): SportsBotXmltvProgramme
    {
        $normalizer = new SportsBotEpgChannelNormalizer();
        $start = now()->setTimeFromTimeString($time);

        return SportsBotXmltvProgramme::query()->create([
            'source_id' => $source->id,
            'source_url' => $source->url,
            'channel' => $channel,
            'canonical_channel_id' => $normalizer->canonicalIdFor($channel, 'UK'),
            'title' => $title,
            'description' => $description,
            'start_time' => $start,
            'end_time' => $start->copy()->addHours(2),
            'raw_data' => [],
        ]);
    }

    private function createTables(): void
    {
        Schema::create('sportsbot_fixture_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->nullable();
            $table->string('sport_key')->nullable();
            $table->date('publish_date')->nullable();
            $table->string('status')->default('draft');
            $table->string('asset_status')->nullable();
            $table->json('fixture_data')->nullable();
            $table->json('payload')->nullable();
            $table->string('card_path')->nullable();
            $table->text('caption')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('url');
            $table->string('type')->default('xmltv');
            $table->string('region')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->string('status')->default('working');
            $table->boolean('stale')->default(false);
            $table->integer('programme_count')->default(0);
            $table->integer('channel_count')->default(0);
            $table->integer('match_count')->default(0);
            $table->decimal('average_confidence', 5, 2)->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('etag')->nullable();
            $table->string('last_modified_header')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_url')->nullable();
            $table->string('status');
            $table->integer('programme_count')->default(0);
            $table->integer('channel_count')->default(0);
            $table->integer('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_xmltv_programmes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_url')->nullable();
            $table->string('channel');
            $table->string('canonical_channel_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->unsignedBigInteger('fixture_id')->nullable();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_channel_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_channel_id');
            $table->string('alias');
            $table->string('normalized_alias');
            $table->string('display_name')->nullable();
            $table->string('region')->nullable();
            $table->string('source')->default('system');
            $table->decimal('confidence', 5, 2)->default(1);
            $table->boolean('accepted')->default(true);
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_fixture_matches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fixture_queue_id')->nullable();
            $table->string('event_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->string('canonical_channel_id')->nullable();
            $table->string('channel')->nullable();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('status')->default('needs_review');
            $table->json('evidence')->nullable();
            $table->json('source_urls')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fixture_queue_id')->nullable();
            $table->string('event_id')->nullable();
            $table->string('canonical_channel_id')->nullable();
            $table->string('channel')->nullable();
            $table->string('action');
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_grabbers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->string('region')->nullable();
            $table->string('command')->nullable();
            $table->json('arguments')->nullable();
            $table->string('working_directory')->nullable();
            $table->string('output_path')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('installed')->default(false);
            $table->string('status')->default('missing');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['type', 'name', 'region']);
        });

        Schema::create('sportsbot_epg_grabber_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grabber_id')->nullable();
            $table->string('type')->nullable();
            $table->string('region')->nullable();
            $table->string('status');
            $table->integer('duration_ms')->default(0);
            $table->unsignedBigInteger('output_bytes')->default(0);
            $table->string('output_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sportsbot_epg_grabber_outputs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grabber_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('region')->nullable();
            $table->string('path');
            $table->string('source_url')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function xmltvFixture(): string
    {
        $start = now()->addHour()->format('YmdHis O');
        $stop = now()->addHours(3)->format('YmdHis O');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="sky.main">
    <display-name>Sky Sports Main Event HD</display-name>
  </channel>
  <programme start="{$start}" stop="{$stop}" channel="sky.main">
    <title>Arsenal vs Chelsea Live</title>
    <desc>Premier League coverage</desc>
  </programme>
</tv>
XML;
    }
}
