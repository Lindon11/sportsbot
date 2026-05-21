<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotUptimeSite extends Model
{
    protected $table = 'sportsbot_uptime_sites';

    protected $fillable = [
        'name',
        'url',
        'expected_keyword',
        'check_interval_seconds',
        'timeout_seconds',
        'failure_threshold',
        'alert_route_key',
        'alerts_enabled',
        'enabled',
        'status',
        'uptime_percentage',
        'last_checked_at',
        'last_online_at',
        'last_offline_at',
        'consecutive_failures',
        'total_checks',
        'total_failures',
    ];

    protected $casts = [
        'check_interval_seconds' => 'integer',
        'timeout_seconds' => 'integer',
        'failure_threshold' => 'integer',
        'alerts_enabled' => 'boolean',
        'enabled' => 'boolean',
        'uptime_percentage' => 'integer',
        'consecutive_failures' => 'integer',
        'total_checks' => 'integer',
        'total_failures' => 'integer',
        'last_checked_at' => 'datetime',
        'last_online_at' => 'datetime',
        'last_offline_at' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(SportsBotUptimeLog::class, 'site_id');
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
