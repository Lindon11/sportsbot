<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class SportsBotPublisher
{
    public function __construct(
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
        private readonly TelegramNotifier $notifier = new TelegramNotifier(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(SportsBotContentModuleInterface $module): array
    {
        $summary = $module->buildSummary();
        $message = $module->format($summary);
        $routeStatus = $this->routingService->resolveTargets($module->routeKey());

        Log::info('sportsbot.publisher.preview', [
            'content_key' => $module->key(),
            'route_key' => $module->routeKey(),
            'target_count' => (int) ($routeStatus['target_count'] ?? 0),
            'fallback' => (bool) ($routeStatus['fallback'] ?? false),
        ]);

        return [
            'content_key' => $module->key(),
            'label' => $module->label(),
            'route_key' => $module->routeKey(),
            'route_status' => $routeStatus,
            'summary' => $summary,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function send(SportsBotContentModuleInterface $module, string $source = 'publisher'): array
    {
        $preview = $this->preview($module);
        $summary = (array) ($preview['summary'] ?? []);
        $message = (string) ($preview['message'] ?? '');
        $moduleOptions = $module->telegramOptions($summary);
        $options = array_merge($moduleOptions, [
            'route_key' => $module->routeKey(),
            'type' => $module->key(),
            'payload' => array_merge((array) ($moduleOptions['payload'] ?? []), [
                'source' => $source,
                'content_key' => $module->key(),
            ]),
        ]);

        try {
            $results = $this->notifier->send($message, $options);
        } catch (Throwable $error) {
            Log::error('sportsbot.publisher.send_failed', [
                'content_key' => $module->key(),
                'route_key' => $module->routeKey(),
                'error' => $error->getMessage(),
            ]);

            throw $error;
        }

        Log::info('sportsbot.publisher.sent', [
            'content_key' => $module->key(),
            'route_key' => $module->routeKey(),
            'result_count' => count($results),
            'target_count' => (int) (($preview['route_status']['target_count'] ?? 0)),
        ]);

        return array_merge($preview, [
            'sent' => true,
            'results' => $results,
        ]);
    }
}
