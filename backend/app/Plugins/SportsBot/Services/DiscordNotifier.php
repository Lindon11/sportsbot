<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\NotifierInterface;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DiscordNotifier implements NotifierInterface
{
    private const BOT_API = 'https://discord.com/api/v10';

    public function __construct(
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    private function botToken(): string
    {
        return trim((string) $this->settings->get('discord_bot_token', config('plugins.SportsBot.discord.bot_token', '')));
    }

    private function botChannels(): array
    {
        $channels = $this->normalizeChannelMap(
            $this->settings->get('discord_bot_channels', config('plugins.SportsBot.discord.bot_channels', []))
        );

        $defaultChannelId = trim((string) $this->settings->get(
            'discord_default_channel_id',
            config('plugins.SportsBot.discord.default_channel_id', '')
        ));

        if ($defaultChannelId !== '') {
            $channels[TelegramRouteKeys::DEFAULT] ??= $defaultChannelId;
        }

        return $channels;
    }

    public function send(string $message, array $options = []): array
    {
        $routeKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? TelegramRouteKeys::DEFAULT));

        $token = $this->botToken();
        if ($token !== '') {
            return $this->sendViaBot($message, $options, $routeKey);
        }

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

        $token = $this->botToken();
        if ($token !== '') {
            return $this->sendPhotoViaBot($photoPath, $caption, $options, $routeKey);
        }

        $webhooks = $this->resolveWebhooks($routeKey);

        if ($webhooks === []) {
            throw new RuntimeException('No Discord webhook configured for route: ' . $routeKey);
        }

        $payload = $this->basePayload($options);
        $payload['content'] = mb_substr($this->discordText($caption), 0, 2000);

        if (!empty($options['embed_url'])) {
            $filename = basename($photoPath);
            $payload['embeds'] = [[
                'title' => (string) ($options['embed_title'] ?? '▶ Watch Highlights'),
                'url' => (string) $options['embed_url'],
                'color' => (int) ($options['embed_color'] ?? 10181043),
                'description' => mb_substr($this->discordText($caption), 0, 500),
                'image' => ['url' => 'attachment://' . $filename],
                'footer' => ['text' => mb_substr($this->discordText((string) ($options['embed_footer'] ?? 'SportsBot')), 0, 100)],
            ]];
            $payload['content'] = '';
        }

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

    /**
     * Delete messages from a Discord channel.
     * Requires bot token mode and 'Manage Messages' permission.
     *
     * Uses single delete for 1 message, bulk delete for 2-100.
     *
     * @param array<int, string> $messageIds
     */
    public function deleteMessages(string $channelId, array $messageIds): bool
    {
        $token = $this->botToken();
        if ($token === '' || $messageIds === []) {
            return false;
        }

        $base = self::BOT_API . '/channels/' . rawurlencode($channelId);

        foreach (array_chunk($messageIds, 100) as $chunk) {
            $http = Http::withToken($token, 'Bot')->timeout(15);

            if (count($chunk) === 1) {
                $response = $http->delete($base . '/messages/' . rawurlencode($chunk[0]));
            } else {
                $response = $http->post($base . '/messages/bulk-delete', [
                    'messages' => $chunk,
                ]);
            }

            if (!$response->successful()) {
                Log::warning('sportsbot.discord.delete_failed', [
                    'channel_id' => $channelId,
                    'count' => count($chunk),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Fetch recent messages from a Discord channel and delete any posted by this bot.
     *
     * @return array{deleted:int,total:int}
     */
    public function purgeBotMessages(string $channelId, int $limit = 50): array
    {
        $token = $this->botToken();
        if ($token === '') {
            throw new RuntimeException('Discord bot token is not configured.');
        }

        // Fetch recent messages
        $resp = Http::withToken($token, 'Bot')
            ->timeout(15)
            ->get(self::BOT_API . '/channels/' . rawurlencode($channelId) . '/messages', [
                'limit' => min(100, max(1, $limit)),
            ]);

        if (!$resp->successful()) {
            throw new RuntimeException('Failed to fetch Discord messages: HTTP ' . $resp->status());
        }

        $messages = (array) $resp->json();
        $botId = $this->resolveBotUserId($token);
        $toDelete = [];

        foreach ($messages as $msg) {
            $authorId = $msg['author']['id'] ?? '';
            if ($authorId === $botId) {
                $toDelete[] = (string) $msg['id'];
            }
        }

        $total = count($toDelete);
        $deleted = 0;

        if ($toDelete !== []) {
            $deleted = $this->deleteMessages($channelId, $toDelete) ? count($toDelete) : 0;
        }

        return ['deleted' => $deleted, 'total' => $total];
    }

    private function resolveBotUserId(string $token): string
    {
        $resp = Http::withToken($token, 'Bot')
            ->timeout(10)
            ->get(self::BOT_API . '/users/@me');

        return $resp->successful() ? (string) ($resp->json('id') ?? '') : '';
    }

    public function configured(?string $routeKey = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $token = $this->botToken();
        if ($token !== '') {
            if ($routeKey === null) {
                return $this->botChannels() !== [];
            }
            return $this->resolveBotChannel($routeKey) !== '';
        }

        return $this->resolveWebhooks($routeKey ?? TelegramRouteKeys::DEFAULT) !== [];
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('discord_enabled', config('plugins.SportsBot.discord.enabled', false));
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $enabled = $this->enabled();
        $tokenConfigured = $this->botToken() !== '';
        $channels = $this->botChannels();
        $webhooks = $this->routeWebhooks();
        $defaultWebhook = trim((string) $this->settings->get('discord_default_webhook_url', config('plugins.SportsBot.discord.default_webhook_url', '')));
        $routeStatuses = [];

        foreach (TelegramRouteKeys::all() as $routeKey) {
            $botChannel = $tokenConfigured ? $this->resolveBotChannel($routeKey) : '';
            $routeWebhook = trim((string) ($webhooks[$routeKey] ?? ''));
            $hasDefaultWebhook = $defaultWebhook !== '';

            $routeStatuses[$routeKey] = [
                'configured' => $enabled && (
                    ($tokenConfigured && $botChannel !== '')
                    || (!$tokenConfigured && ($routeWebhook !== '' || $hasDefaultWebhook))
                ),
                'source' => $tokenConfigured
                    ? ($botChannel !== '' ? (isset($channels[$routeKey]) ? 'bot_channel' : 'bot_default') : 'none')
                    : ($routeWebhook !== '' ? 'webhook_route' : ($hasDefaultWebhook ? 'webhook_default' : 'none')),
                'bot_channel_configured' => $botChannel !== '',
                'webhook_configured' => $routeWebhook !== '' || $hasDefaultWebhook,
            ];
        }

        $botChannels = [];
        if ($tokenConfigured) {
            $token = $this->botToken();
            foreach ($channels as $routeKey => $channelId) {
                $name = '';
                $resp = Http::withToken($token, 'Bot')->timeout(5)->get(self::BOT_API . '/channels/' . rawurlencode($channelId));
                if ($resp->successful()) {
                    $name = (string) ($resp->json('name') ?? '');
                }
                $botChannels[$routeKey] = ['id' => $channelId, 'name' => $name];
            }
        }

        return [
            'enabled' => $enabled,
            'mode' => $tokenConfigured ? 'bot' : 'webhook',
            'bot_token_configured' => $tokenConfigured,
            'bot_channel_count' => count($channels),
            'default_bot_channel_configured' => isset($channels[TelegramRouteKeys::DEFAULT]) && trim((string) $channels[TelegramRouteKeys::DEFAULT]) !== '',
            'bot_channels' => $botChannels,
            'webhook_route_count' => count($webhooks),
            'default_webhook_configured' => $defaultWebhook !== '',
            'route_statuses' => $routeStatuses,
        ];
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
    private function sendViaBot(string $message, array $options, string $routeKey): array
    {
        $channelId = $this->resolveBotChannel($routeKey);
        if ($channelId === '') {
            throw new RuntimeException('No Discord bot channel configured for route: ' . $routeKey);
        }

        $payload = [
            'content' => mb_substr($message, 0, 2000),
            'allowed_mentions' => ['parse' => []],
        ];

        if (!empty($options['components'])) {
            $payload['components'] = $options['components'];
        }

        return $this->postToBotApi("/channels/{$channelId}/messages", $payload, $routeKey);
    }

    private function sendPhotoViaBot(string $photoPath, string $caption, array $options, string $routeKey): array
    {
        $channelId = $this->resolveBotChannel($routeKey);
        if ($channelId === '') {
            throw new RuntimeException('No Discord bot channel configured for route: ' . $routeKey);
        }

        $watchUrl = (string) ($options['embed_url'] ?? '');
        $watchLabel = (string) ($options['embed_title'] ?? '▶ Watch Highlights');

        $embeds = [];
        if ($watchUrl !== '') {
            $embeds[] = [
                'title' => $watchLabel,
                'url' => $watchUrl,
                'color' => (int) ($options['embed_color'] ?? 10181043),
                'description' => mb_substr(strip_tags($caption), 0, 500),
                'footer' => ['text' => mb_substr((string) ($options['embed_footer'] ?? 'SportsBot'), 0, 100)],
            ];
        }

        $components = [];
        if ($watchUrl !== '') {
            $components[] = [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'style' => 5,
                        'label' => $watchLabel,
                        'url' => $watchUrl,
                    ],
                ],
            ];
        }

        return $this->postToBotApiWithFile(
            "/channels/{$channelId}/messages",
            $photoPath,
            [
                'content' => mb_substr(strip_tags($caption), 0, 2000),
                'embeds' => $embeds,
                'components' => $components,
                'allowed_mentions' => ['parse' => []],
            ],
            $routeKey
        );
    }

    private function resolveBotChannel(string $routeKey): string
    {
        $channels = $this->botChannels();
        $normalized = TelegramRouteKeys::normalize($routeKey);

        $id = trim((string) ($channels[$normalized] ?? $channels[$routeKey] ?? ''));
        if ($id === '') {
            foreach ([$normalized, $routeKey, TelegramRouteKeys::DEFAULT] as $key) {
                $id = trim((string) ($channels[$key] ?? ''));
                if ($id !== '') break;
            }
        }

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeChannelMap(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $channels = [];
        $supported = array_fill_keys(TelegramRouteKeys::all(), true);
        $legacy = TelegramRouteKeys::legacyGroupRouteMap();

        foreach ($value as $key => $channelId) {
            $key = TelegramRouteKeys::normalize((string) $key);
            $channelId = trim((string) $channelId);

            if ($channelId === '') {
                continue;
            }

            if (isset($legacy[$key])) {
                foreach ($legacy[$key] as $expandedRouteKey) {
                    $channels[$expandedRouteKey] ??= $channelId;
                }

                continue;
            }

            if (isset($supported[$key])) {
                $channels[$key] = $channelId;
            }
        }

        return $channels;
    }

    private function postToBotApi(string $path, array $payload, string $routeKey): array
    {
        $token = $this->botToken();
        if ($token === '') {
            throw new RuntimeException('Discord bot token is not configured.');
        }

        $response = Http::timeout(15)
            ->withHeaders([
                'Authorization' => 'Bot ' . $token,
                'Content-Type' => 'application/json',
            ])
            ->post(self::BOT_API . $path, $payload);

        if (!$response->successful()) {
            $error = 'Discord bot API error: HTTP ' . $response->status() . ' ' . $response->body();
            throw new RuntimeException($error);
        }

        return [[
            'platform' => 'discord_bot',
            'route_key' => $routeKey,
            'message_id' => $response->json('id'),
        ]];
    }

    private function postToBotApiWithFile(string $path, string $filePath, array $payload, string $routeKey): array
    {
        $token = $this->botToken();
        if ($token === '') {
            throw new RuntimeException('Discord bot token is not configured.');
        }

        $response = Http::asMultipart()
            ->withHeaders(['Authorization' => 'Bot ' . $token])
            ->attach('files[0]', file_get_contents($filePath), basename($filePath))
            ->timeout(20)
            ->post(self::BOT_API . $path, [
                ['name' => 'payload_json', 'contents' => json_encode($payload)],
            ]);

        if (!$response->successful()) {
            $error = 'Discord bot API error: HTTP ' . $response->status() . ' ' . $response->body();
            throw new RuntimeException($error);
        }

        return [[
            'platform' => 'discord_bot',
            'route_key' => $routeKey,
            'message_id' => $response->json('id'),
        ]];
    }

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
