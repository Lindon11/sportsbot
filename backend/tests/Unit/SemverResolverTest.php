<?php

namespace Tests\Unit;

use App\Core\Services\SemverResolver;
use Tests\TestCase;

class SemverResolverTest extends TestCase
{
    private SemverResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SemverResolver();
    }

    // ── Wildcard ──────────────────────────────────────────────────────────────

    public function test_wildcard_satisfies_any_version(): void
    {
        $this->assertTrue($this->resolver->satisfies('99.0.0', '*'));
        $this->assertTrue($this->resolver->satisfies('0.0.1', '*'));
    }

    public function test_empty_constraint_satisfies_any_version(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.3', ''));
    }

    // ── Caret ─────────────────────────────────────────────────────────────────

    public function test_caret_allows_same_major(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.3', '^1.0'));
        $this->assertTrue($this->resolver->satisfies('1.99.0', '^1.0'));
    }

    public function test_caret_rejects_different_major(): void
    {
        $this->assertFalse($this->resolver->satisfies('2.0.0', '^1.0'));
        $this->assertFalse($this->resolver->satisfies('0.9.9', '^1.0'));
    }

    public function test_caret_zero_major_allows_same_minor(): void
    {
        $this->assertTrue($this->resolver->satisfies('0.2.5', '^0.2'));
        $this->assertFalse($this->resolver->satisfies('0.3.0', '^0.2'));
    }

    // ── Tilde ─────────────────────────────────────────────────────────────────

    public function test_tilde_allows_same_minor(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.5', '~1.2.3'));
        $this->assertTrue($this->resolver->satisfies('1.2.3', '~1.2.3'));
    }

    public function test_tilde_rejects_next_minor(): void
    {
        $this->assertFalse($this->resolver->satisfies('1.3.0', '~1.2.3'));
        $this->assertFalse($this->resolver->satisfies('2.0.0', '~1.2.3'));
    }

    // ── Range operators ───────────────────────────────────────────────────────

    public function test_gte_operator(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.0', '>=1.2.0'));
        $this->assertTrue($this->resolver->satisfies('1.3.0', '>=1.2.0'));
        $this->assertFalse($this->resolver->satisfies('1.1.9', '>=1.2.0'));
    }

    public function test_gt_operator(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.1', '>1.2.0'));
        $this->assertFalse($this->resolver->satisfies('1.2.0', '>1.2.0'));
    }

    public function test_lte_operator(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.0', '<=1.2.0'));
        $this->assertTrue($this->resolver->satisfies('1.1.9', '<=1.2.0'));
        $this->assertFalse($this->resolver->satisfies('1.2.1', '<=1.2.0'));
    }

    public function test_lt_operator(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.1.9', '<1.2.0'));
        $this->assertFalse($this->resolver->satisfies('1.2.0', '<1.2.0'));
    }

    // ── Exact match ───────────────────────────────────────────────────────────

    public function test_exact_version_match(): void
    {
        $this->assertTrue($this->resolver->satisfies('1.2.3', '1.2.3'));
        $this->assertTrue($this->resolver->satisfies('1.2.3', '=1.2.3'));
        $this->assertFalse($this->resolver->satisfies('1.2.4', '1.2.3'));
    }
}
