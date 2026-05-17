<?php

namespace App\Core\Contracts;

use App\Core\Models\User;

interface CombatInterface
{
    /**
     * Calculate the effective combat power for a user.
     * Factors in base stats and equipped items.
     */
    public function calculatePower(User $user): int;

    /**
     * Resolve combat between attacker and defender.
     * Returns result array with keys: winner, damage, loot, log_id.
     */
    public function resolveCombat(User $attacker, User $defender): array;
}
