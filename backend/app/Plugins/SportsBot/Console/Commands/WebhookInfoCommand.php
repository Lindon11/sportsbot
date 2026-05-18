<?php

namespace App\Plugins\SportsBot\Console\Commands;

use App\Plugins\SportsBot\Models\SportsBotTelegramUpdateState;
use App\Plugins\SportsBot\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookInfoCommand extends Command
{
    protected $signature = 'sportsbot:webhook:info';

    protected $description = 'Show the current Telegram webhook status for SportsBot';

    public function handle(TelegramNotifier $notifier): int
    {
        if (!$notifier->configured()) {
            $this->error('Telegram bot is not configured. Check SPORTSBOT_TELEGRAM_BOT_TOKEN.');
            return self::FAILURE;
        }

        $token = trim((string) app(\App\Plugins\SportsBot\Services\SportsBotSettingsService::class)->resolveBotToken());
        $enabled = config('plugins.SportsBot.telegram.webhook_enabled', false);
        $webhookUrl = route('sportsbot.telegram.webhook', [], false);
        $fullUrl = url($webhookUrl);

        $this->info('SportsBot Telegram Webhook Information');
        $this->newLine();

        // Local configuration
        $this->line('<fg=bright-blue>■ Local Configuration</>');
        $this->line("  Webhook enabled:       " . ($enabled ? '<fg=green>✓ Yes</>' : '<fg=red>✗ No</>'));
        $this->line("  Webhook route:         {$fullUrl}");
        $this->line("  Secret token:          " . (config('plugins.SportsBot.telegram.webhook_secret') ? '<fg=green>✓ Configured</>' : '<fg=yellow>⚠ Not set</>'));
        $this->newLine();

        // Fetch from Telegram API
        try {
            $response = Http::timeout(10)
                ->get("https://api.telegram.org/bot{$token}/getWebhookInfo");

            $result = $response->json();

            if ($response->successful() && ($result['ok'] ?? false)) {
                $info = $result['result'] ?? [];
                $configuredUrl = $info['url'] ?? '';

                $this->line('<fg=bright-blue>■ Telegram API Remote Status</>');
                $this->line("  URL:                   " . ($configuredUrl ?: '<fg=yellow>Not set</>'));
                $this->line("  Has custom certificate: " . (($info['has_custom_certificate'] ?? false) ? '<fg=green>Yes</>' : 'No'));
                $this->line("  Pending update count:  " . ($info['pending_update_count'] ?? 0));
                $this->line("  Max connections:       " . ($info['max_connections'] ?? 'N/A'));
                $this->line("  IP address:            " . ($info['ip_address'] ?? 'N/A'));
                $this->line("  Last error date:       " . (isset($info['last_error_date']) ? date('Y-m-d H:i:s', $info['last_error_date']) : 'None'));
                $this->line("  Last error message:    " . ($info['last_error_message'] ?? 'None'));
                $this->line("  Last synchronized:     " . (isset($info['last_synchronization_error_date']) ? date('Y-m-d H:i:s', $info['last_synchronization_error_date']) : 'N/A'));

                $allowedUpdates = $info['allowed_updates'] ?? [];
                if (!empty($allowedUpdates)) {
                    $this->line("  Allowed updates:       " . implode(', ', $allowedUpdates));
                } else {
                    $this->line("  Allowed updates:       <fg=yellow>All</>");
                }

                $this->newLine();
            } else {
                $this->warn('  Could not fetch webhook info from Telegram API.');
                $this->line('  Response: ' . ($result['description'] ?? 'Unknown'));
                $this->newLine();
            }
        } catch (Throwable $error) {
            $this->error('  Failed to contact Telegram API: ' . $error->getMessage());
            $this->newLine();
        }

        // Recent updates from database
        $this->line('<fg=bright-blue>■ Recent Webhook Activity</>');
        try {
            $recentMessages = SportsBotTelegramUpdateState::query()
                ->where('type', 'message')
                ->latest()
                ->take(5)
                ->get();

            $recentCallbacks = SportsBotTelegramUpdateState::query()
                ->where('type', 'callback_query')
                ->latest()
                ->take(5)
                ->get();
        } catch (Throwable $error) {
            $this->warn('  Could not read webhook activity from the database.');
            $this->line('  Error: ' . $error->getMessage());

            return self::SUCCESS;
        }

        if ($recentMessages->isNotEmpty()) {
            $this->line("  Recent messages:");
            foreach ($recentMessages as $msg) {
                $this->line("    • #{$msg->id}: {$msg->created_at->diffForHumans()} (chat: {$msg->chat_id})");
            }
        } else {
            $this->line("  Recent messages:      <fg=yellow>None received yet</>");
        }

        if ($recentCallbacks->isNotEmpty()) {
            $this->line("  Recent callbacks:");
            foreach ($recentCallbacks as $cb) {
                $this->line("    • #{$cb->id}: {$cb->created_at->diffForHumans()} (data: {$cb->callback_data}, chat: {$cb->chat_id})");
            }
        } else {
            $this->line("  Recent callbacks:     <fg=yellow>None received yet</>");
        }

        return self::SUCCESS;
    }
}
