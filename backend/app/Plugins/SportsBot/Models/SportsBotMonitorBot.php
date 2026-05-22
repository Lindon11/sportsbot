<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotMonitorBot extends Model
{
    protected $table = 'sportsbot_monitor_bots';

    protected $fillable = [
        'name',
        'owner_label',
        'telegram_token',
        'telegram_chat_id',
        'telegram_message_thread_id',
        'telegram_extra_targets',
        'enabled',
    ];

    protected $hidden = [
        'telegram_token',
    ];

    protected $casts = [
        'telegram_token' => 'encrypted',
        'telegram_message_thread_id' => 'integer',
        'enabled' => 'boolean',
    ];

    public function sites()
    {
        return $this->hasMany(SportsBotUptimeSite::class, 'monitor_bot_id');
    }

    public function tokenConfigured(): bool
    {
        return trim((string) $this->telegram_token) !== '';
    }
}
