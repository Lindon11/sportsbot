<?php

namespace App\Plugins\SportsBot\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SportsBotFixtureQueue extends Model
{
    protected $table = 'sportsbot_fixture_queue';

    protected $fillable = [
        'event_id',
        'sport_key',
        'publish_date',
        'status',
        'card_path',
        'renderer_used',
        'render_duration_ms',
        'template_used',
        'theme_used',
        'fallback_reason',
        'browser_failure_reason',
        'asset_failures',
        'render_diagnostics',
        'caption',
        'route_key',
        'topic_id',
        'telegram_message_id',
        'asset_status',
        'payload_hash',
        'fixture_data',
        'payload',
        'error',
        'last_refreshed_at',
        'sent_at',
    ];

    protected $casts = [
        'publish_date' => 'date:Y-m-d',
        'fixture_data' => 'array',
        'payload' => 'array',
        'asset_failures' => 'array',
        'render_diagnostics' => 'array',
        'last_refreshed_at' => 'datetime',
        'sent_at' => 'datetime',
        'telegram_message_id' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const ASSET_PENDING = 'pending';
    public const ASSET_CACHED = 'cached';
    public const ASSET_FAILED = 'failed';

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeBySport($query, string $sportKey)
    {
        return $query->where('sport_key', $sportKey);
    }

    public function scopePublishable($query, string $date = null)
    {
        $date ??= Carbon::today()->toDateString();

        return $query->where('publish_date', $date)->ready();
    }

    public function scopeWithinWindow($query, string $from, string $to)
    {
        return $query->whereBetween('publish_date', [$from, $to]);
    }
}
