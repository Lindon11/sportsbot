<?php

namespace Tests\Feature;

use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email(): void
    {
        $user = User::factory()->create([
            'email' => 'player@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'login' => 'player@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'email'],
                'token',
            ]);
    }

    public function test_user_can_login_with_username(): void
    {
        $user = User::factory()->create([
            'username' => 'gangster1',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'login' => 'gangster1',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'player@example.com',
            'password' => 'correctpassword',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'login' => 'player@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    public function test_login_fails_with_nonexistent_user(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'login' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    public function test_login_updates_last_active_timestamp(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
            'last_active' => null,
        ]);

        $this->postJson('/api/v1/login', [
            'login' => $user->email,
            'password' => 'password123',
        ]);

        $user->refresh();
        $this->assertNotNull($user->last_active);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_login_requires_credentials(): void
    {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['login', 'password']);
    }
}
