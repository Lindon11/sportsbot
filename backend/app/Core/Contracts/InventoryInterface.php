<?php

namespace App\Core\Contracts;

use App\Core\Models\User;

interface InventoryInterface
{
    /**
     * Give item(s) to a user. Returns the updated inventory entry.
     */
    public function give(User $user, int $itemId, int $quantity = 1): mixed;

    /**
     * Remove item(s) from a user's inventory.
     * Returns true on success, false if insufficient quantity.
     */
    public function take(User $user, int $itemId, int $quantity = 1): bool;

    /**
     * Check if a user has at least $quantity of the given item.
     */
    public function has(User $user, int $itemId, int $quantity = 1): bool;
}
