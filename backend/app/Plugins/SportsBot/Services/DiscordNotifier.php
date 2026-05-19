<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DiscordNotifier implements NotifierInterface
{
    public function __construct(
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    public function send(string $message, array $options = []): array
    {
        $routeKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $webhooks = $this->resolveWebhooks($routeKey);

        if ($webhooks === []) {
            throw new RuntimeException('No Discord webhook configured for route: ' . $routeKey);
        }

        $payload = $this->basePayload($options);
        $payload['content'] = mb_substr($this->discordText($message), 0, 2000);

        return $this->postToWebhooks($webhooks, $payload, $routeKey);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sendPhoto(string $photoPath, string $caption, array $options = []): array
    {
        if (!is_file($photoPath)) {
            throw new RuntimeException('SportsBot card image does not exist: ' . $photoPath);
        }

        $routeKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT));
        $webhooks = $this->resolveWebhooks($routeKey);

        if ($webhooks === []) {
            throw new RuntimeException('No Discord webhook configured for route: ' . $routeKey);
        }

        $payload = $this->basePayload($options);
        $payload['content'] = mb_substr($this->discordText($caption), 0, 2000);

        $results = [];
        $failures = [];

        foreach ($webhooks as $name => $url) {
            try {
                $response = Http::asMultipart()
                    ->attach('files[0]', file_get_contents($photoPath), basename($photoPath))
                    ->timeout(20)
                    ->post($this->waitUrl($url), [
                        [
                            'name' => 'payload_json',
                            'contents' => json_encode($payload),
                        ],
                    ]);

                if (!$response->successful()) {
                    $failures[] = "Discord send failed for {$name}: HTTP " . $response->status();
                    continue;
                }

                $results[] = [
                    'platform' => 'discord',
                    'route_key' => $routeKey,
                    'webhook' => $name,
                    'message_id' => $response->json('id'),
                    'media' => 'photo',
                ];
            } catch (Throwable $error) {
                $failures[] = "Discord send failed for {$name}: " . $error->getMessage();
            }
        }

        if ($results === [] && $failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }

        return $results;
    }

    public function configured(?string $routeKey = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        return $this->resolveWebhooks($routeKey ?? TelegramRouteKeys::DEFAULT) !== [];
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false));
    }

    /**
     * @return array<string, string>
     */
    private function resolveWebhooks(string $routeKey): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $routeKey = TelegramRouteKeys::normalize($routeKey);
        $webhooks = [];
        $routeWebhooks = $this->routeWebhooks();
        $defaultWebhook = trim((string) $this->settings->get('discord_default_webhook_url', config('plugins.SportsBot.discord.default_webhook_url', '')));

        foreach ([$routeKey, ...TelegramRouteKeys::fallbackRouteKeys($routeKey), TelegramRouteKeys::DEFAULT] as $key) {
            $url = trim((string) ($routeWebhooks[$key] ?? ''));
            if ($url !== '') {
                $webhooks[$key] = $url;
            }
        }

        if ($webhooks === [] && $defaultWebhook !== '') {
            $webhooks['default'] = $defaultWebhook;
        }

        return array_filter($webhooks, static fn (string $url): bool => str_starts_with($url, 'https://discord.com/api/webhooks/') || str_starts_with($url, 'https://discordapp.com/api/webhooks/'));
    }

    /**
     * @return array<string, string>
     */
    private function routeWebhooks(): array
    {
        $value = $this->settings->get('discord_route_webhooks', config('plugins.SportsBot.discord.route_webhooks', []));

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $webhooks = [];
        $supported = array_fill_keys(TelegramRouteKeys::all(), true);
        $legacy = TelegramRouteKeys::legacyGroupRouteMap();
        foreach ($value as $key => $url) {
            $key = TelegramRouteKeys::normalize((string) $key);
            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

            if (isset($legacy[$key])) {
                foreach ($legacy[$key] as $expandedRouteKey) {
                    $webhooks[$expandedRouteKey] ??= $url;
                }

                continue;
            }

            if (isset($supported[$key])) {
                $webhooks[$key] = $url;
            }
        }

        return $webhooks;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function basePayload(array $options): array
    {
        $payload = [
            'allowed_mentions' => ['parse' => []],
        ];

        $username = trim((string) $this->settings->get('discord_username', config('plugins.SportsBot.discord.username', 'SportsBot')));
        if ($username !== '') {
            $payload['username'] = $username;
        }

        $avatarUrl = trim((string) $this->settings->get('discord_avatar_url', config('plugins.SportsBot.discord.avatar_url', '')));
        if ($avatarUrl !== '') {
            $payload['avatar_url'] = $avatarUrl;
        }

        $fixture = (array) ($options['payload']['fixture'] ?? []);
        if ($fixture !== []) {
            $payload['embeds'] = [[
                'title' => $this->fixtureTitle($fixture),
                'fields' => array_values(array_filter([
                    $this->embedField('Competition', (string) ($fixture['league'] ?? ''), true),
                    $this->embedField('Time', (string) ($fixture['time'] ?? ''), true),
                    $this->embedField('TV', (string) ($fixture['tv_channel'] ?? ''), true),
                ])),
                'timestamp' => now()->toIso8601String(),
            ]];
        }

        return $payload;
    }

    /**
     * @param array<string, string> $webhooks
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function postToWebhooks(array $webhooks, array $payload, string $routeKey): array
    {
        $results = [];
        $failures = [];

        foreach ($webhooks as $name => $url) {
            try {
                $response = Http::timeout(15)->post($this->waitUrl($url), $payload);
                if (!$response->successful()) {
                    $failures[] = "Discord send failed for {$name}: HTTP " . $response->status();
                    continue;
                }

                $results[] = [
                    'platform' => 'discord',
                    'route_key' => $routeKey,
                    'webhook' => $name,
                    'message_id' => $response->json('id'),
                ];
            } catch (Throwable $error) {
                $failures[] = "Discord send failed for {$name}: " . $error->getMessage();
            }
        }

        if ($results === [] && $failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }

        return $results;
    }

    private function waitUrl(string $url): string
    {
        return str_contains($url, '?') ? $url . '&wait=true' : $url . '?wait=true';
    }

    private function discordText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function fixtureTitle(array $fixture): string
    {
        $home = trim((string) ($fixture['home_team'] ?? ''));
        $away = trim((string) ($fixture['away_team'] ?? ''));
        if ($home !== '' && $away !== '') {
            return "{$home} vs {$away}";
        }

        return trim((string) ($fixture['event_name'] ?? 'SportsBot fixture'));
    }

    /**
     * @return array{name:string,value:string,inline:bool}|null
     */
    private function embedField(string $name, string $value, bool $inline): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return ['name' => $name, 'value' => mb_substr($value, 0, 1024), 'inline' => $inline];
    }
}
