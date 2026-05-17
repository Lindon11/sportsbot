<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data migration: copy game-stat columns from the users table into player_profiles.
 *
 * Uses DB::table() (not Eloquent) to avoid triggering getter/setter shims.
 * Runs in a transaction — if anything fails, the table stays empty and the
 * create_player_profiles_table migration can be rolled back cleanly.
 *
 * Only creates a profile row for users that don't already have one,
 * so this migration is safe to re-run after failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $existingIds = DB::table('player_profiles')->pluck('user_id')->flip();

            // Build a safe select list based on columns that actually exist
            $desired = [
                'id',
                'cash', 'bank', 'respect', 'bullets', 'points',
                'strength', 'defense', 'speed',
                'health', 'max_health',
                'energy', 'max_energy',
                'nerve', 'max_nerve',
                'level', 'experience', 'rank', 'rank_id',
                'location', 'location_id',
                'status', 'jail_until',
                'last_crime_at', 'last_gta_at',
                'created_at',
            ];

            $available = Schema::hasTable('users') ? Schema::getColumnListing('users') : [];
            $select = array_values(array_intersect($desired, $available));

            DB::table('users')
                ->select($select)
                ->orderBy('id')
                ->chunk(500, function ($users) use ($existingIds) {
                    $rows = [];
                    $now = now()->toDateTimeString();

                    foreach ($users as $user) {
                        if ($existingIds->has($user->id)) {
                            continue; // skip already-seeded users
                        }

                        $rows[] = [
                            'user_id'      => $user->id,
                            'cash'         => $user->cash         ?? 1000,
                            'bank'         => $user->bank         ?? 0,
                            'respect'      => $user->respect      ?? 0,
                            'bullets'      => $user->bullets      ?? 10,
                            'points'       => $user->points       ?? 0,
                            'strength'     => $user->strength     ?? 10,
                            'defense'      => $user->defense      ?? 10,
                            'speed'        => $user->speed        ?? 10,
                            'health'       => $user->health       ?? 100,
                            'max_health'   => $user->max_health   ?? 100,
                            'energy'       => $user->energy       ?? 100,
                            'max_energy'   => $user->max_energy   ?? 100,
                            'nerve'        => property_exists($user, 'nerve') ? ($user->nerve ?? 100) : 100,
                            'max_nerve'    => property_exists($user, 'max_nerve') ? ($user->max_nerve ?? 100) : 100,
                            'level'        => $user->level        ?? 1,
                            'experience'   => $user->experience   ?? 0,
                            'rank'         => $user->rank         ?? 'Thug',
                            'rank_id'      => $user->rank_id,
                            'location'     => $user->location     ?? 'Chicago',
                            'location_id'  => $user->location_id,
                            'status'       => $user->status       ?? 'alive',
                            'jail_until'   => $user->jail_until,
                            'last_crime_at'=> $user->last_crime_at,
                            'last_gta_at'  => $user->last_gta_at,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ];
                    }

                    if (!empty($rows)) {
                        DB::table('player_profiles')->insert($rows);
                    }
                });
        });
    }

    public function down(): void
    {
        // Rolling back removes all seeded profiles.
        // The create_player_profiles_table migration handles table removal.
        DB::table('player_profiles')->truncate();
    }
};
