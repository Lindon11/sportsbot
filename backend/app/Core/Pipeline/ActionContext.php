<?php

namespace App\Core\Pipeline;

use App\Core\Models\User;

/**
 * Value object passed through the ActionPipeline.
 * Holds the player, action name, input payload, and accumulated result/errors.
 */
class ActionContext
{
    /** Names of hooks fired during this action (for debugging/logging). */
    public array $hooksFired = [];

    /** Result set by the Execute pipe after the core action runs. */
    public mixed $result = null;

    /** Errors collected by validation or execution pipes. */
    public array $errors = [];

    /**
     * @param User   $player     The player performing the action
     * @param string $action     Dot-notation action name, e.g. 'crime.commit', 'combat.attack'
     * @param array  $payload    Input parameters for the action (mutable — before-hooks may modify it)
     * @param string $pluginSlug Slug of the plugin that owns this action
     */
    public function __construct(
        public readonly User $player,
        public readonly string $action,
        public array $payload = [],
        public readonly string $pluginSlug = 'core'
    ) {}

    public function addHookFired(string $hook): void
    {
        $this->hooksFired[] = $hook;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function isSuccessful(): bool
    {
        return empty($this->errors);
    }
}
