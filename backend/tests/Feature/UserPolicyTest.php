<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UserPolicy();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        return $user->fresh();
    }

    private function makeModerator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');
        return $user->fresh();
    }

    private function makePlayer(): User
    {
        return User::factory()->create();
    }

    // ── viewAny ───────────────────────────────────────────────────────────────

    public function test_admin_can_view_any(): void
    {
        $this->assertTrue($this->policy->viewAny($this->makeAdmin()));
    }

    public function test_moderator_can_view_any(): void
    {
        $this->assertTrue($this->policy->viewAny($this->makeModerator()));
    }

    public function test_player_cannot_view_any(): void
    {
        $this->assertFalse($this->policy->viewAny($this->makePlayer()));
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_admin_can_create(): void
    {
        $this->assertTrue($this->policy->create($this->makeAdmin()));
    }

    public function test_moderator_cannot_create(): void
    {
        $this->assertFalse($this->policy->create($this->makeModerator()));
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_admin_can_update_regular_user(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makePlayer();
        $this->assertTrue($this->policy->update($admin, $target));
    }

    public function test_moderator_can_update_regular_user(): void
    {
        $moderator = $this->makeModerator();
        $target = $this->makePlayer();
        $this->assertTrue($this->policy->update($moderator, $target));
    }

    public function test_moderator_cannot_update_admin(): void
    {
        $moderator = $this->makeModerator();
        $admin = $this->makeAdmin();
        $this->assertFalse($this->policy->update($moderator, $admin));
    }

    public function test_admin_cannot_update_self(): void
    {
        $admin = $this->makeAdmin();
        $this->assertFalse($this->policy->update($admin, $admin));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_regular_user(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makePlayer();
        $this->assertTrue($this->policy->delete($admin, $target));
    }

    public function test_admin_cannot_delete_another_admin(): void
    {
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();
        $this->assertFalse($this->policy->delete($admin1, $admin2));
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->makeAdmin();
        $this->assertFalse($this->policy->delete($admin, $admin));
    }

    public function test_moderator_cannot_delete(): void
    {
        $moderator = $this->makeModerator();
        $target = $this->makePlayer();
        $this->assertFalse($this->policy->delete($moderator, $target));
    }

    // ── ban ───────────────────────────────────────────────────────────────────

    public function test_admin_can_ban_regular_user(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makePlayer();
        $this->assertTrue($this->policy->ban($admin, $target));
    }

    public function test_moderator_can_ban_regular_user(): void
    {
        $moderator = $this->makeModerator();
        $target = $this->makePlayer();
        $this->assertTrue($this->policy->ban($moderator, $target));
    }

    public function test_nobody_can_ban_an_admin(): void
    {
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();
        $this->assertFalse($this->policy->ban($admin1, $admin2));
    }

    public function test_nobody_can_ban_self(): void
    {
        $admin = $this->makeAdmin();
        $this->assertFalse($this->policy->ban($admin, $admin));
    }

    // ── manageGameStats ───────────────────────────────────────────────────────

    public function test_admin_can_manage_game_stats(): void
    {
        $this->assertTrue($this->policy->manageGameStats($this->makeAdmin()));
    }

    public function test_moderator_cannot_manage_game_stats(): void
    {
        $this->assertFalse($this->policy->manageGameStats($this->makeModerator()));
    }
}
