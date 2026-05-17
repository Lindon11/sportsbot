<?php

namespace App\Core\Pipeline;

use App\Core\Pipeline\Pipes\AfterHooksPipe;
use App\Core\Pipeline\Pipes\BeforeHooksPipe;
use App\Core\Pipeline\Pipes\CooldownPipe;
use App\Core\Pipeline\Pipes\ExecutePipe;
use App\Core\Pipeline\Pipes\LogActionPipe;
use App\Core\Pipeline\Pipes\RateLimitPipe;
use App\Core\Pipeline\Pipes\ValidateActionPipe;
use Illuminate\Pipeline\Pipeline;

/**
 * Central action pipeline for game actions.
 *
 * Runs an ActionContext through a series of stages:
 *   Validate → RateLimitCheck → BeforeHooks → Execute → Cooldown → AfterHooks → Log
 *
 * New plugins are encouraged to adopt this pipeline for consistent behavior
 * (rate limiting, hook integration, logging) without boilerplate.
 * Existing plugins do not need to migrate.
 *
 * Usage:
 *   $context = new ActionContext($player, 'crime.commit', ['crime_id' => $crime->id], 'crimes');
 *   $result  = app(ActionPipeline::class)->run($context, function (ActionContext $ctx) use ($crime) {
 *       // Core action logic here — result is stored in $ctx->result
 *       $ctx->result = $this->crimeService->attemptCrime($ctx->player, $crime);
 *   });
 */
class ActionPipeline
{
    public function __construct(private readonly Pipeline $pipeline) {}

    /**
     * Execute an action through the full pipeline.
     *
     * @param ActionContext $context      The action context (player, action, payload)
     * @param callable      $coreExecute  The core action logic; receives ActionContext, sets $context->result
     * @param array         $extraPipes   Optional additional pipe classes to inject after ValidateActionPipe
     */
    public function run(ActionContext $context, callable $coreExecute, array $extraPipes = []): ActionContext
    {
        // Insert any $extraPipes immediately after validation so they run
        // before rate-limiting, hooks, and execution.
        $pipes = array_merge([
            ValidateActionPipe::class,
        ], $extraPipes, [
            RateLimitPipe::class,
            BeforeHooksPipe::class,
            new ExecutePipe($coreExecute),
            CooldownPipe::class,
            AfterHooksPipe::class,
            LogActionPipe::class,
        ]);

        return $this->pipeline
            ->send($context)
            ->through($pipes)
            ->thenReturn();
    }
}
