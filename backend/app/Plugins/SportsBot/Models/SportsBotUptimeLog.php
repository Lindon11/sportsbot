<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotUptimeLog extends Model
{
    protected $table = 'sportsbot_uptime_logs';

    protected $fillable = [
        'site_id',
        'status_code',
        'response_time_ms',
        'error',
        'status',
        'checked_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public $timestamps = false;
}
