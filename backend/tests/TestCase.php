<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure commonly-used roles exist for feature tests.
        try {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'sanctum']);
        } catch (\Throwable $e) {
            // Some unit tests run without the permission tables; ignore errors there.
        }
    }
}
