<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\FixturesTodayFormatter;
use App\Plugins\SportsBot\Services\FixturesTodayService;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;

class MotorsportFixturesContentModule implements SportsBotContentModuleInterface
{
    private const SPORT_KEY = 'formula_1';

    public function __construct(
        private readonly FixturesTodayService $service = new FixturesTodayService(),
        private readonly FixturesTodayFormatter $formatter = new FixturesTodayFormatter(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    public function key(): string
    {
        return 'MOTORSPORT_FIXTURES';
    }

    public function label(): string
    {
        return 'Motorsport Fixtures TV';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::MOTORSPORT;
    }

    public function buildSummary(): array
    {
        return $this->service->buildSummary(self::SPORT_KEY);
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
                'captions_enabled' => (bool) $this->settings->get('formula_1_fixture_captions_enabled', false),
                'card_version' => $this->cardVersion(),
            ],
        ];
    }

    private function cardVersion(): string
    {
        $version = strtolower(trim((string) $this->settings->get('formula_1_fixture_card_version', SportsFixtureConfig::defaultCardVersion(self::SPORT_KEY))));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }
}
