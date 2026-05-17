<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The player's mutable game state, separated from the User identity model.
 *
 * This model is the single source of truth for all game stats:
 * economy (cash, bank), combat (strength, defense, speed), resources
 * (health, energy, nerve), progression (level, experience, rank), and
 * game state (status, jail_until, location).
 *
 * The User model provides transparent getter/setter shims so that all
 * existing code accessing $user->cash etc. continues to work without change.
 * New code should prefer $user->profile->cash for clarity.
 *
 * @property int         $user_id
 * @property int         $cash
 * @property int         $bank
 * @property int         $respect
 * @property int         $bullets
 * @property int         $points
 * @property int         $strength
 * @property int         $defense
 * @property int         $speed
 * @property int         $health
 * @property int         $max_health
 * @property int         $energy
 * @property int         $max_energy
 * @property int         $nerve
 * @property int         $max_nerve
 * @property int         $level
 * @property int         $experience
 * @property string      $rank
 * @property int|null    $rank_id
 * @property string      $location
 * @property int|null    $location_id
 * @property string      $status
 * @property \Carbon\Carbon|null $jail_until
 * @property \Carbon\Carbon|null $last_crime_at
 * @property \Carbon\Carbon|null $last_gta_at
 */
class PlayerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'cash', 'bank', 'respect', 'bullets', 'points',
        'strength', 'defense', 'speed',
        'health', 'max_health',
        'energy', 'max_energy',
        'nerve', 'max_nerve',
        'level', 'experience', 'rank', 'rank_id',
        'location', 'location_id',
        'status', 'jail_until',
        'last_crime_at', 'last_gta_at',
    ];

    protected function casts(): array
    {
        return [
            'cash'         => 'integer',
            'bank'         => 'integer',
            'respect'      => 'integer',
            'bullets'      => 'integer',
            'points'       => 'integer',
            'strength'     => 'integer',
            'defense'      => 'integer',
            'speed'        => 'integer',
            'health'       => 'integer',
            'max_health'   => 'integer',
            'energy'       => 'integer',
            'max_energy'   => 'integer',
            'nerve'        => 'integer',
            'max_nerve'    => 'integer',
            'level'        => 'integer',
            'experience'   => 'integer',
            'rank_id'      => 'integer',
            'location_id'  => 'integer',
            'jail_until'   => 'datetime',
            'last_crime_at'=> 'datetime',
            'last_gta_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Rank and Location relationships have been removed from the Core.
    // Plugins may provide these relations later. Accessing `$profile->currentRank`
    // or `$profile->currentLocation` will return the raw string value stored
    // on the profile (`rank` / `location`) until a plugin registers a relation.

    public function getCurrentRankAttribute()
    {
        return $this->attributes['rank'] ?? null;
    }

    public function getCurrentLocationAttribute()
    {
        return $this->attributes['location'] ?? null;
    }
}
