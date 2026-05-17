<?php

namespace App\Core\Contracts;

use App\Core\Models\User;

interface EconomyInterface
{
    /**
     * Credit cash to a user's wallet.
     * Returns the user's new cash balance.
     */
    public function credit(User $user, int $amount, string $reason, string $pluginSlug = 'core'): int;

    /**
     * Debit cash from a user's wallet.
     * Throws InsufficientFundsException if balance is too low.
     * Returns the user's new cash balance.
     */
    public function debit(User $user, int $amount, string $reason, string $pluginSlug = 'core'): int;

    /**
     * Atomic transfer between two users (deadlock-safe).
     * Returns true on success.
     */
    public function transfer(User $from, User $to, int $amount, string $reason, string $pluginSlug = 'core'): bool;

    /**
     * Get the user's current cash balance.
     */
    public function getBalance(User $user): int;

    /**
     * Get the user's current bank balance.
     */
    public function getBankBalance(User $user): int;
}
