<?php

namespace App\Console\Commands;

use App\Plugins\SportsBot\Services\TelegramNotifier;
use App\Plugins\SportsBot\Services\TelegramRoutingService;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Console\Command;

class SportsBotTestRouteCommand extends Command
{
    protected $signature = 'sportsbot:test-route
        {route_key=default : Route key to resolve, e.g. FIXTURES_TODAY}
        {--send : Send a test Telegram message to resolved targets}';

    protected $description = 'Resolve and optionally test-send a SportsBot Telegram route';

    public function handle(TelegramRoutingService $routingService, TelegramNotifier $notifier): int
    {
        $routeKey = TelegramRouteKeys::normalize((string) $this->argument('route_key'));
        $resolved = $routingService->resolveTargets($routeKey);

        $this->line('resolved route: ' . (string) ($resolved['resolved_route_key'] ?? TelegramRouteKeys::DEFAULT));
        $this->line('fallback: ' . ((bool) ($resolved['fallback'] ?? false) ? 'true' : 'false'));
        $this->line('target count: ' . (int) ($resolved['target_count'] ?? 0));

        foreach ((array) ($resolved['targets'] ?? []) as $target) {
            $chatId = (string) ($target['chat_id'] ?? '');
            $thread = $target['message_thread_id'] ?? null;
            $this->line($chatId . ':' . ($thread !== null ? (string) $thread : '-'));
        }

        if (!$this->option('send')) {
            return Command::SUCCESS;
        }

        $message = implode("\n", [
            'SportsBot route test',
            'Route: ' . $routeKey,
            'Resolved: ' . (string) ($resolved['resolved_route_key'] ?? TelegramRouteKeys::DEFAULT),
            'Time: ' . now()->toDateTimeString(),
        ]);

        try {
            $results = $notifier->send($message, [
                'route_key' => $routeKey,
                'type' => 'ROUTE_TEST',
                'payload' => [
                    'command' => 'sportsbot:test-route',
                ],
            ]);
        } catch (\Throwable $error) {
            $this->error('Send failed: ' . $error->getMessage());

            return Command::FAILURE;
        }

        $this->info('Sent test message(s): ' . count($results));

        return Command::SUCCESS;
    }
}
