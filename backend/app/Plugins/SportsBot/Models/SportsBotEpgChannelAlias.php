<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotEpgChannelAlias extends Model
{
    protected $table = 'sportsbot_epg_channel_aliases';

    protected $fillable = [
        'canonical_channel_id',
        'alias',
        'normalized_alias',
        'display_name',
        'region',
        'source',
        'confidence',
        'accepted',
    ];

    protected $casts = [
        'confidence' => 'float',
        'accepted' => 'boolean',
    ];
}
