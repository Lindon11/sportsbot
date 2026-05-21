<?php

use App\Plugins\SportsBot\Controllers\Admin\SportsBotController;
use App\Plugins\SportsBot\Controllers\Admin\UpdateController;
use Illuminate\Support\Facades\Route;

Route::prefix('sportsbot')->name('sportsbot.')->group(function () {
    Route::get('/status', [SportsBotController::class, 'status'])->name('status');
    Route::get('/autopilot', [SportsBotController::class, 'autopilotStatus'])->name('autopilot');
    Route::get('/post-timings', [SportsBotController::class, 'postTimings'])->name('post-timings');
    Route::post('/post-timings', [SportsBotController::class, 'savePostTimings'])->name('post-timings.save');
    Route::post('/run', [SportsBotController::class, 'run'])->name('run');
    Route::post('/test-route', [SportsBotController::class, 'testRoute'])->name('test-route');
    Route::get('/fixture-queue', [SportsBotController::class, 'fixtureQueue'])->name('fixture-queue');
    Route::post('/fixture-queue/prefetch', [SportsBotController::class, 'fixtureQueuePrefetch'])->name('fixture-queue.prefetch');
    Route::post('/fixture-queue/enrich', [SportsBotController::class, 'fixtureQueueEnrich'])->name('fixture-queue.enrich');
    Route::post('/fixture-queue/render', [SportsBotController::class, 'fixtureQueueRender'])->name('fixture-queue.render');
    Route::post('/fixture-queue/publish', [SportsBotController::class, 'fixtureQueuePublish'])->name('fixture-queue.publish');
    Route::post('/fixture-queue/bulk/re-render', [SportsBotController::class, 'fixtureQueueBulkReRender'])->name('fixture-queue.bulk.re-render');
    Route::post('/fixture-queue/bulk/republish', [SportsBotController::class, 'fixtureQueueBulkRepublish'])->name('fixture-queue.bulk.republish');
    Route::post('/fixture-queue/bulk/regenerate-assets', [SportsBotController::class, 'fixtureQueueRegenerateAssets'])->name('fixture-queue.bulk.regenerate-assets');
    Route::get('/fixture-queue/{id}', [SportsBotController::class, 'fixtureQueueItem'])->name('fixture-queue.item');
    Route::post('/fixture-queue/{id}/re-render', [SportsBotController::class, 'fixtureQueueReRender'])->name('fixture-queue.re-render');
    Route::post('/fixture-queue/{id}/publish', [SportsBotController::class, 'fixtureQueuePublishNow'])->name('fixture-queue.publish-now');
    Route::post('/fixture-queue/{id}/render-options', [SportsBotController::class, 'fixtureQueueRenderOptions'])->name('fixture-queue.render-options');
    Route::post('/fixture-queue/{id}/find-poster', [SportsBotController::class, 'fixtureQueueFindPoster'])->name('fixture-queue.find-poster');
    Route::post('/fixture-queue/{id}/find-tv-info', [SportsBotController::class, 'fixtureQueueFindTvInfo'])->name('fixture-queue.find-tv-info');
    Route::post('/fixture-queue/{id}/refresh-scraped-data', [SportsBotController::class, 'fixtureQueueRefreshScrapedData'])->name('fixture-queue.refresh-scraped-data');
    Route::post('/fixture-queue/{id}/accept-scraped-data', [SportsBotController::class, 'fixtureQueueAcceptScrapedData'])->name('fixture-queue.accept-scraped-data');
    Route::post('/fixture-queue/{id}/reject-scraped-data', [SportsBotController::class, 'fixtureQueueRejectScrapedData'])->name('fixture-queue.reject-scraped-data');
    Route::post('/fixture-queue/{id}/skip', [SportsBotController::class, 'fixtureQueueSkip'])->name('fixture-queue.skip');
    Route::delete('/fixture-queue/{id}', [SportsBotController::class, 'fixtureQueueDelete'])->name('fixture-queue.delete');
    Route::post('/football-fixtures/preview', [SportsBotController::class, 'footballFixturesPreview'])->name('football-fixtures.preview');
    Route::post('/football-fixtures/send', [SportsBotController::class, 'footballFixturesSend'])->name('football-fixtures.send');
    Route::post('/rugby-fixtures/preview', [SportsBotController::class, 'rugbyFixturesPreview'])->name('rugby-fixtures.preview');
    Route::post('/rugby-fixtures/send', [SportsBotController::class, 'rugbyFixturesSend'])->name('rugby-fixtures.send');
    Route::post('/fight-fixtures/preview', [SportsBotController::class, 'fightFixturesPreview'])->name('fight-fixtures.preview');
    Route::post('/fight-fixtures/send', [SportsBotController::class, 'fightFixturesSend'])->name('fight-fixtures.send');
    Route::post('/motorsport-fixtures/preview', [SportsBotController::class, 'motorsportFixturesPreview'])->name('motorsport-fixtures.preview');
    Route::post('/motorsport-fixtures/send', [SportsBotController::class, 'motorsportFixturesSend'])->name('motorsport-fixtures.send');
    Route::post('/fixtures/{sport}/preview', [SportsBotController::class, 'sportFixturePreview'])->name('fixtures.preview');
    Route::post('/fixtures/{sport}/send', [SportsBotController::class, 'sportFixtureSend'])->name('fixtures.send');
    Route::post('/fixtures/{sport}/publish', [SportsBotController::class, 'sportFixturePublish'])->name('fixtures.publish');
    Route::get('/highlights', [SportsBotController::class, 'highlightsPreview'])->name('highlights.preview');
    Route::post('/highlights/send', [SportsBotController::class, 'highlightsSend'])->name('highlights.send');
    Route::get('/leagues', [SportsBotController::class, 'allLeagues'])->name('leagues');
    Route::post('/leagues/lookup', [SportsBotController::class, 'lookupLeague'])->name('leagues.lookup');
    Route::get('/coverage', [SportsBotController::class, 'coverageSettings'])->name('coverage');
    Route::post('/coverage', [SportsBotController::class, 'saveCoverageSettings'])->name('coverage.save');
    Route::get('/scraper-settings', [SportsBotController::class, 'scraperSettings'])->name('scraper-settings');
    Route::post('/scraper-settings', [SportsBotController::class, 'saveScraperSettings'])->name('scraper-settings.save');
    Route::post('/telegram/send-diagnostics', [SportsBotController::class, 'sendTelegramDiagnostics'])->name('telegram.send-diagnostics');
    Route::post('/discord/send-diagnostics', [SportsBotController::class, 'sendDiscordDiagnostics'])->name('discord.send-diagnostics');
    Route::post('/discord/clear-channel', [SportsBotController::class, 'clearDiscordChannel'])->name('discord.clear-channel');
    Route::get('/discord/routes', [SportsBotController::class, 'discordRoutesIndex'])->name('discord.routes');
    Route::post('/discord/settings', [SportsBotController::class, 'saveDiscordSettings'])->name('discord.settings.save');
    Route::post('/discord/routes', [SportsBotController::class, 'saveDiscordRoute'])->name('discord.routes.save');
    Route::post('/discord/routes/test', [SportsBotController::class, 'testDiscordRoute'])->name('discord.routes.test');
    Route::delete('/discord/routes/{routeKey}', [SportsBotController::class, 'deleteDiscordRoute'])->name('discord.routes.delete');
    Route::get('/telegram/messages', [SportsBotController::class, 'telegramMessages'])->name('telegram.messages');
    Route::get('/telegram/topics', [SportsBotController::class, 'telegramTopics'])->name('telegram.topics');
    Route::post('/telegram/topics', [SportsBotController::class, 'saveTelegramTopic'])->name('telegram.topics.save');
    Route::post('/telegram/topics/sync', [SportsBotController::class, 'syncTelegramTopics'])->name('telegram.topics.sync');
    Route::post('/telegram/topics/import-legacy', [SportsBotController::class, 'importLegacyTelegramTopics'])->name('telegram.topics.import-legacy');
    Route::get('/telegram/routes', [SportsBotController::class, 'telegramRoutesIndex'])->name('telegram.routes');
    Route::post('/telegram/routes', [SportsBotController::class, 'saveTelegramRoute'])->name('telegram.routes.save');
    Route::delete('/telegram/routes/{routeKey}', [SportsBotController::class, 'deleteTelegramRoute'])->name('telegram.routes.delete');
    Route::get('/telegram/settings', [SportsBotController::class, 'telegramSettings'])->name('telegram.settings');
    Route::post('/telegram/settings', [SportsBotController::class, 'saveTelegramSettings'])->name('telegram.settings.save');
    Route::get('/telegram/webhook/diagnostics', [SportsBotController::class, 'telegramWebhookDiagnostics'])->name('telegram.webhook.diagnostics');
    Route::post('/telegram/webhook/set', [SportsBotController::class, 'setTelegramWebhook'])->name('telegram.webhook.set');
    Route::delete('/telegram/webhook', [SportsBotController::class, 'deleteTelegramWebhook'])->name('telegram.webhook.delete');

    // Uptime Monitor
    Route::get('/uptime', [SportsBotController::class, 'uptimeSites'])->name('uptime');
    Route::post('/uptime', [SportsBotController::class, 'uptimeSiteCreate'])->name('uptime.create');
    Route::put('/uptime/{id}', [SportsBotController::class, 'uptimeSiteUpdate'])->name('uptime.update');
    Route::delete('/uptime/{id}', [SportsBotController::class, 'uptimeSiteDelete'])->name('uptime.delete');
    Route::get('/uptime/{id}/logs', [SportsBotController::class, 'uptimeLogs'])->name('uptime.logs');

    // Update
    Route::get('/update/check', [UpdateController::class, 'check'])->name('update.check');
    Route::post('/update/run', [UpdateController::class, 'update'])->name('update.run');
    Route::post('/update/rebuild-admin-ui', [UpdateController::class, 'rebuildAdminUi'])->name('update.rebuild-admin-ui');
    Route::post('/update/force-sync', [UpdateController::class, 'forceSync'])->name('update.force-sync');
});
