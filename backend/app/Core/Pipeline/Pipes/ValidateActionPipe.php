<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Pipeline\ActionContext;
use Closure;

/**
 * Validates the player performing the action.
 * Checks for basic conditions that would prevent any action (banned, null user).
 */
class ValidateActionPipe
{
    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        if (!$context->player) {
            $context->addError('No player in context.');
            return $context;
        }

        if ($context->player->is_banned ?? false) {
            $context->addError('Player is banned.');
            return $context;
        }

        return $next($context);
    }
}
