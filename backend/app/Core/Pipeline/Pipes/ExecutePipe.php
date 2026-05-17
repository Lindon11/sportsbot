<?php

namespace App\Core\Pipeline\Pipes;

use App\Core\Exceptions\GameException;
use App\Core\Pipeline\ActionContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Executes the core action logic provided to ActionPipeline::run().
 * The callable receives the ActionContext and is expected to set $context->result.
 *
 * Error policy:
 *   GameException  → player-safe message is exposed directly
 *   Any other      → full details logged; generic message returned to player
 */
class ExecutePipe
{
    public function __construct(private readonly mixed $coreExecute) {}

    public function handle(ActionContext $context, Closure $next): ActionContext
    {
        try {
            ($this->coreExecute)($context);
        } catch (GameException $e) {
            $context->addError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error("ActionPipeline execute failed for action '{$context->action}'", [
                'player_id' => $context->player->id,
                'exception' => $e,
            ]);
            $context->addError('An error occurred. Please try again.');
        }

        return $next($context);
    }
}
