<?php

namespace App\Core\Http\Kernel;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareGroups = [
        'web' => [
            // ...existing middleware...
        ],
        'api' => [
            // ...existing middleware...
        ],
        'installer' => [
            \App\Core\Http\Middleware\InstallerLocked::class,
        ],
    ];

    // ...existing code...
}
