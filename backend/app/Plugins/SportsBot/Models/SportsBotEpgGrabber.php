<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgGrabber extends Model
{
    protected $table = 'sportsbot_epg_grabbers';

    protected $fillable = [
        'name',
        'type',
        'region',
        'command',
        'arguments',
        'working_directory',
        'output_path',
        'enabled',
        'installed',
        'status',
        'last_run_at',
        'last_success_at',
        'last_failure_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'arguments' => 'array',
        'enabled' => 'boolean',
        'installed' => 'boolean',
        'last_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'metadata' => 'array',
    ];
}
