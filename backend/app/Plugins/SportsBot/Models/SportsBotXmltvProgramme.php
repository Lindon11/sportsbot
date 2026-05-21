<?php

namespace App\Plugins\SportsBot\Models;

use Illuminate\Database\Eloquent\Model;

class SportsBotXmltvProgramme extends Model
{
    protected $table = 'sportsbot_xmltv_programmes';

    protected $fillable = [
        'channel',
        'title',
        'description',
        'start_time',
        'end_time',
        'fixture_id',
        'raw_data',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'raw_data' => 'array',
    ];
}
