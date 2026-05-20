<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotHighlightSent extends Model
{
    protected $table = 'sportsbot_highlights_sent';

    protected $fillable = [
        'event_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public $timestamps = false;
}
