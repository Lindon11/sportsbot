<?php

namespace Tests\Unit;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Models\SportsBotDelivery;
use App\Plugins\SportsBot\Models\SportsBotFixtureQueue;
use App\Plugins\SportsBot\Services\Content\HighlightsContentModule;
use App\Plugins\SportsBot\Services\SportsBotCardRenderer;
use App\Plugins\SportsBot\Services\SportsBotNotifier;
use App\Plugins\SportsBot\Services\SportsBotPublisher;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Services\TelegramRoutingService;
use App\Plugins\SportsBot\Services\TheSportsDbClient;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SportsBotHighlightsContentModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createFixtureQueueTable();
        $this->createDeliveriesTable();
        $this->configureHighlightLeagues();
        Cache::flush();
    }

    public function test_highlights_only_include_events_from_sent_fixture_queue_rows(): void
    {
        $today = now()->toDateString();
        $provider = $this->providerWithEvents([
            $this->event('sent-event', $today),
            $this->event('ready-event', $today),
            $this->event('unknown-event', $today),
        ]);

        SportsBotFixtureQueue::query()->create([
            'event_id' => 'sent-event',
            'sport_key' => 'football',
            'publish_date' => $today,
            'status' => SportsBotFixtureQueue::STATUS_SENT,
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'telegram_message_id' => 12345,
            'fixture_data' => [
                'event_name' => 'Sent Home vs Sent Away',
                'home_team' => 'Sent Home',
                'away_team' => 'Sent Away',
                'league' => 'Posted League',
            ],
            'sent_at' => now(),
        ]);

        SportsBotFixtureQueue::query()->create([
            'event_id' => 'ready-event',
            'sport_key' => 'football',
            'publish_date' => $today,
            'status' => SportsBotFixtureQueue::STATUS_READY,
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'telegram_message_id' => 67890,
            'sent_at' => now(),
        ]);

        $summary = (new HighlightsContentModule(
            $provider,
            new SportsBotSettingsService(),
            new SportsBotCardRenderer(),
        ))->buildSummary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame(3, $summary['provider_total']);
        $this->assertSame(1, $summary['matched_total']);
        $this->assertSame(2, $summary['filtered_unposted_total']);
        $this->assertSame(['sent-event'], array_column($summary['highlights'], 'event_id'));
        $this->assertSame('12345', $summary['highlights'][0]['fixture_message_id']);
        $this->assertSame(TelegramRouteKeys::FOOTBALL, $summary['highlights'][0]['fixture_route_key']);
        $this->assertSame('Sent Home', $summary['highlights'][0]['posted_fixture_payload']['home_team']);
    }

    public function test_discord_delivery_log_can_qualify_a_sent_fixture_without_telegram_message_id(): void
    {
        $today = now()->toDateString();
        $provider = $this->providerWithEvents([
            $this->event('discord-event', $today),
            $this->event('failed-discord-event', $today),
        ]);

        $discordEntry = SportsBotFixtureQueue::query()->create([
            'event_id' => 'discord-event',
            'sport_key' => 'football',
            'publish_date' => $today,
            'status' => SportsBotFixtureQueue::STATUS_SENT,
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'telegram_message_id' => null,
            'fixture_data' => ['event_name' => 'Discord Home vs Discord Away'],
            'sent_at' => now(),
        ]);

        $failedDiscordEntry = SportsBotFixtureQueue::query()->create([
            'event_id' => 'failed-discord-event',
            'sport_key' => 'football',
            'publish_date' => $today,
            'status' => SportsBotFixtureQueue::STATUS_SENT,
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'telegram_message_id' => null,
            'fixture_data' => ['event_name' => 'Failed Home vs Failed Away'],
            'sent_at' => now(),
        ]);

        SportsBotDelivery::query()->create([
            'platform' => 'discord',
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'type' => 'FOOTBALL_FIXTURES',
            'status' => 'sent',
            'target' => 'football-channel',
            'message_id' => 'discord-message-1',
            'payload' => [
                'fixture_queue_id' => $discordEntry->id,
                'event_id' => 'discord-event',
            ],
            'sent_at' => now(),
        ]);

        SportsBotDelivery::query()->create([
            'platform' => 'discord',
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'type' => 'FOOTBALL_FIXTURES',
            'status' => 'failed',
            'target' => 'football-channel',
            'message_id' => 'discord-message-2',
            'payload' => [
                'fixture_queue_id' => $failedDiscordEntry->id,
                'event_id' => 'failed-discord-event',
            ],
            'sent_at' => now(),
        ]);

        $summary = (new HighlightsContentModule(
            $provider,
            new SportsBotSettingsService(),
            new SportsBotCardRenderer(),
        ))->buildSummary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame(2, $summary['provider_total']);
        $this->assertSame(1, $summary['matched_total']);
        $this->assertSame(['discord-event'], array_column($summary['highlights'], 'event_id'));
        $this->assertSame('discord-message-1', $summary['highlights'][0]['fixture_message_id']);
        $this->assertSame('discord', $summary['highlights'][0]['fixture_delivery_platform']);
        $this->assertNull($summary['highlights'][0]['fixture_telegram_message_id']);
        $this->assertSame('sportsbot_deliveries', $summary['highlights'][0]['fixture_delivery_proof']['source']);
    }

    public function test_sent_cache_is_applied_after_fixture_queue_match(): void
    {
        $today = now()->toDateString();
        $provider = $this->providerWithEvents([
            $this->event('sent-event', $today),
        ]);

        SportsBotFixtureQueue::query()->create([
            'event_id' => 'sent-event',
            'sport_key' => 'football',
            'publish_date' => $today,
            'status' => SportsBotFixtureQueue::STATUS_SENT,
            'route_key' => TelegramRouteKeys::FOOTBALL,
            'telegram_message_id' => 12345,
            'fixture_data' => ['event_name' => 'Sent Home vs Sent Away'],
            'sent_at' => now(),
        ]);
        Cache::put('sportsbot:highlights_sent', ['sent-event' => true], now()->addHour());

        $summary = (new HighlightsContentModule(
            $provider,
            new SportsBotSettingsService(),
            new SportsBotCardRenderer(),
        ))->buildSummary();

        $this->assertSame(0, $summary['total']);
        $this->assertSame(1, $summary['provider_total']);
        $this->assertSame(1, $summary['matched_total']);
        $this->assertSame(1, $summary['already_sent_total']);
        $this->assertSame([], $summary['highlights']);
    }

    public function test_publisher_does_not_send_when_there_are_no_eligible_highlights(): void
    {
        $notifier = new class extends SportsBotNotifier {
            public int $sendCalls = 0;
            public int $photoCalls = 0;

            public function __construct()
            {
            }

            public function send(string $message, array $options = []): array
            {
                $this->sendCalls++;

                return [['message_id' => 1]];
            }

            public function sendPhoto(string $photoPath, string $caption, array $options = []): array
            {
                $this->photoCalls++;

                return [['message_id' => 1]];
            }
        };

        $publisher = new SportsBotPublisher(
            new class extends TelegramRoutingService {
                public function resolveTargets(string $routeKey): array
                {
                    return [
                        'route_key' => $routeKey,
                        'resolved_route_key' => $routeKey,
                        'fallback' => false,
                        'target_count' => 1,
                        'targets' => [['chat_id' => '123', 'message_thread_id' => null]],
                        'source' => 'test',
                    ];
                }
            },
            $notifier,
            new SportsBotCardRenderer(),
            new SportsBotSettingsService(),
        );

        $module = new class implements SportsBotContentModuleInterface {
            public function key(): string
            {
                return 'HIGHLIGHTS';
            }

            public function label(): string
            {
                return 'Match Highlights';
            }

            public function routeKey(): string
            {
                return TelegramRouteKeys::HIGHLIGHTS;
            }

            public function buildSummary(): array
            {
                return [
                    'route_key' => TelegramRouteKeys::HIGHLIGHTS,
                    'title' => 'Match Highlights',
                    'highlights' => [],
                    'total' => 0,
                    'provider_total' => 2,
                    'filtered_out_total' => 2,
                    'already_sent_total' => 0,
                ];
            }

            public function format(array $summary): string
            {
                return 'No match highlights available.';
            }

            public function telegramOptions(array $summary): array
            {
                return ['parse_mode' => 'HTML', 'payload' => []];
            }
        };

        $result = $publisher->send($module, 'test');

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['no_eligible_highlights']);
        $this->assertSame([], $result['results']);
        $this->assertSame(0, $notifier->sendCalls);
        $this->assertSame(0, $notifier->photoCalls);
    }

    private function createFixtureQueueTable(): void
    {
        Schema::dropIfExists('sportsbot_fixture_queue');
        Schema::create('sportsbot_fixture_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 64);
            $table->string('sport_key', 40);
            $table->date('publish_date');
            $table->string('status', 20)->default('draft');
            $table->string('card_path', 500)->nullable();
            $table->text('caption')->nullable();
            $table->string('route_key', 40)->nullable();
            $table->string('topic_id', 64)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('asset_status', 20)->nullable()->default('pending');
            $table->string('payload_hash', 64)->nullable();
            $table->json('fixture_data')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    private function createDeliveriesTable(): void
    {
        Schema::dropIfExists('sportsbot_deliveries');
        Schema::create('sportsbot_deliveries', function (Blueprint $table): void {
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
        });
    }

    private function configureHighlightLeagues(): void
    {
        foreach ([
            'default_league_ids',
            'rugby_league_ids',
            'fight_league_ids',
            'formula_1_league_ids',
            'american_football_league_ids',
            'ice_hockey_league_ids',
            'cricket_league_ids',
            'basketball_league_ids',
            'baseball_league_ids',
            'tennis_league_ids',
        ] as $key) {
            config()->set('plugins.SportsBot.fixtures_today.' . $key, $key === 'default_league_ids' ? ['test-league'] : []);
        }
    }

    private function providerWithEvents(array $events): TheSportsDbClient
    {
        return new class($events) extends TheSportsDbClient {
            public function __construct(private readonly array $events)
            {
            }

            public function previousLeagueEvents(string $leagueId): array
            {
                return $leagueId === 'test-league' ? $this->events : [];
            }
        };
    }

    private function event(string $eventId, string $date): array
    {
        return [
            'idEvent' => $eventId,
            'dateEvent' => $date,
            'strEvent' => $eventId . ' Home vs Away',
            'strHomeTeam' => $eventId . ' Home',
            'strAwayTeam' => $eventId . ' Away',
            'intHomeScore' => '2',
            'intAwayScore' => '1',
            'strLeague' => 'Provider League',
            'idLeague' => 'test-league',
            'strVideo' => 'https://example.com/watch/' . $eventId,
        ];
    }
}
