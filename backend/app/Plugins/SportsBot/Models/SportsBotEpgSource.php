<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgSource extends Model
{
    protected $table = 'sportsbot_epg_sources';

    protected $fillable = [
        'name',
        'url',
        'type',
        'region',
        'priority',
        'enabled',
        'status',
        'stale',
        'programme_count',
        'channel_count',
        'match_count',
        'average_confidence',
        'last_checked_at',
        'last_success_at',
        'last_failure_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'stale' => 'boolean',
        'metadata' => 'array',
        'last_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'average_confidence' => 'float',
    ];
}
