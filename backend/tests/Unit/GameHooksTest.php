<?php

namespace Tests\Unit;

use App\Core\Services\GameHooks;
use Tests\TestCase;

class GameHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset static listener state between tests
        $reflection = new \ReflectionClass(GameHooks::class);
        $prop = $reflection->getProperty('listeners');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function test_apply_returns_value_unchanged_when_no_listeners(): void
    {
        $result = GameHooks::apply('some.hook', 42);
        $this->assertSame(42, $result);
    }

    public function test_listener_transforms_value(): void
    {
        GameHooks::listen('test.hook', fn($v) => $v * 2);

        $result = GameHooks::apply('test.hook', 5);
        $this->assertSame(10, $result);
    }

    public function test_multiple_listeners_chain_in_order(): void
    {
        GameHooks::listen('test.chain', fn($v) => $v + 1);
        GameHooks::listen('test.chain', fn($v) => $v * 10);

        // (3 + 1) * 10 = 40
        $result = GameHooks::apply('test.chain', 3);
        $this->assertSame(40, $result);
    }

    public function test_exception_in_listener_is_isolated_and_does_not_propagate(): void
    {
        $callCount = 0;

        GameHooks::listen('test.isolate', function ($v) {
            throw new \RuntimeException('plugin boom');
        });

        GameHooks::listen('test.isolate', function ($v) use (&$callCount) {
            $callCount++;
            return $v + 1;
        });

        // Should not throw — exception from first listener must be swallowed
        $result = GameHooks::apply('test.isolate', 10);

        // Second listener runs with the last good value (10, since first threw)
        $this->assertSame(11, $result);
        $this->assertSame(1, $callCount, 'Second listener should still execute after first throws');
    }

    public function test_exception_in_all_listeners_returns_original_value(): void
    {
        GameHooks::listen('test.all.throw', function ($v) {
            throw new \RuntimeException('boom 1');
        });
        GameHooks::listen('test.all.throw', function ($v) {
            throw new \RuntimeException('boom 2');
        });

        $result = GameHooks::apply('test.all.throw', 99);
        $this->assertSame(99, $result);
    }

    public function test_apply_with_array_value(): void
    {
        GameHooks::listen('test.array', function ($widgets) {
            $widgets['new'] = 'value';
            return $widgets;
        });

        $result = GameHooks::apply('test.array', []);
        $this->assertSame(['new' => 'value'], $result);
    }
}
