<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SetWebhookCommand extends Command
{
    protected $signature = 'sportsbot:webhook:set
        {--url= : The webhook URL to set}
        {--secret= : The webhook secret token}
        {--max-connections=40 : Maximum simultaneous connections}
        {--drop-pending-updates : Drop pending updates}';

    protected $description = 'Set the Telegram webhook URL for SportsBot';

    public function handle(TelegramNotifier $notifier): int
    {
        if (!$notifier->configured()) {
            $this->error('Telegram bot is not configured. Check SPORTSBOT_TELEGRAM_BOT_TOKEN.');
            return self::FAILURE;
        }

        $token = trim((string) config('plugins.SportsBot.telegram.bot_token', ''));
        $url = (string) ($this->option('url') ?: route('sportsbot.telegram.webhook', [], false));
        $secret = (string) ($this->option('secret') ?: config('plugins.SportsBot.telegram.webhook_secret', ''));
        $maxConnections = (int) $this->option('max-connections');
        $dropPending = (bool) $this->option('drop-pending-updates');

        // Resolve full URL
        if (!str_starts_with($url, 'http')) {
            $url = url($url);
        }

        $this->info("Setting Telegram webhook to: {$url}");
        $this->line("Max connections: {$maxConnections}");
        $this->line("Drop pending updates: " . ($dropPending ? 'yes' : 'no'));

        if ($secret !== '') {
            $this->line("Secret token: [configured]");
        }

        $payload = [
            'url' => $url,
            'max_connections' => $maxConnections,
            'drop_pending_updates' => $dropPending,
        ];

        if ($secret !== '') {
            $payload['secret_token'] = $secret;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/setWebhook", $payload);

            $result = $response->json();

            if ($response->successful() && ($result['ok'] ?? false)) {
                $this->info('✓ Webhook set successfully!');
                $this->line('Description: ' . ($result['description'] ?? 'No description'));
                $this->line('Result: ' . json_encode($result['result'] ?? []));

                Log::info('sportsbot.telegram.webhook_set', [
                    'url' => $url,
                    'max_connections' => $maxConnections,
                ]);

                return self::SUCCESS;
            }

            $this->error('Failed to set webhook: ' . ($result['description'] ?? 'Unknown error'));
            Log::error('sportsbot.telegram.webhook_set_failed', [
                'response' => $result,
            ]);

            return self::FAILURE;
        } catch (Throwable $error) {
            $this->error('Failed to set webhook: ' . $error->getMessage());
            Log::error('sportsbot.telegram.webhook_set_error', [
                'error' => $error->getMessage(),
            ]);

            return self::FAILURE;
        }
    }
}
