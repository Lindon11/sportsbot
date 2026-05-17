<?php

namespace App\Core\Events\Economy;

use App\Core\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MoneyTransferred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $from,
        public readonly User $to,
        public readonly int $amount,
        public readonly string $reason,
        public readonly string $pluginSlug = 'core'
    ) {}
}
