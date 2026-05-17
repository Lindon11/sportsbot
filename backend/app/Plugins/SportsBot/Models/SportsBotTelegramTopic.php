<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotTelegramTopic extends Model
{
    protected $table = 'sportsbot_telegram_topics';

    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'message_thread_id',
        'title',
        'source',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
