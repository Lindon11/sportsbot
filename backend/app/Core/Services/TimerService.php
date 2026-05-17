<?php

namespace App\Core\Services;

use App\Core\Models\User;
use App\Core\Models\UserTimer;
use InvalidArgumentException;

class TimerService
{
    public function setTimer(User $user, string $timerName, int $seconds, array $metadata = []): UserTimer
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timer duration must be greater than zero.');
        }

        return $user->setTimer($timerName, $seconds, $metadata);
    }

    public function hasActiveTimer(User $user, string $timerName): bool
    {
        return $user->hasTimer($timerName);
    }

    public function getRemainingSeconds(User $user, string $timerName): int
    {
        $timer = $user->getTimer($timerName);

        if (!$timer) {
            return 0;
        }

        return max(0, now()->diffInSeconds($timer->expires_at, false));
    }

    public function getTimer(User $user, string $timerName): ?UserTimer
    {
        return $user->getTimer($timerName);
    }

    public function clearTimer(User $user, string $timerName): void
    {
        $user->clearTimer($timerName);
    }
}
