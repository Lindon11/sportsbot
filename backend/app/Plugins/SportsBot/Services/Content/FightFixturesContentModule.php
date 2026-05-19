<?php

namespace App\Plugins\SportsBot\Services\Content;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Services\FixturesTodayFormatter;
use App\Plugins\SportsBot\Services\FixturesTodayService;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;

class FightFixturesContentModule implements SportsBotContentModuleInterface
{
    public function __construct(
        private readonly FixturesTodayService $service = new FixturesTodayService(),
        private readonly FixturesTodayFormatter $formatter = new FixturesTodayFormatter(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
    ) {
    }

    public function key(): string
    {
        return 'FIGHT_FIXTURES';
    }

    public function label(): string
    {
        return 'Fights TV';
    }

    public function routeKey(): string
    {
        return TelegramRouteKeys::COMBAT_OTHER;
    }

    public function buildSummary(): array
    {
        return $this->service->buildSummary('fights', (int) config('plugins.SportsBot.fixtures_today.fight_lookahead_days', 30));
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
                'captions_enabled' => (bool) $this->settings->get('fight_fixture_captions_enabled', true),
                'card_version' => $this->cardVersion(),
            ],
        ];
    }

    private function cardVersion(): string
    {
        $version = strtolower(trim((string) $this->settings->get('fight_fixture_card_version', 'v3')));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }
}
