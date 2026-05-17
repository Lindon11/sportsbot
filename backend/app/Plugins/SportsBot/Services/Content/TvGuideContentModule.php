<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\TvGuideFormatter;
use App\Plugins\SportsBot\Services\TvGuideService;
use App\Plugins\SportsBot\Services\SportsBotInlineKeyboardBuilder;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;

class TvGuideContentModule implements SportsBotContentModuleInterface
{
    public function __construct(
        private readonly TvGuideService $service = new TvGuideService(),
        private readonly TvGuideFormatter $formatter = new TvGuideFormatter(),
    ) {
    }

    public function key(): string
    {
        return 'TV_GUIDE';
    }

    public function label(): string
    {
        return 'TV Guide';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::TV_GUIDE;
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
            'reply_markup' => SportsBotInlineKeyboardBuilder::tvReplyMarkup(),
            'payload' => [
                'events_total' => (int) ($summary['events_total'] ?? 0),
                'channels_count' => count((array) ($summary['channels'] ?? [])),
                'sports_grouped' => (array) ($summary['sports_grouped'] ?? []),
                'hours_ahead' => (int) ($summary['hours_ahead'] ?? 24),
            ],
        ];
    }
}
