<?php

namespace App\Core\Facades;

use App\Core\Models\User;
use Illuminate\Support\Facades\Facade;

/**
 * Inventory facade — the stable public API for item management.
 * Plugin developers should use this facade to give, take, or check items
 * rather than manipulating UserInventory records directly.
 *
 * @method static mixed give(User $user, int $itemId, int $quantity = 1)
 * @method static bool  take(User $user, int $itemId, int $quantity = 1)
 * @method static bool  has(User $user, int $itemId, int $quantity = 1)
 *
 * @see \App\Plugins\Inventory\Services\InventoryService
 */
class Inventory extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'inventory';
    }
}
