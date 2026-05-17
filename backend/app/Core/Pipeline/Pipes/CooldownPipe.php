<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Pipeline\ActionContext;
use App\Core\Services\TimerService;
use Closure;

/**
 * Sets a cooldown timer after a successful action execution.
 *
 * Services signal a cooldown by including 'cooldown_seconds' in $context->result.
 * This pipe reads that value, sets the timer via TimerService, then removes the
 * key from the result so it is not exposed to API consumers.
 *
 * If a service sets its own timer directly (legacy pattern), this pipe is a no-op
 * for that action as long as the result does not include 'cooldown_seconds'.
 *
 * Example service result:
 *   return ['success' => true, 'message' => '...', 'cooldown_seconds' => 30];
 */
class CooldownPipe
{
    public function __construct(private readonly TimerService $timerService) {}

    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        if (
            $context->isSuccessful()
            && is_array($context->result)
            && isset($context->result['cooldown_seconds'])
        ) {
            $seconds = (int) $context->result['cooldown_seconds'];

            if ($seconds > 0) {
                $this->timerService->setTimer($context->player, $context->action, $seconds);
            }

            // Strip the internal key before the result reaches the controller
            unset($context->result['cooldown_seconds']);
        }

        return $next($context);
    }
}
