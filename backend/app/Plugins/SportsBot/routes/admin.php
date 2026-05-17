<?php

use App\Plugins\SportsBot\Controllers\Admin\SportsBotController;
use Illuminate\Support\Facades\Route;

Route::prefix('sportsbot')->name('sportsbot.')->group(function () {
    Route::get('/status', [SportsBotController::class, 'status'])->name('status');
    Route::post('/run', [SportsBotController::class, 'run'])->name('run');
});
