<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotDelivery extends Model
{
    protected $table = 'sportsbot_deliveries';

    protected $fillable = [
        'platform',
        'route_key',
        'type',
        'status',
        'target',
        'message_id',
        'error',
        'payload',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];
}
