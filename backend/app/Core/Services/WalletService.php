<?php

namespace App\Core\Services;

use App\Core\Contracts\EconomyInterface;
use App\Core\Exceptions\InsufficientFundsException;
use App\Core\Models\PlayerProfile;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService implements EconomyInterface
{
    public function credit(User $user, int $amount, string $reason, string $pluginSlug = 'core'): int
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount) {
            $profile = $this->lockedProfile($user);
            $profile->cash += $amount;
            $profile->save();

            return (int) $profile->cash;
        });
    }

    public function debit(User $user, int $amount, string $reason, string $pluginSlug = 'core'): int
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount) {
            $profile = $this->lockedProfile($user);

            if ($profile->cash < $amount) {
                throw new InsufficientFundsException($user, $amount, (int) $profile->cash);
            }

            $profile->cash -= $amount;
            $profile->save();

            return (int) $profile->cash;
        });
    }

    public function transfer(User $from, User $to, int $amount, string $reason, string $pluginSlug = 'core'): bool
    {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($from, $to, $amount) {
            $profiles = PlayerProfile::query()
                ->whereIn('user_id', [$from->id, $to->id])
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            $fromProfile = $profiles->get($from->id) ?? $this->lockedProfile($from);
            $toProfile = $profiles->get($to->id) ?? $this->lockedProfile($to);

            if ($fromProfile->cash < $amount) {
                throw new InsufficientFundsException($from, $amount, (int) $fromProfile->cash);
            }

            $fromProfile->cash -= $amount;
            $toProfile->cash += $amount;
            $fromProfile->save();
            $toProfile->save();

            return true;
        });
    }

    public function getBalance(User $user): int
    {
        return (int) ($this->profile($user)->cash ?? 0);
    }

    public function getBankBalance(User $user): int
    {
        return (int) ($this->profile($user)->bank ?? 0);
    }

    private function assertPositiveAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet amount must be greater than zero.');
        }
    }

    private function profile(User $user): PlayerProfile
    {
        return $user->profile()->firstOrCreate([]);
    }

    private function lockedProfile(User $user): PlayerProfile
    {
        return $user->profile()->lockForUpdate()->first() ?? $user->profile()->create([]);
    }
}
