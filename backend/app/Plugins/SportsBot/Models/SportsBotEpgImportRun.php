<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgImportRun extends Model
{
    protected $table = 'sportsbot_epg_import_runs';

    protected $fillable = [
        'source_id',
        'source_url',
        'status',
        'programme_count',
        'channel_count',
        'matched_fixture_count',
        'duration_ms',
        'error',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];
}
