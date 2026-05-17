<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\LiveNowFormatter;
use App\Plugins\SportsBot\Services\LiveNowService;
use App\Plugins\SportsBot\Services\SportsBotInlineKeyboardBuilder;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;

class LiveNowContentModule implements SportsBotContentModuleInterface
{
    public function __construct(
        private readonly LiveNowService $service = new LiveNowService(),
        private readonly LiveNowFormatter $formatter = new LiveNowFormatter(),
    ) {
    }

    public function key(): string
    {
        return 'LIVE_NOW';
    }

    public function label(): string
    {
        return 'Live Now';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::LIVE_NOW;
    }

    public function buildSummary(): array
    {
        return $this->service->buildSummary();
    }

    public function format(array $summary): string
    {
        return $this->formatter->format($summary);
    }

    public function telegramOptions(array $summary): array
    {
        return [
            'parse_mode' => '',
            'reply_markup' => SportsBotInlineKeyboardBuilder::backReplyMarkup(),
            'payload' => [
                'live_total' => (int) ($summary['live_total'] ?? 0),
                'live_raw' => (int) ($summary['live_raw'] ?? 0),
                'sports_grouped' => (array) ($summary['sports_grouped'] ?? []),
            ],
        ];
    }
}
