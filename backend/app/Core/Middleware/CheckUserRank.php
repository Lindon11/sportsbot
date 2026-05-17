<?php

namespace App\Core\Middleware;

/**
 * Backward-compatibility shim.
 * Real implementation lives in App\Plugins\Progression\Middleware\CheckUserRank.
 * This file is kept so any reference to the Core namespace continues to resolve.
 */
class CheckUserRank extends \App\Plugins\Progression\Middleware\CheckUserRank {}
