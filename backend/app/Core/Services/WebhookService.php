<?php

namespace App\Core\Services;

use App\Core\Models\Webhook;
use App\Core\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookService
{
    /**
     * Available webhook events
     */
    public const EVENTS = [
        // User events
        'user.registered',
        'user.login',
        'user.logout',
        'user.level_up',
        'user.rank_up',
        'user.banned',
        'user.unbanned',

        // Combat events
        'combat.attack',
        'combat.kill',
        'combat.death',

        // Economy events
        'economy.transaction',
        'economy.transfer',
        'economy.purchase',

        // Gang events
        'gang.created',
        'gang.member_joined',
        'gang.member_left',
        'gang.war_started',

        // Game events
        'crime.completed',
        'mission.completed',
        'achievement.unlocked',
        'item.acquired',

        // Admin events
        'admin.announcement',
        'admin.maintenance',
        'ticket.created',
        'ticket.replied',
    ];

    /**
     * Dispatch a webhook event
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $webhooks = Webhook::where('is_active', true)
            ->where(function ($q) use ($event) {
                $q->whereJsonContains('events', $event)
                  ->orWhereJsonContains('events', '*');
            })
            ->get();

        foreach ($webhooks as $webhook) {
            dispatch(function () use ($webhook, $event, $payload) {
                $this->deliver($webhook, $event, $payload);
            })->afterResponse();
        }
    }

    /**
     * Deliver webhook to endpoint
     */
    /**
     * Deliver webhook to endpoint with SSRF protection and return JSON result
     */
    public function deliver(Webhook $webhook, string $event, array $payload, int $attempt = 1): array
    {
        $fullPayload = [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => $payload,
        ];

        $signature = $this->generateSignature($fullPayload, $webhook->secret);

        // SSRF Protection: Only allow https, block private IPs, strip risky headers
        $url = $webhook->url;
        if (!Str::startsWith($url, 'https://')) {
            throw new \Exception('Webhook URL must use HTTPS.');
        }

        // Parse host and check for private IPs
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new \Exception('Invalid webhook URL.');
        }
        $ip = gethostbyname($host);
        if ($this->isPrivateIp($ip)) {
            throw new \Exception('Webhook URL resolves to a private or disallowed IP address.');
        }

        // Remove risky headers
        $userHeaders = $webhook->headers ?? [];
        $forbiddenHeaders = [
            'Host', 'X-Forwarded-For', 'X-Real-IP', 'Forwarded', 'Authorization', 'Cookie', 'Set-Cookie',
        ];
        foreach ($forbiddenHeaders as $forbidden) {
            unset($userHeaders[$forbidden]);
        }

        $headers = array_merge($userHeaders, [
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => $event,
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => (string) time(),
            'User-Agent' => 'LaravelCP-Webhook/1.0',
        ]);

        $startTime = microtime(true);
        $delivery = new WebhookDelivery([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $fullPayload,
            'attempt' => $attempt,
        ]);

        try {
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->retry($webhook->retry_count, 1000)
                ->post($url, $fullPayload);

            $delivery->fill([
                'response_code' => $response->status(),
                'response_body' => Str::limit($response->body(), 5000),
                'response_time_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'delivered_at' => now(),
            ]);

            if ($response->successful()) {
                $webhook->resetFailure();
            } else {
                $webhook->incrementFailure();
            }

            $webhook->update([
                'last_triggered_at' => now(),
                'last_response_code' => $response->status(),
            ]);

            $delivery->save();

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'response' => Str::limit($response->body(), 5000),
                'delivery_id' => $delivery->id,
                'webhook_id' => $webhook->id,
                'attempt' => $attempt,
                'error' => null,
            ];

        } catch (\Exception $e) {
            $delivery->fill([
                'response_code' => 0,
                'error' => $e->getMessage(),
                'response_time_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            $webhook->incrementFailure();

            Log::warning('Webhook delivery failed', [
                'webhook_id' => $webhook->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            $delivery->save();

            return [
                'success' => false,
                'status' => 0,
                'response' => null,
                'delivery_id' => $delivery->id,
                'webhook_id' => $webhook->id,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if an IP is private or reserved
     */
    protected function isPrivateIp($ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4 private/reserved ranges
            $privateRanges = [
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['127.0.0.0', '127.255.255.255'], // loopback
                ['169.254.0.0', '169.254.255.255'], // link-local
            ];
            $ipLong = ip2long($ip);
            foreach ($privateRanges as [$start, $end]) {
                if ($ipLong >= ip2long($start) && $ipLong <= ip2long($end)) {
                    return true;
                }
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6 private/reserved ranges
            if (Str::startsWith($ip, ['fd', 'fc', '::1'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate HMAC signature for payload
     */
    public function generateSignature(array $payload, ?string $secret): string
    {
        if (!$secret) {
            return '';
        }

        return 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Verify incoming webhook signature
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Get all available events
     */
    public function getAvailableEvents(): array
    {
        return self::EVENTS;
    }

    /**
     * Retry a failed delivery
     */
    public function retry(WebhookDelivery $delivery): array
    {
        return $this->deliver(
            $delivery->webhook,
            $delivery->event,
            $delivery->payload['data'] ?? [],
            $delivery->attempt + 1
        );
    }

    /**
     * Create a new webhook
     */
    public function create(array $data): Webhook
    {
        return Webhook::create([
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $data['secret'] ?? Str::random(32),
            'events' => $data['events'] ?? ['*'],
            'is_active' => $data['is_active'] ?? true,
            'headers' => $data['headers'] ?? [],
            'retry_count' => $data['retry_count'] ?? 3,
        ]);
    }

    /**
     * Test a webhook endpoint
     */
    public function test(Webhook $webhook): array
    {
        return $this->deliver($webhook, 'webhook.test', [
            'message' => 'This is a test webhook delivery',
            'webhook_id' => $webhook->id,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
