<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotTelegramFollow extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'telegram_username',
        'chat_id',
        'followable_type',
        'followable_id',
        'name',
        'sport',
        'alerts',
        'enabled',
    ];

    protected $casts = [
        'alerts' => 'array',
        'enabled' => 'boolean',
    ];
}
