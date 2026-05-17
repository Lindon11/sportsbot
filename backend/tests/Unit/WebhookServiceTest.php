<?php

namespace Tests\Unit;

use App\Core\Models\Webhook;
use App\Core\Services\WebhookService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Thin subclass that exposes isPrivateIp() for direct testing.
 */
class TestableWebhookService extends WebhookService
{
    public function publicIsPrivateIp(string $ip): bool
    {
        return $this->isPrivateIp($ip);
    }
}

class WebhookServiceTest extends TestCase
{
    private TestableWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestableWebhookService();
    }

    // ── generateSignature() ───────────────────────────────────────────────────

    public function test_generate_signature_returns_sha256_hmac(): void
    {
        $payload = ['event' => 'user.registered', 'data' => ['id' => 1]];
        $secret  = 'my-secret';

        $sig = $this->service->generateSignature($payload, $secret);

        $this->assertStringStartsWith('sha256=', $sig);

        $expected = 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
        $this->assertSame($expected, $sig);
    }

    public function test_generate_signature_returns_empty_string_for_null_secret(): void
    {
        $sig = $this->service->generateSignature(['foo' => 'bar'], null);

        $this->assertSame('', $sig);
    }

    public function test_generate_signature_is_deterministic(): void
    {
        $payload = ['event' => 'crime.completed'];
        $secret  = 'stable-secret';

        $this->assertSame(
            $this->service->generateSignature($payload, $secret),
            $this->service->generateSignature($payload, $secret)
        );
    }

    // ── verifySignature() ─────────────────────────────────────────────────────

    public function test_verify_signature_passes_for_correct_signature(): void
    {
        $body   = '{"event":"user.registered"}';
        $secret = 'webhook-secret';
        $sig    = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->service->verifySignature($body, $sig, $secret));
    }

    public function test_verify_signature_fails_for_tampered_body(): void
    {
        $original = '{"event":"user.registered"}';
        $tampered = '{"event":"user.admin"}';
        $secret   = 'webhook-secret';
        $sig      = 'sha256=' . hash_hmac('sha256', $original, $secret);

        $this->assertFalse($this->service->verifySignature($tampered, $sig, $secret));
    }

    public function test_verify_signature_fails_for_wrong_secret(): void
    {
        $body      = '{"event":"crime.completed"}';
        $sig       = 'sha256=' . hash_hmac('sha256', $body, 'correct-secret');

        $this->assertFalse($this->service->verifySignature($body, $sig, 'wrong-secret'));
    }

    public function test_verify_signature_fails_for_missing_prefix(): void
    {
        $body   = '{"event":"user.login"}';
        $secret = 'my-secret';
        $rawSig = hash_hmac('sha256', $body, $secret); // no 'sha256=' prefix

        $this->assertFalse($this->service->verifySignature($body, $rawSig, $secret));
    }

    // ── isPrivateIp() — IPv4 ranges ───────────────────────────────────────────

    public function test_loopback_127_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('127.0.0.1'));
    }

    public function test_loopback_127_255_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('127.255.255.255'));
    }

    public function test_rfc1918_10_block_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('10.0.0.1'));
        $this->assertTrue($this->service->publicIsPrivateIp('10.255.255.255'));
    }

    public function test_rfc1918_172_block_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('172.16.0.1'));
        $this->assertTrue($this->service->publicIsPrivateIp('172.31.255.255'));
    }

    public function test_rfc1918_192_168_block_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('192.168.0.1'));
        $this->assertTrue($this->service->publicIsPrivateIp('192.168.255.255'));
    }

    public function test_link_local_169_254_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('169.254.0.1'));
        $this->assertTrue($this->service->publicIsPrivateIp('169.254.169.254')); // AWS metadata
    }

    public function test_public_ipv4_is_not_private(): void
    {
        $this->assertFalse($this->service->publicIsPrivateIp('8.8.8.8'));
        $this->assertFalse($this->service->publicIsPrivateIp('1.1.1.1'));
        $this->assertFalse($this->service->publicIsPrivateIp('104.26.10.10'));
    }

    // ── isPrivateIp() — IPv6 ranges ───────────────────────────────────────────

    public function test_ipv6_loopback_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('::1'));
    }

    public function test_ipv6_unique_local_fd_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('fd00::1'));
    }

    public function test_ipv6_unique_local_fc_is_private(): void
    {
        $this->assertTrue($this->service->publicIsPrivateIp('fc00::1'));
    }

    // ── deliver() — SSRF protection ───────────────────────────────────────────

    public function test_deliver_throws_for_http_url(): void
    {
        $webhook = new Webhook([
            'url'         => 'http://example.com/hook',
            'secret'      => 'secret',
            'headers'     => [],
            'retry_count' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must use HTTPS');

        $this->service->deliver($webhook, 'user.registered', []);
    }

    public function test_deliver_throws_for_invalid_url(): void
    {
        $webhook = new Webhook([
            'url'         => 'https://',
            'secret'      => 'secret',
            'headers'     => [],
            'retry_count' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid webhook URL');

        $this->service->deliver($webhook, 'user.registered', []);
    }
}
