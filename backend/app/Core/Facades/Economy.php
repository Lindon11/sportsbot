<?php

namespace App\Core\Facades;

use App\Core\Models\User;
use App\Core\Services\WalletService;
use Illuminate\Support\Facades\Facade;

/**
 * Economy facade — the stable public API for all cash operations.
 * Plugin developers should always use this facade rather than accessing the User model directly.
 *
 * @method static int  credit(User $user, int $amount, string $reason, string $pluginSlug = 'core')
 * @method static int  debit(User $user, int $amount, string $reason, string $pluginSlug = 'core')
 * @method static bool transfer(User $from, User $to, int $amount, string $reason, string $pluginSlug = 'core')
 * @method static int  getBalance(User $user)
 * @method static int  getBankBalance(User $user)
 *
 * @see WalletService
 */
class Economy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'economy';
    }
}
