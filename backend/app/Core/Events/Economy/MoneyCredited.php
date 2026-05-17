<?php

namespace App\Core\Events\Economy;

use App\Core\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MoneyCredited
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly int $amount,
        public readonly int $newBalance,
        public readonly string $reason,
        public readonly string $pluginSlug = 'core'
    ) {}
}
