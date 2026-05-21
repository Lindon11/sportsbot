<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgGrabberOutput extends Model
{
    protected $table = 'sportsbot_epg_grabber_outputs';

    protected $fillable = [
        'grabber_id',
        'run_id',
        'region',
        'path',
        'source_url',
        'bytes',
        'content_hash',
        'generated_at',
        'metadata',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'generated_at' => 'datetime',
        'metadata' => 'array',
    ];
}
