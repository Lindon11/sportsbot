<?php

use App\Plugins\SportsBot\Controllers\Admin\SportsBotController;
use Illuminate\Support\Facades\Route;

Route::prefix('sportsbot')->name('sportsbot.')->group(function () {
    Route::get('/status', [SportsBotController::class, 'status'])->name('status');
    Route::post('/run', [SportsBotController::class, 'run'])->name('run');
    Route::post('/test-route', [SportsBotController::class, 'testRoute'])->name('test-route');
    Route::post('/fixtures-today/preview', [SportsBotController::class, 'fixturesTodayPreview'])->name('fixtures-today.preview');
    Route::post('/fixtures-today/send', [SportsBotController::class, 'fixturesTodaySend'])->name('fixtures-today.send');
    Route::post('/football-fixtures/preview', [SportsBotController::class, 'footballFixturesPreview'])->name('football-fixtures.preview');
    Route::post('/football-fixtures/send', [SportsBotController::class, 'footballFixturesSend'])->name('football-fixtures.send');
    Route::post('/rugby-fixtures/preview', [SportsBotController::class, 'rugbyFixturesPreview'])->name('rugby-fixtures.preview');
    Route::post('/rugby-fixtures/send', [SportsBotController::class, 'rugbyFixturesSend'])->name('rugby-fixtures.send');
    Route::post('/fight-fixtures/preview', [SportsBotController::class, 'fightFixturesPreview'])->name('fight-fixtures.preview');
    Route::post('/fight-fixtures/send', [SportsBotController::class, 'fightFixturesSend'])->name('fight-fixtures.send');
    Route::post('/tv-guide/preview', [SportsBotController::class, 'tvGuidePreview'])->name('tv-guide.preview');
    Route::post('/tv-guide/send', [SportsBotController::class, 'tvGuideSend'])->name('tv-guide.send');
    Route::post('/live-now/preview', [SportsBotController::class, 'liveNowPreview'])->name('live-now.preview');
    Route::post('/live-now/send', [SportsBotController::class, 'liveNowSend'])->name('live-now.send');
    Route::get('/coverage', [SportsBotController::class, 'coverageSettings'])->name('coverage');
    Route::post('/coverage', [SportsBotController::class, 'saveCoverageSettings'])->name('coverage.save');
    Route::post('/telegram/send-diagnostics', [SportsBotController::class, 'sendTelegramDiagnostics'])->name('telegram.send-diagnostics');
    Route::get('/telegram/messages', [SportsBotController::class, 'telegramMessages'])->name('telegram.messages');
    Route::get('/telegram/topics', [SportsBotController::class, 'telegramTopics'])->name('telegram.topics');
    Route::post('/telegram/topics', [SportsBotController::class, 'saveTelegramTopic'])->name('telegram.topics.save');
    Route::post('/telegram/topics/sync', [SportsBotController::class, 'syncTelegramTopics'])->name('telegram.topics.sync');
    Route::post('/telegram/topics/import-legacy', [SportsBotController::class, 'importLegacyTelegramTopics'])->name('telegram.topics.import-legacy');
    Route::get('/telegram/routes', [SportsBotController::class, 'telegramRoutesIndex'])->name('telegram.routes');
    Route::post('/telegram/routes', [SportsBotController::class, 'saveTelegramRoute'])->name('telegram.routes.save');
    Route::delete('/telegram/routes/{routeKey}', [SportsBotController::class, 'deleteTelegramRoute'])->name('telegram.routes.delete');
    Route::get('/telegram/webhook/diagnostics', [SportsBotController::class, 'telegramWebhookDiagnostics'])->name('telegram.webhook.diagnostics');
    Route::post('/telegram/webhook/set', [SportsBotController::class, 'setTelegramWebhook'])->name('telegram.webhook.set');
    Route::delete('/telegram/webhook', [SportsBotController::class, 'deleteTelegramWebhook'])->name('telegram.webhook.delete');
});
