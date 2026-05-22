<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Models\SportsBotMonitorBot;
use App\Plugins\SportsBot\Models\SportsBotUptimeSite;
use RuntimeException;

class SportsBotUptimeAlertCardService
{
    public function renderAlertCard(SportsBotUptimeSite $site, string $type, int $responseTime, ?string $error, ?int $statusCode): string
    {
        $payload = $this->alertPayload($site, $type, $responseTime, $error, $statusCode);

        return $this->renderPayload($payload, $this->cachedAlertCardPath($site, $type, $payload));
    }

    public function renderTestAlertCard(SportsBotMonitorBot $bot): string
    {
        $payload = $this->testPayload($bot);

        return $this->renderPayload($payload, $this->cachedTestCardPath($bot, $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function alertPayload(SportsBotUptimeSite $site, string $type, int $responseTime, ?string $error, ?int $statusCode): array
    {
        $status = $type === 'down' ? 'offline' : 'online';

        return [
            'mode' => 'alert',
            'type' => $type,
            'checked_at' => now()->toIso8601String(),
            'title' => $type === 'down' ? 'Server Experiencing Downtime' : 'Server Is Now Online',
            'message' => $type === 'down'
                ? "{$site->name} is currently experiencing downtime. Please wait for an update."
                : "{$site->name} is now back online and operating normally.",
            'sites' => [[
                'name' => $site->name,
                'url' => $site->url,
                'status' => $status,
                'status_code' => $statusCode,
                'response_time_ms' => $responseTime,
                'error' => $error,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testPayload(SportsBotMonitorBot $bot): array
    {
        $profileName = trim((string) $bot->name) ?: 'Monitor Bot';

        return [
            'mode' => 'alert',
            'type' => 'test',
            'kicker' => 'Monitor Bot Test',
            'checked_at' => now()->toIso8601String(),
            'title' => 'Test Alert Delivered',
            'message' => "{$profileName} can send uptime cards to this Telegram target.",
            'sites' => [[
                'name' => $profileName,
                'url' => 'Telegram delivery verified',
                'status' => 'online',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function cachedAlertCardPath(SportsBotUptimeSite $site, string $type, array $payload): string
    {
        $fingerprint = $this->payloadFingerprint([
            'site_id' => $site->id,
            'type' => $type,
            'title' => $payload['title'] ?? '',
            'message' => $payload['message'] ?? '',
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
            ],
        ]);

        return $this->cardDirectory() . '/uptime-alert-' . $site->id . '-' . $type . '-' . substr($fingerprint, 0, 16) . '.png';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cachedTestCardPath(SportsBotMonitorBot $bot, array $payload): string
    {
        $fingerprint = $this->payloadFingerprint([
            'monitor_bot_id' => $bot->id,
            'type' => 'test',
            'title' => $payload['title'] ?? '',
            'message' => $payload['message'] ?? '',
            'profile_name' => $bot->name,
        ]);

        return $this->cardDirectory() . '/uptime-test-' . ($bot->id ?: 'profile') . '-' . substr($fingerprint, 0, 16) . '.png';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderPayload(array $payload, string $outputPath): string
    {
        if (is_file($outputPath)) {
            return $outputPath;
        }

        $dir = $this->cardDirectory();
        @mkdir($dir, 0755, true);

        $inputPath = $dir . '/uptime-card-input-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($inputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $script = base_path('sportsbot-render-status.cjs');
        if (!is_file($script)) {
            @unlink($inputPath);
            throw new RuntimeException('Render script not found');
        }

        $command = sprintf(
            'node %s %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
        exec($command, $output, $exitCode);
        @unlink($inputPath);

        if ($exitCode !== 0 || !is_file($outputPath)) {
            throw new RuntimeException('Render failed: ' . implode("\n", $output));
        }

        return $outputPath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadFingerprint(array $payload): string
    {
        $templatePath = resource_path('cards/templates/uptime-card.html');
        $payload['template'] = is_file($templatePath)
            ? hash_file('sha256', $templatePath)
            : 'missing-template';

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function cardDirectory(): string
    {
        return storage_path('app/monitor-bot/cards');
    }
}
