<?php

namespace App\Plugins\SportsBot\Models;

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Database\Eloquent\Model;

class SportsBotTelegramMessage extends Model
{
    protected $table = 'sportsbot_telegram_messages';

    public $timestamps = false;

    protected $fillable = [
        'route_key',
        'chat_id',
        'message_thread_id',
        'telegram_message_id',
        'type',
        'status',
        'payload',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'telegram_message_id' => 'integer',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $message): void {
            $message->route_key = TelegramRouteKeys::normalize((string) $message->route_key);
        });
    }
}
