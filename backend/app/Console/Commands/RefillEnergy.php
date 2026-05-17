<?php

namespace App\Console\Commands;

use App\Core\Models\PlayerProfile;
use Illuminate\Console\Command;

class RefillEnergy extends Command
{
    protected $signature = 'energy:refill';
    protected $description = 'Refill energy for all players based on game settings';

    public function handle()
    {
        $refillRate = setting('energy_refill_rate', 5);

        // Query player_profiles (energy columns moved from users in Phase 6)
        $profiles = PlayerProfile::whereColumn('energy', '<', 'max_energy')
            ->whereHas('user', fn ($q) => $q->whereNotNull('last_active'))
            ->get();

        $refilled = 0;

        foreach ($profiles as $profile) {
            $newEnergy = min($profile->energy + $refillRate, $profile->max_energy);

            if ($newEnergy > $profile->energy) {
                $profile->energy = $newEnergy;
                $profile->save();
                $refilled++;
            }
        }

        $this->info("Refilled energy for {$refilled} players (+{$refillRate} energy each)");

        return Command::SUCCESS;
    }
}
