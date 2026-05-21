<?php

namespace Tests\Unit;

use App\Plugins\SportsBot\Models\SportsBotEpgCorrection;
use App\Plugins\SportsBot\Models\SportsBotEpgSource;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Models\SportsBotXmltvProgramme;
use App\Plugins\SportsBot\Services\SportsBotEpgChannelNormalizer;
use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotEpgMatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SportsBotEpgProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

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
    }
}
