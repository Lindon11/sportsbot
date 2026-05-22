<?php

namespace Tests\Unit;

use App\Core\Services\MonitorBotTelegramNotifier;
use App\Plugins\SportsBot\Console\Commands\SportsBotUptimeCheckCommand;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use Illuminate\Support\Facades\File;
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
        $notifier->shouldReceive('configured')->once()->andReturnTrue();
        $notifier->shouldReceive('sendPhoto')->once()->with($path, '')->andThrow(new RuntimeException('photo upload failed'));
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
