<?php

namespace Tests\Feature;

use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles so assignRole('user') works during registration
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username' => 'testplayer',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user' => ['id', 'username', 'email'],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'testplayer',
            'email' => 'test@example.com',
        ]);
    }

    public function test_registration_requires_username(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'username' => 'newplayer',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_unique_username(): void
    {
        User::factory()->create(['username' => 'takenname']);

        $response = $this->postJson('/api/v1/register', [
            'username' => 'takenname',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username' => 'testplayer',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_requires_minimum_password_length(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username' => 'testplayer',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_new_user_gets_default_game_stats(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'username' => 'testplayer',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);

        $user = User::where('username', 'testplayer')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('player_profiles', [
            'user_id' => $user->id,
            'cash'    => 1000,
            'bullets' => 50,
            'energy'  => 100,
            'health'  => 100,
            'level'   => 1,
        ]);
    }
}
