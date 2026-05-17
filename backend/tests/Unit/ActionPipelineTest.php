<?php

namespace Tests\Unit;

use App\Core\Models\User;
use App\Core\Pipeline\ActionContext;
use App\Core\Pipeline\ActionPipeline;
use App\Core\Pipeline\Pipes\AfterHooksPipe;
use App\Core\Pipeline\Pipes\BeforeHooksPipe;
use App\Core\Pipeline\Pipes\ExecutePipe;
use App\Core\Pipeline\Pipes\RateLimitPipe;
use App\Core\Pipeline\Pipes\ValidateActionPipe;
use App\Core\Services\GameHooks;
use App\Core\Services\TimerService;
use App\Core\Exceptions\GameException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private ActionPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = app(ActionPipeline::class);

        // Reset GameHooks listeners between tests
        $reflection = new \ReflectionClass(GameHooks::class);
        $prop = $reflection->getProperty('listeners');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ── ValidateActionPipe ────────────────────────────────────────────────────

    public function test_banned_player_is_blocked_before_execute(): void
    {
        $player = User::factory()->create(['is_banned' => true]);
        $executed = false;

        $context = new ActionContext($player, 'crime.commit', [], 'crimes');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) use (&$executed) {
            $executed = true;
            $ctx->result = ['success' => true];
        });

        $this->assertFalse($executed, 'Execute closure should not run for banned player');
        $this->assertFalse($context->isSuccessful());
        $this->assertStringContainsString('banned', strtolower($context->errors[0]));
    }

    public function test_non_banned_player_passes_validation(): void
    {
        $player = User::factory()->create(['is_banned' => false]);

        $context = new ActionContext($player, 'crime.commit', [], 'crimes');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) {
            $ctx->result = ['success' => true, 'message' => 'done'];
        });

        $this->assertTrue($context->isSuccessful());
        $this->assertSame(['success' => true, 'message' => 'done'], $context->result);
    }

    // ── RateLimitPipe ─────────────────────────────────────────────────────────

    public function test_active_timer_blocks_action(): void
    {
        $player = User::factory()->create();
        $timerService = app(TimerService::class);

        // Set a 60-second cooldown on 'crime.commit'
        $timerService->setTimer($player, 'crime.commit', 60, []);

        $executed = false;
        $context = new ActionContext($player, 'crime.commit', [], 'crimes');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) use (&$executed) {
            $executed = true;
            $ctx->result = ['success' => true];
        });

        $this->assertFalse($executed, 'Execute should be skipped when timer is active');
        $this->assertNotNull($context->result);
        $this->assertFalse($context->result['success']);
        $this->assertArrayHasKey('cooldown', $context->result);
    }

    public function test_no_active_timer_allows_action(): void
    {
        $player = User::factory()->create();

        // Ensure no timer exists
        $context = new ActionContext($player, 'unique.action.no.timer', [], 'test');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) {
            $ctx->result = ['success' => true];
        });

        $this->assertTrue($context->isSuccessful());
        $this->assertTrue($context->result['success']);
    }

    // ── ExecutePipe ───────────────────────────────────────────────────────────

    public function test_execute_pipe_catches_exception_and_adds_error(): void
    {
        $player = User::factory()->create();
        $executed = false;

        $context = new ActionContext($player, 'combat.attack', [], 'combat');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) use (&$executed) {
            $executed = true;
            throw new GameException('Target is already dead!');
        });

        $this->assertTrue($executed);
        $this->assertFalse($context->isSuccessful());
        $this->assertNotEmpty($context->errors);
        $this->assertStringContainsString('Target is already dead!', $context->errors[0]);
        // result stays null — no partial data set
        $this->assertNull($context->result);
    }

    public function test_execute_pipe_sets_result_on_success(): void
    {
        $player  = User::factory()->create();
        $payload = ['crime_id' => 42, 'reward' => 500];

        $context = new ActionContext($player, 'crime.commit', ['crime_id' => 42], 'crimes');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) use ($payload) {
            $ctx->result = array_merge(['success' => true], $payload);
        });

        $this->assertTrue($context->isSuccessful());
        $this->assertSame(42, $context->result['crime_id']);
        $this->assertSame(500, $context->result['reward']);
    }

    // ── BeforeHooksPipe ───────────────────────────────────────────────────────

    public function test_before_hook_fires_before_execute(): void
    {
        $player = User::factory()->create();
        $order  = [];

        GameHooks::listen('before.crime.commit', function ($data) use (&$order) {
            $order[] = 'before';
        });

        $context = new ActionContext($player, 'crime.commit', [], 'crimes');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) use (&$order) {
            $order[] = 'execute';
            $ctx->result = ['success' => true];
        });

        $this->assertSame(['before', 'execute'], $order);
        $this->assertContains('before.crime.commit', $context->hooksFired);
    }

    // ── AfterHooksPipe ────────────────────────────────────────────────────────

    public function test_after_hook_fires_after_execute(): void
    {
        $player = User::factory()->create();
        $order  = [];

        GameHooks::listen('after.bank.transfer', function ($data) use (&$order) {
            $order[] = 'after';
        });

        $context = new ActionContext($player, 'bank.transfer', [], 'bank');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) use (&$order) {
            $order[] = 'execute';
            $ctx->result = ['success' => true];
        });

        $this->assertSame(['execute', 'after'], $order);
        $this->assertContains('after.bank.transfer', $context->hooksFired);
    }

    public function test_after_hook_receives_result_in_data(): void
    {
        $player   = User::factory()->create();
        $captured = null;

        GameHooks::listen('after.inventory.buy', function ($data) use (&$captured) {
            $captured = $data;
        });

        $context = new ActionContext($player, 'inventory.buy', ['item_id' => 7], 'inventory');
        $context = $this->pipeline->run($context, function (ActionContext $ctx) {
            $ctx->result = ['success' => true, 'item_id' => 7];
        });

        $this->assertNotNull($captured);
        $this->assertSame(['success' => true, 'item_id' => 7], $captured['result']);
        $this->assertTrue($captured['success']);
    }

    // ── Full pipeline / integration ───────────────────────────────────────────

    public function test_full_pipeline_populates_hooks_fired(): void
    {
        $player  = User::factory()->create();
        $context = new ActionContext($player, 'combat.attack', ['defender_id' => 99], 'combat');

        $context = $this->pipeline->run($context, function (ActionContext $ctx) {
            $ctx->result = ['success' => true];
        });

        $this->assertContains('before.combat.attack', $context->hooksFired);
        $this->assertContains('after.combat.attack', $context->hooksFired);
    }

    public function test_payload_is_passed_to_before_hook(): void
    {
        $player   = User::factory()->create();
        $received = null;

        GameHooks::listen('before.crime.commit', function ($data) use (&$received) {
            $received = $data['payload'];
        });

        $context = new ActionContext($player, 'crime.commit', ['crime_id' => 5], 'crimes');
        $this->pipeline->run($context, function (ActionContext $ctx) {
            $ctx->result = ['success' => true];
        });

        $this->assertSame(['crime_id' => 5], $received);
    }
}
