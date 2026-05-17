<?php

namespace App\Core\Exceptions;

use App\Core\Models\User;
use RuntimeException;

class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly User $user,
        public readonly int $requested,
        public readonly int $available
    ) {
        parent::__construct(
            "User {$user->id} has insufficient funds: requested \${$requested}, available \${$available}."
        );
    }
}
