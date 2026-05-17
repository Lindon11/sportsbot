<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotMatchState extends Model
{
    protected $fillable = [
        'event_id',
        'live_score_id',
        'sport',
        'league_id',
        'league_name',
        'home_team_id',
        'away_team_id',
        'home_team',
        'away_team',
        'home_badge',
        'away_badge',
        'status',
        'progress',
        'home_score',
        'away_score',
        'raw_hash',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'home_score' => 'integer',
        'away_score' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
