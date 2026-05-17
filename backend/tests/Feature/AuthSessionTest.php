<?php

namespace Tests\Feature;

use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_own_data(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user');

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
    }

    public function test_unauthenticated_user_cannot_get_user_data(): void
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(401);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);
    }

    public function test_user_can_logout_all_devices(): void
    {
        $user = User::factory()->create();

        // Create multiple tokens
        $user->createToken('device-1');
        $user->createToken('device-2');
        $user->createToken('device-3');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/logout-all');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out from all devices']);
    }

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'oldpassword123',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/change-password', [
            'current_password' => 'oldpassword123',
            'new_password' => 'newpassword456',
            'new_password_confirmation' => 'newpassword456',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Password changed successfully']);

        // Verify new password works
        $user->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword456', $user->password));
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'oldpassword123',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/change-password', [
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword456',
            'new_password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
