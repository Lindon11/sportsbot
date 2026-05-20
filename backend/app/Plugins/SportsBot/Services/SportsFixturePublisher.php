<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SportsFixturePublisher
{
    public function __construct(
        private readonly FixturesTodayService $fixturesService = new FixturesTodayService(),
        private readonly FixturesTodayFormatter $formatter = new FixturesTodayFormatter(),
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
        private readonly SportsBotCardRenderer $cards = new SportsBotCardRenderer(),
        private readonly SportsBotNotifier $notifier = new SportsBotNotifier(),
    ) {
    }

    public function preview(string $sportKey, array $options = []): array
    {
        $config = SportsFixtureConfig::for($sportKey);
        if ($config === null) {
            return ['error' => "Unknown sport: {$sportKey}"];
        }

        $summary = $this->buildSummary($sportKey, $config, $options);
        $message = $this->formatter->format($summary);
        $routeStatus = $this->routingService->resolveTargets($config['topic_key']);

        return [
            'sport' => $sportKey,
            'config' => $config,
            'route_key' => $config['topic_key'],
            'route_status' => $routeStatus,
            'summary' => $summary,
            'message' => $message,
        ];
    }

    public function send(string $sportKey, string $source = 'publisher', array $options = []): array
    {
        $preview = $this->preview($sportKey, $options);

        if (isset($preview['error'])) {
            return $preview;
        }

        $config = SportsFixtureConfig::for($sportKey);
        $summary = (array) ($preview['summary'] ?? []);
        $message = (string) ($preview['message'] ?? '');
        $fixtureCount = count($this->flattenFixtures($summary));

        try {
            $results = $this->sendCards($sportKey, $config, $summary, $message, $source, $options);
        } catch (Throwable $error) {
            Log::error('sportsbot.fixture_publisher.send_failed', [
                'sport' => $sportKey,
                'route_key' => $config['topic_key'] ?? null,
                'error' => $error->getMessage(),
            ]);

            throw $error;
        }

        Log::info('sportsbot.fixture_publisher.sent', [
            'sport' => $sportKey,
            'route_key' => $config['topic_key'] ?? null,
            'fixture_count' => $fixtureCount,
            'result_count' => count($results),
        ]);

        return array_merge($preview, [
            'sent' => $results !== [],
            'no_fixtures' => $fixtureCount === 0,
            'card_only' => true,
            'results' => $results,
        ]);
    }

    private function sendCards(string $sportKey, array $config, array $summary, string $message, string $source, array $options): array
    {
        $fixtures = $this->flattenFixtures($summary);
        $cardVersion = $this->resolveCardVersion($config);

        if ($fixtures === []) {
            if (!$this->cardsEnabled()) {
                throw new RuntimeException('Fixture cards are disabled; text fallback is not allowed for sport fixture sends.');
            }

            $card = $this->cards->noFixturesCard($this->noFixturesSummary($summary, $config), $cardVersion);

            return $this->notifier->sendPhoto((string) $card['path'], '', $this->buildRouteOptions($config, $source, $summary));
        }

        if (!$this->cardsEnabled()) {
            throw new RuntimeException('Fixture cards are disabled; text fallback is not allowed for sport fixture sends.');
        }

        $routeKey = TelegramRouteKeys::normalize((string) ($config['topic_key'] ?? ''));
        $brandGroups = $this->resolveBrandGroups($routeKey);

        $captionsEnabled = $this->captionsEnabled($config);
        $results = [];

        $sendFixtures = function (array $groupTargets, array $branding) use ($fixtures, $cardVersion, $captionsEnabled, $config, $source, $summary, $sportKey, &$results): void {
            $hasCustomBranding = $branding !== [];

            foreach ($fixtures as $fixture) {
                try {
                    $renderOptions = $hasCustomBranding ? ['branding' => $branding] : [];
                    $card = $this->cards->fixtureCard($fixture, $cardVersion, $renderOptions);
                    $caption = $captionsEnabled ? $this->buildCaption($fixture, $config) : '';
                    $fixtureOptions = $this->buildFixtureOptions($fixture, $config, $source, $cardVersion, $summary);

                    if ($hasCustomBranding && $groupTargets !== []) {
                        foreach ($this->notifier->sendPhotoToTargets((string) $card['path'], $caption, $fixtureOptions, $groupTargets) as $result) {
                            $results[] = $result;
                        }
                    } else {
                        foreach ($this->notifier->sendPhoto((string) $card['path'], $caption, $fixtureOptions) as $result) {
                            $results[] = $result;
                        }
                    }
                } catch (Throwable $error) {
                    Log::warning('sportsbot.fixture_publisher.card_send_failed', [
                        'sport' => $sportKey,
                        'event_id' => (string) ($fixture['event_id'] ?? ''),
                        'error' => $error->getMessage(),
                    ]);
                }
            }
        };

        if ($brandGroups === []) {
            $sendFixtures([], []);
        } else {
            foreach ($brandGroups as $group) {
                $sendFixtures($group['targets'], $group['branding']);
            }
        }

        if ($results === []) {
            throw new RuntimeException('No fixture cards were sent. Check card rendering and Telegram route configuration.');
        }

        return $results;
    }

    /**
     * @return array<int, array{branding:array<string,mixed>,targets:array<int,array{chat_id:string,message_thread_id:int|null}>}>
     */
    private function resolveBrandGroups(string $routeKey): array
    {
        $resolved = $this->routingService->resolveTargets($routeKey);
        $targets = (array) ($resolved['targets'] ?? []);

        if ($targets === []) {
            return [];
        }

        $groups = [];

        foreach ($targets as $target) {
            $branding = (array) ($target['branding'] ?? []);
            $hash = md5(serialize($branding));

            if (!isset($groups[$hash])) {
                $groups[$hash] = [
                    'branding' => $branding,
                    'targets' => [],
                ];
            }

            $groups[$hash]['targets'][] = [
                'chat_id' => $target['chat_id'],
                'message_thread_id' => $target['message_thread_id'] ?? null,
            ];
        }

        $groups = array_values($groups);

        if (count($groups) === 1 && $groups[0]['branding'] === []) {
            return [];
        }

        return $groups;
    }

    private function noFixturesSummary(array $summary, array $config): array
    {
        $sportKey = (string) ($config['sport'] ?? $summary['sport_filter'] ?? 'sports');
        $label = trim((string) ($summary['title'] ?? ''));

        return [
            'sport' => $sportKey,
            'sport_key' => $sportKey,
            'sport_label' => $label !== '' ? $label : SportsFixtureConfig::emoji($sportKey) . ' ' . SportsFixtureConfig::for($sportKey)['sport'],
            'title' => $label !== '' ? $label : ucwords(str_replace('_', ' ', $sportKey)) . ' Fixtures TV',
            'date' => (string) ($summary['date'] ?? now()->toDateString()),
            'route_key' => (string) ($config['topic_key'] ?? ''),
            'fixtures_total' => 0,
        ];
    }

    private function buildSummary(string $sportKey, ?array $config, array $options = []): array
    {
        $lookahead = $options['lookahead_days'] ?? ($config['lookahead_days'] ?? 0);

        return $this->fixturesService->buildSummary($sportKey, (int) $lookahead);
    }

    private function buildRouteOptions(array $config, string $source, array $summary): array
    {
        return [
            'parse_mode' => '',
            'route_key' => $config['topic_key'],
            'type' => strtoupper((string) $config['sport']) . '_FIXTURES',
            'payload' => [
                'source' => $source,
                'content_key' => strtoupper((string) $config['sport']) . '_FIXTURES',
                'fixtures_total' => (int) ($summary['fixtures_total'] ?? 0),
                'sports_grouped' => (array) ($summary['sports_grouped'] ?? []),
                'tv_channels_found' => (int) ($summary['tv_channels_found'] ?? 0),
            ],
        ];
    }

    private function buildFixtureOptions(array $fixture, array $config, string $source, string $cardVersion, array $summary): array
    {
        return [
            'route_key' => $config['topic_key'],
            'type' => strtoupper((string) $config['sport']) . '_FIXTURES',
            'payload' => [
                'source' => $source,
                'content_key' => strtoupper((string) $config['sport']) . '_FIXTURES',
                'card_version' => $cardVersion,
                'captions_enabled' => $this->captionsEnabled($config),
                'fixtures_total' => (int) ($summary['fixtures_total'] ?? 0),
                'event_id' => (string) ($fixture['event_id'] ?? ''),
                'fixture' => [
                    'time' => (string) ($fixture['time'] ?? ''),
                    'league' => (string) ($fixture['league'] ?? ''),
                    'home_team' => (string) ($fixture['home_team'] ?? ''),
                    'away_team' => (string) ($fixture['away_team'] ?? ''),
                    'tv_channel' => (string) ($fixture['tv_channel'] ?? ''),
                ],
            ],
        ];
    }

    private function cardsEnabled(): bool
    {
        return (bool) $this->settings->get('cards_enabled', config('plugins.SportsBot.cards.enabled', true))
            && (bool) $this->settings->get('rich_cards_enabled', config('plugins.SportsBot.features.rich_cards', true));
    }

    private function resolveCardVersion(array $config): string
    {
        $sportKey = (string) ($config['sport'] ?? '');
        $settingKey = $sportKey . '_fixture_card_version';
        $version = (string) $this->settings->get($settingKey, $config['default_card_version'] ?? 'v3');
        $version = strtolower(trim($version));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }

    private function captionsEnabled(array $config): bool
    {
        $sportKey = (string) ($config['sport'] ?? '');
        $settingKey = $sportKey . '_fixture_captions_enabled';

        if ($this->settings->has($settingKey)) {
            return (bool) $this->settings->get($settingKey, $config['captions_enabled_default'] ?? false);
        }

        return (bool) ($config['captions_enabled_default'] ?? false);
    }

    private function flattenFixtures(array $summary): array
    {
        $fixtures = [];

        foreach ((array) ($summary['grouped'] ?? []) as $rows) {
            foreach ((array) $rows as $fixture) {
                if (is_array($fixture)) {
                    $fixtures[] = $fixture;
                }
            }
        }

        return $fixtures;
    }

    private function buildCaption(array $fixture, array $config): string
    {
        $formatter = (string) ($config['caption_formatter'] ?? 'generic');

        return match ($formatter) {
            'combat' => $this->combatCaption($fixture),
            'rugby' => $this->genericChannelCaption($fixture),
            'football' => $this->genericChannelCaption($fixture),
            default => $this->genericChannelCaption($fixture),
        };
    }

    private function combatCaption(array $fixture): string
    {
        $title = trim((string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? 'Fight event'));
        if ($title === '') {
            $home = trim((string) ($fixture['home_team'] ?? $fixture['strHomeTeam'] ?? ''));
            $away = trim((string) ($fixture['away_team'] ?? $fixture['strAwayTeam'] ?? ''));
            $title = trim($home . ($home !== '' && $away !== '' ? ' vs ' : '') . $away);
        }

        $date = trim((string) ($fixture['date_label'] ?? $fixture['dateEvent'] ?? 'Date TBC'));
        $time = trim((string) ($fixture['kickoff_label'] ?? $fixture['time'] ?? 'Time TBC'));

        return mb_substr(implode("\n", [
            $title !== '' ? $title : 'Fight event',
            trim($date . ' ' . $time),
            '',
            'PPV: Check the PPV folders for this event.',
        ]), 0, 1000);
    }

    private function genericChannelCaption(array $fixture): string
    {
        $primary = $this->normalizeChannel((string) ($fixture['tv_channel'] ?? ''));
        $channels = [];

        foreach ((array) ($fixture['tv_channels'] ?? []) as $channel) {
            $label = $this->normalizeChannel((string) $channel);
            if ($label === '' || strcasecmp($label, $primary) === 0) {
                continue;
            }

            $channels[strtolower($label)] = $label;
        }

        $parts = [];

        if ($channels !== []) {
            $parts[] = 'Other UK channels: ' . implode(', ', array_values($channels));
        }

        return mb_substr(implode("\n\n", $parts), 0, 1000);
    }

    private function normalizeChannel(string $channel): string
    {
        return trim(preg_replace('/\s+/', ' ', $channel) ?? $channel);
    }
}
