<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgFixtureMatch extends Model
{
    protected $table = 'sportsbot_epg_fixture_matches';

    protected $fillable = [
        'fixture_queue_id',
        'event_id',
        'programme_id',
        'canonical_channel_id',
        'channel',
        'confidence',
        'status',
        'evidence',
        'source_urls',
        'applied_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'confidence' => 'float',
        'evidence' => 'array',
        'source_urls' => 'array',
        'applied_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
