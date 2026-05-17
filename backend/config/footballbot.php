<?php

return [
    'enabled' => env('FOOTBALLBOT_ENABLED', true),

    'runtime_path' => env('FOOTBALLBOT_RUNTIME_PATH', base_path('footballbot')),

    'schedule' => [
        'enabled' => env('FOOTBALLBOT_SCHEDULE_ENABLED', true),
        'frequency' => env('FOOTBALLBOT_SCHEDULE_FREQUENCY', 'everyTwoMinutes'),
    ],
];
