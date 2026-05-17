<?php

/**
 * TestPlugin API Routes
 *
 * Plugin API endpoint definitions.
 * These routes are automatically prefixed with /api/{plugin-slug}
 */

use Illuminate\Support\Facades\Route;
use App\Plugins\TestPlugin\Controllers\Api\TestPluginController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Get plugin status/info
Route::get('/', [TestPluginController::class, 'index'])->name('testplugin.index');

// Example resource endpoints
// Route::get('/data', [TestPluginController::class, 'getData'])->name('testplugin.data');
// Route::post('/action', [TestPluginController::class, 'doAction'])->name('testplugin.action');