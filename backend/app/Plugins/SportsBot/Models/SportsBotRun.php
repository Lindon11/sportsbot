<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotRun extends Model
{
    protected $fillable = [
        'mode',
        'dry_run',
        'status',
        'started_at',
        'finished_at',
        'summary',
        'error',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary' => 'array',
    ];
}
