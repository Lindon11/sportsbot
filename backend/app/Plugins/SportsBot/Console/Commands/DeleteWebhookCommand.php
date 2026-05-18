<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteWebhookCommand extends Command
{
    protected $signature = 'sportsbot:webhook:delete
        {--drop-pending-updates : Drop pending updates}';

    protected $description = 'Delete the Telegram webhook for SportsBot';

    public function handle(TelegramNotifier $notifier): int
    {
        if (!$notifier->configured()) {
            $this->error('Telegram bot is not configured. Check SPORTSBOT_TELEGRAM_BOT_TOKEN.');
            return self::FAILURE;
        }

        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        $dropPending = (bool) $this->option('drop-pending-updates');

        $this->info('Deleting Telegram webhook...');

        $payload = [
            'drop_pending_updates' => $dropPending,
        ];

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/deleteWebhook", $payload);

            $result = $response->json();

            if ($response->successful() && ($result['ok'] ?? false)) {
                $this->info('✓ Webhook deleted successfully!');
                $this->line('Description: ' . ($result['description'] ?? 'No description'));

                Log::info('sportsbot.telegram.webhook_deleted', [
                    'drop_pending_updates' => $dropPending,
                ]);

                return self::SUCCESS;
            }

            $this->error('Failed to delete webhook: ' . ($result['description'] ?? 'Unknown error'));
            Log::error('sportsbot.telegram.webhook_delete_failed', [
                'response' => $result,
            ]);

            return self::FAILURE;
        } catch (Throwable $error) {
            $this->error('Failed to delete webhook: ' . $error->getMessage());
            Log::error('sportsbot.telegram.webhook_delete_error', [
                'error' => $error->getMessage(),
            ]);

            return self::FAILURE;
        }
    }
}
