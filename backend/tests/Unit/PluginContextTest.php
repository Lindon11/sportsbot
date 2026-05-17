<?php

namespace Tests\Unit;

use App\Core\Exceptions\PluginPermissionException;
use App\Core\Services\PluginContext;
use ReflectionClass;
use Tests\TestCase;

class PluginContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPluginContext();
    }

    protected function tearDown(): void
    {
        $this->resetPluginContext();
        parent::tearDown();
    }

    /**
     * Wipe static state between tests to ensure isolation.
     */
    private function resetPluginContext(): void
    {
        $ref = new ReflectionClass(PluginContext::class);

        $stack = $ref->getProperty('stack');
        $stack->setAccessible(true);
        $stack->setValue(null, []);

        $cache = $ref->getProperty('permissionsCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    /**
     * Inject permissions into the cache directly so tests do not depend
     * on the filesystem (no real plugin.json required).
     */
    private function seedPermissions(string $slug, array $permissions): void
    {
        $ref = new ReflectionClass(PluginContext::class);
        $cache = $ref->getProperty('permissionsCache');
        $cache->setAccessible(true);
        $cache->setValue(null, array_merge($cache->getValue(null), [$slug => $permissions]));
    }

    // ── active() ──────────────────────────────────────────────────────────────

    public function test_active_returns_null_when_no_context_is_set(): void
    {
        $this->assertNull(PluginContext::active());
    }

    public function test_active_returns_current_slug_after_enter(): void
    {
        $this->seedPermissions('crimes', []);
        PluginContext::enter('crimes');
        $this->assertSame('crimes', PluginContext::active());
        PluginContext::exit();
    }

    // ── nested stack ──────────────────────────────────────────────────────────

    public function test_innermost_context_is_authoritative(): void
    {
        $this->seedPermissions('gang', []);
        $this->seedPermissions('alliances', []);

        PluginContext::enter('gang');
        PluginContext::enter('alliances');

        $this->assertSame('alliances', PluginContext::active());

        PluginContext::exit();
        $this->assertSame('gang', PluginContext::active());

        PluginContext::exit();
        $this->assertNull(PluginContext::active());
    }

    // ── run() ─────────────────────────────────────────────────────────────────

    public function test_run_executes_callable_and_returns_value(): void
    {
        $this->seedPermissions('crimes', []);
        $result = PluginContext::run('crimes', fn() => 42);
        $this->assertSame(42, $result);
    }

    public function test_run_restores_context_after_completion(): void
    {
        $this->seedPermissions('crimes', []);
        PluginContext::run('crimes', fn() => null);
        $this->assertNull(PluginContext::active());
    }

    public function test_run_restores_context_even_when_exception_is_thrown(): void
    {
        $this->seedPermissions('crimes', []);

        try {
            PluginContext::run('crimes', function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(PluginContext::active(), 'Stack must be unwound after exception.');
    }

    public function test_nested_run_restores_outer_context(): void
    {
        $this->seedPermissions('gang', []);
        $this->seedPermissions('alliances', []);

        PluginContext::run('gang', function () {
            $this->assertSame('gang', PluginContext::active());

            PluginContext::run('alliances', function () {
                $this->assertSame('alliances', PluginContext::active());
            });

            $this->assertSame('gang', PluginContext::active());
        });

        $this->assertNull(PluginContext::active());
    }

    // ── assertPermission() ────────────────────────────────────────────────────

    public function test_assert_permission_always_passes_with_no_active_context(): void
    {
        // No exception expected — core code is always allowed
        PluginContext::assertPermission('economy.write');
        $this->assertTrue(true); // reached this line = pass
    }

    public function test_assert_permission_passes_when_plugin_has_declared_permission(): void
    {
        $this->seedPermissions('crimes', ['economy.write']);

        PluginContext::enter('crimes');
        PluginContext::assertPermission('economy.write');
        PluginContext::exit();

        $this->assertTrue(true);
    }

    public function test_assert_permission_throws_when_plugin_lacks_permission(): void
    {
        $this->seedPermissions('crimes', []); // no permissions declared

        PluginContext::enter('crimes');

        $this->expectException(PluginPermissionException::class);
        $this->expectExceptionMessage("Plugin 'crimes' attempted 'economy.write' without declaring it");

        try {
            PluginContext::assertPermission('economy.write');
        } finally {
            PluginContext::exit();
        }
    }

    public function test_permission_exception_exposes_slug_and_permission(): void
    {
        $this->seedPermissions('crimes', []);
        PluginContext::enter('crimes');

        try {
            PluginContext::assertPermission('economy.write');
            $this->fail('Expected PluginPermissionException');
        } catch (PluginPermissionException $e) {
            $this->assertSame('crimes', $e->pluginSlug);
            $this->assertSame('economy.write', $e->permission);
        } finally {
            PluginContext::exit();
        }
    }

    // ── hasPermission() ───────────────────────────────────────────────────────

    public function test_has_permission_returns_true_with_no_active_context(): void
    {
        $this->assertTrue(PluginContext::hasPermission('economy.write'));
    }

    public function test_has_permission_returns_true_for_declared_permission(): void
    {
        $this->seedPermissions('crimes', ['economy.write', 'inventory.read']);

        PluginContext::enter('crimes');
        $this->assertTrue(PluginContext::hasPermission('economy.write'));
        $this->assertTrue(PluginContext::hasPermission('inventory.read'));
        PluginContext::exit();
    }

    public function test_has_permission_returns_false_for_undeclared_permission(): void
    {
        $this->seedPermissions('crimes', ['economy.write']);

        PluginContext::enter('crimes');
        $this->assertFalse(PluginContext::hasPermission('combat.modify'));
        PluginContext::exit();
    }

    // ── permissions cache ─────────────────────────────────────────────────────

    public function test_permissions_are_loaded_once_per_slug(): void
    {
        // Seed via cache — if loadPermissions reads the same slug twice the
        // cached value is returned (no double filesystem hit). We can confirm
        // the cache is populated after the first enter().
        $this->seedPermissions('gang', ['economy.read']);

        PluginContext::enter('gang');
        PluginContext::exit();
        PluginContext::enter('gang'); // second enter — must use cache
        PluginContext::exit();

        $ref   = new ReflectionClass(PluginContext::class);
        $cache = $ref->getProperty('permissionsCache');
        $cache->setAccessible(true);
        $cached = $cache->getValue(null);

        $this->assertArrayHasKey('gang', $cached);
        $this->assertSame(['economy.read'], $cached['gang']);
    }
}
