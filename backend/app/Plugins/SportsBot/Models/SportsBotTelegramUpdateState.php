<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotTelegramUpdateState extends Model
{
    protected $table = 'sportsbot_telegram_update_states';

    protected $fillable = [
        'update_id',
        'type',
        'chat_id',
        'message_thread_id',
        'callback_data',
        'callback_query_id',
        'telegram_message_id',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
