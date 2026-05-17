<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Pipeline\ActionContext;
use App\Core\Services\TimerService;
use Closure;

/**
 * Checks whether the player has an active cooldown for this action.
 * Uses the action name as the timer key (e.g. 'crime.commit' → timer name 'crime.commit').
 * Stops the pipeline if a cooldown is active.
 */
class RateLimitPipe
{
    public function __construct(private readonly TimerService $timerService) {}

    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        $timerKey = $context->action;

        if ($this->timerService->hasActiveTimer($context->player, $timerKey)) {
            $remaining = $this->timerService->getRemainingSeconds($context->player, $timerKey);
            $context->addError("Action on cooldown. Try again in {$remaining} seconds.");
            $context->result = ['success' => false, 'cooldown' => $remaining];
            return $context;
        }

        return $next($context);
    }
}
