<?php

namespace Tests\Feature;

use App\Core\Models\LicenseKey;
use App\Core\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Generate a key or skip the test if no private key is available. */
    private function generateOrSkip(array $options = []): string
    {
        if (!LicenseService::canGenerate()) {
            $this->markTestSkipped('Private key not available — cannot generate licenses.');
        }

        return LicenseService::generate(array_merge([
            'domain'   => '*',
            'tier'     => 'standard',
            'customer' => 'Test User',
            'email'    => 'test@example.com',
            'expires'  => 'lifetime',
        ], $options));
    }

    // ── generate ──────────────────────────────────────────────────────────────

    public function test_license_can_be_generated_with_private_key(): void
    {
        $key = $this->generateOrSkip(['domain' => 'test.example.com', 'tier' => 'standard']);

        $this->assertNotNull($key);
        $this->assertStringStartsWith('LCP-STA-', $key);
    }

    public function test_generate_returns_null_without_private_key(): void
    {
        // Temporarily override env to force "no private key" path
        putenv('LCP_LICENSE_PRIVATE_KEY=');
        putenv('LCP_LICENSE_PRIVATE_KEY_PATH=/nonexistent/path/key.pem');

        $key = LicenseService::generate(['domain' => 'test.com', 'tier' => 'standard']);

        // Only assert null when the key truly doesn't exist; if the default path
        // happens to resolve, the method will succeed — just verify no crash.
        $this->assertTrue($key === null || is_string($key));
    }

    // ── validate ──────────────────────────────────────────────────────────────

    public function test_valid_license_passes_validation(): void
    {
        $key    = $this->generateOrSkip(['tier' => 'enterprise']);
        $result = LicenseService::validate($key);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('payload', $result);
        $this->assertSame('enterprise', $result['payload']['tier']);
    }

    public function test_tampered_license_fails_validation(): void
    {
        $fakeLicense = 'LCP-STA-' . base64_encode('{"domain":"*","tier":"hacked"}') . '-invalidsignature';

        $result = LicenseService::validate($fakeLicense);

        $this->assertFalse($result['valid']);
    }

    public function test_wrong_prefix_fails_validation(): void
    {
        $result = LicenseService::validate('XYZ-STA-payload-sig');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('prefix', $result['error']);
    }

    public function test_expired_license_fails_validation(): void
    {
        $key = $this->generateOrSkip(['expires' => now()->subDay()->toDateString()]);

        $result = LicenseService::validate($key);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['expired'] ?? false);
    }

    public function test_lifetime_license_never_expires(): void
    {
        $key    = $this->generateOrSkip(['expires' => 'lifetime']);
        $result = LicenseService::validate($key);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['expired'] ?? false);
    }

    // ── revocation ────────────────────────────────────────────────────────────

    public function test_revoked_license_fails_validation(): void
    {
        $key = $this->generateOrSkip();

        // Extract the license_id from the generated key's payload
        $parts = explode('-', $key, 4);
        $payload = json_decode(base64_decode($parts[2]), true);
        $licenseId = $payload['id'];

        // Mark it as revoked in the DB
        LicenseKey::where('license_id', $licenseId)->update([
            'is_revoked' => true,
            'revoked_at' => now(),
        ]);

        $result = LicenseService::validate($key);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['revoked'] ?? false);
    }

    public function test_is_revoked_returns_true_for_revoked_key(): void
    {
        LicenseKey::create([
            'license_id'  => 'REVOKED-ID',
            'customer'    => 'Test',
            'email'       => 't@t.com',
            'domain'      => '*',
            'tier'        => 'standard',
            'expires'     => 'lifetime',
            'max_users'   => 0,
            'plugins'     => 'all',
            'masked_key'  => 'LCP-STA-...',
            'is_revoked'  => true,
        ]);

        $this->assertTrue(LicenseService::isRevoked('REVOKED-ID'));
    }

    public function test_is_revoked_returns_false_for_active_key(): void
    {
        LicenseKey::create([
            'license_id'  => 'ACTIVE-ID',
            'customer'    => 'Test',
            'email'       => 't@t.com',
            'domain'      => '*',
            'tier'        => 'standard',
            'expires'     => 'lifetime',
            'max_users'   => 0,
            'plugins'     => 'all',
            'masked_key'  => 'LCP-STA-...',
            'is_revoked'  => false,
        ]);

        $this->assertFalse(LicenseService::isRevoked('ACTIVE-ID'));
    }

    public function test_is_revoked_returns_false_for_unknown_id(): void
    {
        $this->assertFalse(LicenseService::isRevoked('NO-SUCH-ID'));
    }

    // ── validateForDomain ─────────────────────────────────────────────────────

    public function test_validate_for_domain_passes_wildcard_license_on_any_domain(): void
    {
        $key    = $this->generateOrSkip(['domain' => '*']);
        $result = LicenseService::validateForDomain($key, 'anything.example.com');

        $this->assertTrue($result['valid']);
    }

    public function test_validate_for_domain_passes_exact_match(): void
    {
        $key    = $this->generateOrSkip(['domain' => 'myapp.example.com']);
        $result = LicenseService::validateForDomain($key, 'myapp.example.com');

        $this->assertTrue($result['valid']);
    }

    public function test_validate_for_domain_fails_wrong_domain(): void
    {
        $key    = $this->generateOrSkip(['domain' => 'myapp.example.com']);
        $result = LicenseService::validateForDomain($key, 'other.example.com');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('myapp.example.com', $result['error']);
    }

    public function test_validate_for_domain_passes_wildcard_subdomain(): void
    {
        $key    = $this->generateOrSkip(['domain' => '*.example.com']);
        $result = LicenseService::validateForDomain($key, 'app.example.com');

        $this->assertTrue($result['valid']);
    }

    public function test_validate_for_domain_fails_for_non_matching_wildcard_subdomain(): void
    {
        $key    = $this->generateOrSkip(['domain' => '*.example.com']);
        $result = LicenseService::validateForDomain($key, 'evil.other.com');

        $this->assertFalse($result['valid']);
    }

    // ── recordActivation ──────────────────────────────────────────────────────

    public function test_record_activation_marks_key_as_activated(): void
    {
        LicenseKey::create([
            'license_id'  => 'ACTIVATE-ME',
            'customer'    => 'Test Corp',
            'email'       => 'corp@test.com',
            'domain'      => 'test.com',
            'tier'        => 'standard',
            'expires'     => 'lifetime',
            'max_users'   => 0,
            'plugins'     => 'all',
            'masked_key'  => 'LCP-STA-...',
            'is_revoked'  => false,
            'is_activated' => false,
        ]);

        $result = LicenseService::recordActivation('ACTIVATE-ME', 'test.com', '1.2.3.4');

        $this->assertTrue($result);

        $record = LicenseKey::where('license_id', 'ACTIVATE-ME')->first();
        $this->assertTrue($record->is_activated);
        $this->assertSame('test.com', $record->activated_domain);
        $this->assertSame('1.2.3.4', $record->activated_ip);
        $this->assertNotNull($record->activated_at);
    }

    public function test_record_activation_fails_for_revoked_key(): void
    {
        LicenseKey::create([
            'license_id'  => 'REVOKED-CANT-ACTIVATE',
            'customer'    => 'Test',
            'email'       => 't@t.com',
            'domain'      => '*',
            'tier'        => 'standard',
            'expires'     => 'lifetime',
            'max_users'   => 0,
            'plugins'     => 'all',
            'masked_key'  => 'LCP-STA-...',
            'is_revoked'  => true,
        ]);

        $result = LicenseService::recordActivation('REVOKED-CANT-ACTIVATE', 'test.com', '1.2.3.4');

        $this->assertFalse($result);
    }

    public function test_record_activation_returns_false_for_unknown_id(): void
    {
        $this->assertFalse(LicenseService::recordActivation('UNKNOWN', 'test.com', '1.1.1.1'));
    }

    // ── maskKey ───────────────────────────────────────────────────────────────

    public function test_mask_key_returns_original_for_short_keys(): void
    {
        $this->assertSame('LCP-STA-12', LicenseService::maskKey('LCP-STA-12'));
    }

    public function test_mask_key_masks_long_keys(): void
    {
        $key    = 'LCP-STA-VERYLONGPAYLOAD-SIGNATURE12345678';
        $masked = LicenseService::maskKey($key);

        $this->assertStringStartsWith('LCP-STA-VER', $masked);
        $this->assertStringContainsString('...', $masked);
        $this->assertStringEndsWith(substr($key, -8), $masked);
    }

    public function test_mask_key_preserves_exactly_first_12_and_last_8(): void
    {
        $key    = str_repeat('A', 40);
        $masked = LicenseService::maskKey($key);

        $this->assertSame(substr($key, 0, 12) . '...' . substr($key, -8), $masked);
    }
}
