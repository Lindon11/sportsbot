<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\FixturesTodayFormatter;
use App\Plugins\SportsBot\Services\FixturesTodayService;
use App\Plugins\SportsBot\Services\SportsBotInlineKeyboardBuilder;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;

class FixturesTodayContentModule implements SportsBotContentModuleInterface
{
    public function __construct(
        private readonly FixturesTodayService $service = new FixturesTodayService(),
        private readonly FixturesTodayFormatter $formatter = new FixturesTodayFormatter(),
    ) {
    }

    public function key(): string
    {
        return 'FIXTURES_TODAY';
    }

    public function label(): string
    {
        return 'Fixtures Today';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::FIXTURES_TODAY;
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
            'reply_markup' => SportsBotInlineKeyboardBuilder::fixturesTodayReplyMarkup(),
            'payload' => [
                'fixtures_total' => (int) ($summary['fixtures_total'] ?? 0),
                'sports_grouped' => (array) ($summary['sports_grouped'] ?? []),
                'tv_channels_found' => (int) ($summary['tv_channels_found'] ?? 0),
            ],
        ];
    }
}
