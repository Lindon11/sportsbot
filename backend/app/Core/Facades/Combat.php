<?php

namespace App\Core\Facades;

use App\Core\Models\User;
use Illuminate\Support\Facades\Facade;

/**
 * Combat facade — the stable public API for combat resolution.
 * Plugin developers should use this facade to integrate with the combat system
 * rather than calling CombatService directly.
 *
 * @method static int   calculatePower(User $user)
 * @method static array resolveCombat(User $attacker, User $defender)
 *
 * @see \App\Plugins\Combat\Services\CombatService
 */
class Combat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'combat';
    }
}
