<?php

use App\Plugins\SportsBot\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

use App\Plugins\SportsBot\Controllers\Admin\SportsBotController;

Route::prefix('sportsbot')->name('sportsbot.')->group(function () {
    Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');
    Route::get('/telegram/webhook/health', [TelegramWebhookController::class, 'health'])->name('telegram.webhook.health');
    Route::get('/fixture-queue/{id}/card', [SportsBotController::class, 'fixtureQueueCard'])->name('fixture-queue.card');
});
