<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgCorrection extends Model
{
    protected $table = 'sportsbot_epg_corrections';

    protected $fillable = [
        'fixture_queue_id',
        'event_id',
        'canonical_channel_id',
        'channel',
        'action',
        'notes',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
