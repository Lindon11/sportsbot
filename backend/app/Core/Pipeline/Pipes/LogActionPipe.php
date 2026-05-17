<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Pipeline\ActionContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Logs the completed action to the activity log.
 * Only logs successful actions. Failed actions are logged at error level by ExecutePipe.
 */
class LogActionPipe
{
    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        if ($context->isSuccessful()) {
            try {
                if (function_exists('activity')) {
                    activity()
                        ->causedBy($context->player)
                        ->withProperties([
                            'action'      => $context->action,
                            'plugin'      => $context->pluginSlug,
                            'hooks_fired' => $context->hooksFired,
                        ])
                        ->log($context->action);
                } else {
                    // Fallback: write a structured info log when activity() helper is not available
                    Log::info('activity', [
                        'actor_id'    => $context->player->id ?? null,
                        'action'      => $context->action,
                        'plugin'      => $context->pluginSlug,
                        'hooks_fired' => $context->hooksFired,
                    ]);
                }
            } catch (\Throwable $e) {
                // Activity logging failure must never break the action
                Log::warning("ActionPipeline: activity log failed for '{$context->action}'", ['error' => $e->getMessage()]);
            }
        }

        return $next($context);
    }
}
