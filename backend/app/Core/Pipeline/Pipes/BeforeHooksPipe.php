<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Pipeline\ActionContext;
use App\Core\Services\GameHooks;
use Closure;

/**
 * Fires the 'before.{action}' hook before the core action executes.
 * Plugins can use this hook to modify the payload or log the incoming action.
 */
class BeforeHooksPipe
{
    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        $hookName = 'before.' . $context->action;
        $data = GameHooks::apply($hookName, [
            'player'  => $context->player,
            'action'  => $context->action,
            'payload' => $context->payload,
        ]);

        // Allow before-hooks to mutate the payload (e.g. modify amounts, add metadata)
        if (isset($data['payload']) && is_array($data['payload'])) {
            $context->payload = $data['payload'];
        }

        $context->addHookFired($hookName);

        return $next($context);
    }
}
