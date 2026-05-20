<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotPipelineRun extends Model
{
    protected $table = 'sportsbot_pipeline_runs';

    protected $fillable = [
        'stage',
        'status',
        'options',
        'counts',
        'error_summary',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'options' => 'array',
        'counts' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];
}
