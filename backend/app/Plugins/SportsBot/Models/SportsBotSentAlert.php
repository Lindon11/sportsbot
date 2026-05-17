<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotSentAlert extends Model
{
    protected $fillable = [
        'alert_key',
        'event_id',
        'sport',
        'alert_type',
        'payload',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}
