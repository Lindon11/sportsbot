<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgGrabberRun extends Model
{
    protected $table = 'sportsbot_epg_grabber_runs';

    protected $fillable = [
        'grabber_id',
        'type',
        'region',
        'status',
        'duration_ms',
        'output_bytes',
        'output_path',
        'error',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'output_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];
}
