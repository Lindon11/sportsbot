<?php

namespace Tests\Unit;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Console\Commands\SportsBotUptimeCheckCommand;
use App\Plugins\SportsBot\Models\SportsBotMonitorBot;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class SportsBotUptimeMonitorTest extends TestCase
{
    public function test_failed_uptime_card_photo_does_not_send_text_fallback(): void
    {
        $command = app(SportsBotUptimeCheckCommand::class);
        $site = $this->site();
        $path = $this->cachedCardPath($command, $site, 'recovered');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'cached recovery card');

        $notifier = Mockery::mock(MonitorBotTelegramNotifier::class);
        $notifier->shouldReceive('configured')->once()->with(null)->andReturnTrue();
        $notifier->shouldReceive('sendPhoto')->once()->with($path, '', ['monitor_bot' => null])->andThrow(new RuntimeException('photo upload failed'));
        $notifier->shouldNotReceive('sendMessage');

        try {
            $this->callPrivate($command, 'sendAlert', [$site, 'recovered', $notifier, 188, null, 200]);
        } finally {
            File::delete($path);
        }

        $this->assertTrue(true);
    }

    public function test_cached_uptime_card_path_ignores_runtime_response_metrics(): void
    {
        $command = app(SportsBotUptimeCheckCommand::class);
        $site = $this->site();
        $firstPayload = $this->callPrivate($command, 'alertPayload', [$site, 'down', 4500, 'HTTP 503', 503]);
        $secondPayload = $this->callPrivate($command, 'alertPayload', [$site, 'down', 188, null, 200]);

        $this->assertSame(
            $this->callPrivate($command, 'cachedAlertCardPath', [$site, 'down', $firstPayload]),
            $this->callPrivate($command, 'cachedAlertCardPath', [$site, 'down', $secondPayload]),
        );
    }

    public function test_monitor_bot_profile_uses_its_own_telegram_token_for_photo_alerts(): void
    {
        config()->set('services.monitor_bot.telegram_token', 'default-token');
        config()->set('services.monitor_bot.telegram_chat_id', '-1000000000001');

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 91]], 200),
        ]);

        $path = storage_path('framework/testing/monitor-bot-profile-photo.png');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'fake alert card');

        $bot = new SportsBotMonitorBot([
            'name' => 'Customer Monitor',
            'telegram_token' => 'profile-token',
            'telegram_chat_id' => '-1000000000002',
            'enabled' => true,
        ]);

        try {
            app(MonitorBotTelegramNotifier::class)->sendPhoto($path, '', ['monitor_bot' => $bot]);
        } finally {
            File::delete($path);
        }

        Http::assertSent(fn ($request) => str_contains($request->url(), '/botprofile-token/sendPhoto'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/botdefault-token/'));
    }

    private function site(): SportsBotUptimeSite
    {
        $site = new SportsBotUptimeSite([
            'name' => 'Limitless TV',
            'url' => 'https://batman.it.com',
            'alerts_enabled' => true,
        ]);
        $site->id = 77;

        return $site;
    }

    private function cachedCardPath(SportsBotUptimeCheckCommand $command, SportsBotUptimeSite $site, string $type): string
    {
        $payload = $this->callPrivate($command, 'alertPayload', [$site, $type, 188, null, 200]);

        return $this->callPrivate($command, 'cachedAlertCardPath', [$site, $type, $payload]);
    }

    private function callPrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
