<?php

return [
    'enabled' => env('FOOTBALLBOT_ENABLED', false),

    'runtime_path' => env('FOOTBALLBOT_RUNTIME_PATH', base_path('footballbot')),

    'schedule' => [
        'enabled' => env('FOOTBALLBOT_SCHEDULE_ENABLED', false),
        'frequency' => env('FOOTBALLBOT_SCHEDULE_FREQUENCY', 'everyTwoMinutes'),
    ],
];
