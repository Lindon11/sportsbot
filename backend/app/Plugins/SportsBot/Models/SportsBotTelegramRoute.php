<?php

namespace App\Plugins\SportsBot\Models;

use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Database\Eloquent\Model;

class SportsBotTelegramRoute extends Model
{
    protected $table = 'sportsbot_telegram_routes';

    public $timestamps = false;

    protected $fillable = [
        'route_key',
        'label',
        'chat_id',
        'message_thread_id',
        'enabled',
        'fallback',
        'branding',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'enabled' => 'boolean',
        'fallback' => 'boolean',
        'branding' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $route): void {
            $route->route_key = TelegramRouteKeys::normalize((string) $route->route_key);
        });
    }
}
