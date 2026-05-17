<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Pipeline\ActionContext;
use App\Core\Services\GameHooks;
use Closure;

/**
 * Fires the 'after.{action}' hook after the core action executes.
 * Plugins can use this hook to react to results (e.g. award achievements, update stats).
 */
class AfterHooksPipe
{
    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        $hookName = 'after.' . $context->action;
        $data = GameHooks::apply($hookName, [
            'player'  => $context->player,
            'action'  => $context->action,
            'result'  => $context->result,
            'success' => $context->isSuccessful(),
        ]);

        // Allow after-hooks to mutate the result (e.g. append bonus rewards, add metadata)
        // Ensure $data is an array before checking (hooks may return null)
        if (is_array($data) && array_key_exists('result', $data)) {
            $context->result = $data['result'];
        }

        $context->addHookFired($hookName);

        return $next($context);
    }
}
