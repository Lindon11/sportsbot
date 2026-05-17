<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\FixturesTodayFormatter;
use App\Plugins\SportsBot\Services\FixturesTodayService;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;

class FootballFixturesContentModule implements SportsBotContentModuleInterface
{
    public function __construct(
        private readonly FixturesTodayService $service = new FixturesTodayService(),
        private readonly FixturesTodayFormatter $formatter = new FixturesTodayFormatter(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    public function key(): string
    {
        return 'FOOTBALL_FIXTURES';
    }

    public function label(): string
    {
        return 'Football Fixtures TV';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::FOOTBALL;
    }

    public function buildSummary(): array
    {
        return $this->service->buildSummary('football');
    }

    public function format(array $summary): string
    {
        return $this->formatter->format($summary);
    }

    public function telegramOptions(array $summary): array
    {
        return [
            'parse_mode' => '',
            'payload' => [
                'fixtures_total' => (int) ($summary['fixtures_total'] ?? 0),
                'sports_grouped' => (array) ($summary['sports_grouped'] ?? []),
                'tv_channels_found' => (int) ($summary['tv_channels_found'] ?? 0),
                'captions_enabled' => (bool) $this->settings->get('football_fixture_captions_enabled', false),
                'card_version' => $this->cardVersion(),
            ],
        ];
    }

    private function cardVersion(): string
    {
        $version = (string) $this->settings->get('football_fixture_card_version', 'v1');

        return strtolower(trim($version)) === 'v2' ? 'v2' : 'v1';
    }
}
