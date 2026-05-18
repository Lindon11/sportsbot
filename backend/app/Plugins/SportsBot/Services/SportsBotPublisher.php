<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SportsBotPublisher
{
    public function __construct(
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
        private readonly SportsBotNotifier $notifier = new SportsBotNotifier(),
        private readonly SportsBotCardRenderer $cards = new SportsBotCardRenderer(),
        private readonly SportsBotSettingsService $settings = new SportsBotSettingsService(),
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
     * @param array<string, mixed> $extraOptions
     * @return array<string, mixed>
     */
    public function sendSportFixtures(string $sportKey, string $source = 'publisher', array $extraOptions = []): array
    {
        $config = SportsFixtureConfig::for($sportKey);

        if ($config === null) {
            return [
                'sent' => false,
                'error' => "Unknown sport: {$sportKey}",
            ];
        }

        $contentKey = strtoupper($sportKey) . '_FIXTURES';

        $summary = app(FixturesTodayService::class)->buildSummary($sportKey, (int) ($config['lookahead_days'] ?? 0));
        $message = app(FixturesTodayFormatter::class)->format($summary);

        $options = array_merge([
            'parse_mode' => '',
            'route_key' => $config['topic_key'],
            'type' => $contentKey,
            'payload' => [
                'source' => $source,
                'content_key' => $contentKey,
                'sport_key' => $sportKey,
                'card_version' => $config['default_card_version'] ?? 'v1',
                'captions_enabled' => (bool) ($config['captions_enabled_default'] ?? false),
            ],
        ], $extraOptions);

        $routeStatus = $this->routingService->resolveTargets($config['topic_key']);
        $preview = [
            'content_key' => $contentKey,
            'route_key' => $config['topic_key'],
            'route_status' => $routeStatus,
            'summary' => $summary,
            'message' => $message,
        ];

        try {
            $results = $this->sendFixtureCards($summary, $message, $options);
        } catch (Throwable $error) {
            Log::error('sportsbot.publisher.sport_send_failed', [
                'sport_key' => $sportKey,
                'content_key' => $contentKey,
                'error' => $error->getMessage(),
            ]);

            throw $error;
        }

        Log::info('sportsbot.publisher.sport_sent', [
            'sport_key' => $sportKey,
            'content_key' => $contentKey,
            'result_count' => count($results),
        ]);

        return array_merge($preview, [
            'sent' => true,
            'results' => $results,
        ]);
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
            $isFixtureModule = in_array($module->key(), ['FOOTBALL_FIXTURES', 'RUGBY_FIXTURES', 'FIGHT_FIXTURES', 'MOTORSPORT_FIXTURES'], true);
            if ($isFixtureModule) {
                $results = $this->sendFixtureCards($summary, $message, $options);
            } else {
                $card = $this->renderCard($module->key(), $summary);
                if ($module->key() === 'LIVE_NOW') {
                    $edited = $this->editLiveNowInPlace($preview, $message, $options, $card);
                    if ($edited !== null) {
                        $results = $edited;
                    } elseif ($card !== null) {
                        $results = $this->notifier->sendPhoto((string) $card['path'], $this->captionFor($module, $summary, $message), $options);
                    } else {
                        $results = $this->notifier->send($message, $options);
                    }
                } elseif ($card !== null) {
                    $results = $this->notifier->sendPhoto((string) $card['path'], $this->captionFor($module, $summary, $message), $options);
                } else {
                    $results = $this->notifier->send($message, $options);
                }
            }
        } catch (Throwable $error) {
            Log::error('sportsbot.publisher.send_failed', [
                'content_key' => $module->key(),
                'route_key' => $module->routeKey(),
                'error' => $error->getMessage(),
            ]);

            if (($isFixtureModule ?? false) === true) {
                throw $error;
            }

            $results = $this->notifier->send($message, $options);
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

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function sendFixtureCards(array $summary, string $message, array $options): array
    {
        $fixtures = $this->flattenFixtures($summary);

        if ($fixtures === []) {
            if (!$this->cardsEnabled()) {
                throw new RuntimeException('Fixture cards are disabled; text fallback is not allowed for fixture sends.');
            }

            $cardVersion = $this->fixtureCardVersionFromOptions($options);
            $card = $this->cards->noFixturesCard($this->noFixturesSummary($summary, $options), $cardVersion);

            return $this->notifier->sendPhoto((string) $card['path'], '', $options);
        }

        if (!$this->cardsEnabled()) {
            throw new RuntimeException('Fixture cards are disabled; text fallback is not allowed for fixture sends.');
        }

        $results = [];
        $cardVersion = $this->fixtureCardVersionFromOptions($options);
        $captionsEnabled = $this->fixtureCaptionsFromOptions($options);

        foreach ($fixtures as $fixture) {
            $card = $this->cards->fixtureCard($fixture, $cardVersion);
            $fixtureOptions = $options;
            unset($fixtureOptions['reply_markup']);
            $fixtureOptions['payload'] = array_merge((array) ($options['payload'] ?? []), [
                'event_id' => (string) ($fixture['event_id'] ?? ''),
                'card_version' => $cardVersion,
                'fixture' => [
                    'time' => (string) ($fixture['time'] ?? ''),
                    'league' => (string) ($fixture['league'] ?? ''),
                    'home_team' => (string) ($fixture['home_team'] ?? ''),
                    'away_team' => (string) ($fixture['away_team'] ?? ''),
                    'tv_channel' => (string) ($fixture['tv_channel'] ?? ''),
                ],
            ]);

            $contentKey = (string) (($fixtureOptions['payload']['content_key'] ?? $options['payload']['content_key'] ?? '') ?: '');
            $caption = $captionsEnabled ? $this->fixtureCaption($fixture, $contentKey) : '';
            foreach ($this->notifier->sendPhoto((string) $card['path'], $caption, $fixtureOptions) as $result) {
                $results[] = $result;
            }
        }

        if ($results === []) {
            throw new RuntimeException('No fixture cards were sent. Check card rendering and route configuration.');
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $options
     */
    private function noFixturesSummary(array $summary, array $options): array
    {
        $payload = (array) ($options['payload'] ?? []);
        $contentKey = strtoupper((string) ($payload['content_key'] ?? $options['type'] ?? 'SPORTS_FIXTURES'));
        $sportKey = match ($contentKey) {
            'FOOTBALL_FIXTURES' => 'football',
            'RUGBY_FIXTURES' => 'rugby',
            'FIGHT_FIXTURES' => 'fights',
            'MOTORSPORT_FIXTURES' => 'formula_1',
            default => (string) ($payload['sport_key'] ?? $summary['sport_filter'] ?? 'sports'),
        };

        return [
            'sport' => $sportKey,
            'sport_key' => $sportKey,
            'sport_label' => (string) ($summary['title'] ?? SportsFixtureConfig::emoji($sportKey) . ' ' . SportsFixtureConfig::providerSport($sportKey) . ' Fixtures TV'),
            'title' => (string) ($summary['title'] ?? ucwords(str_replace('_', ' ', $sportKey)) . ' Fixtures TV'),
            'date' => (string) ($summary['date'] ?? now()->toDateString()),
            'route_key' => (string) ($options['route_key'] ?? ''),
            'fixtures_total' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fixtureCardVersionFromOptions(array $options): string
    {
        $payload = (array) ($options['payload'] ?? []);
        $version = strtolower(trim((string) ($payload['card_version'] ?? $this->settings->get('football_fixture_card_version', 'v3'))));

        return in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fixtureCaptionsFromOptions(array $options): bool
    {
        $payload = (array) ($options['payload'] ?? []);

        if (array_key_exists('captions_enabled', $payload)) {
            return (bool) $payload['captions_enabled'];
        }

        return (bool) $this->settings->get('football_fixture_captions_enabled', false);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param array<string, mixed> $fixture
     */
    private function fixtureCaption(array $fixture, string $contentKey = ''): string
    {
        $key = strtoupper($contentKey);
        $sportMap = [
            'FOOTBALL_FIXTURES' => 'football',
            'RUGBY_FIXTURES' => 'rugby',
            'FIGHT_FIXTURES' => 'fights',
            'MMA_FIXTURES' => 'mma',
            'BOXING_FIXTURES' => 'boxing',
            'MOTORSPORT_FIXTURES' => 'formula_1',
        ];

        $sportKey = $sportMap[$key] ?? null;
        $formatter = $sportKey !== null ? SportsFixtureConfig::captionFormatter($sportKey) : 'generic';

        return match ($formatter) {
            'combat' => $this->combatCaption($fixture),
            'rugby' => $this->otherChannelsCaption($fixture),
            'football' => $this->otherChannelsCaption($fixture),
            default => $this->otherChannelsCaption($fixture),
        };
    }

    /**
     * @param array<string, mixed> $fixture
     */
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

    /**
     * @param array<string, mixed> $fixture
     */
    private function otherChannelsCaption(array $fixture): string
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

    private function cardsEnabled(): bool
    {
        return (bool) $this->settings->get('cards_enabled', config('plugins.SportsBot.cards.enabled', true))
            && (bool) $this->settings->get('rich_cards_enabled', config('plugins.SportsBot.features.rich_cards', true));
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    private function renderCard(string $key, array $summary): ?array
    {
        if (
            !((bool) $this->settings->get('cards_enabled', config('plugins.SportsBot.cards.enabled', true)))
            || !((bool) $this->settings->get('rich_cards_enabled', config('plugins.SportsBot.features.rich_cards', true)))
        ) {
            return null;
        }

        try {
            return match ($key) {
                'FIXTURES_TODAY' => $this->firstFixtureCard($summary),
                'LIVE_NOW' => $this->firstLiveCard($summary),
                'TV_GUIDE' => $this->cards->tvGuideCard($summary),
                default => null,
            };
        } catch (Throwable $error) {
            Log::warning('sportsbot.publisher.card_render_failed', [
                'content_key' => $key,
                'error' => $error->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    private function firstFixtureCard(array $summary): ?array
    {
        foreach ((array) ($summary['grouped'] ?? []) as $fixtures) {
            foreach ((array) $fixtures as $fixture) {
                if (is_array($fixture)) {
                    return $this->cards->fixtureCard($fixture);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    private function firstLiveCard(array $summary): ?array
    {
        foreach ((array) ($summary['grouped'] ?? []) as $matches) {
            foreach ((array) $matches as $match) {
                if (is_array($match)) {
                    return $this->cards->liveMatchCard($match);
                }
            }
        }

        return null;
    }

    private function captionFor(SportsBotContentModuleInterface $module, array $summary, string $message): string
    {
        $caption = match ($module->key()) {
            'FIXTURES_TODAY' => '📋 Today’s Fixtures · ' . (int) ($summary['fixtures_total'] ?? 0) . ' events',
            'TV_GUIDE' => '📺 TV Guide · ' . (int) ($summary['events_total'] ?? 0) . ' events',
            'LIVE_NOW' => '🔴 Live Now · ' . (int) ($summary['live_total'] ?? 0) . ' events',
            default => $message,
        };

        return mb_substr($caption, 0, 1000);
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $card
     * @return array<int, array<string, mixed>>|null
     */
    private function editLiveNowInPlace(array $preview, string $message, array $options, ?array $card): ?array
    {
        $routeStatus = (array) ($preview['route_status'] ?? []);
        $targets = (array) ($routeStatus['targets'] ?? []);
        if ($targets === []) {
            return null;
        }

        $results = [];

        foreach ($targets as $target) {
            $chatId = (string) ($target['chat_id'] ?? '');
            $threadId = $target['message_thread_id'] ?? null;
            $last = SportsBotTelegramMessage::query()
                ->where('route_key', 'LIVE_NOW')
                ->where('chat_id', $chatId)
                ->where('status', 'sent')
                ->when($threadId === null, fn ($query) => $query->whereNull('message_thread_id'))
                ->when($threadId !== null, fn ($query) => $query->where('message_thread_id', $threadId))
                ->whereNotNull('telegram_message_id')
                ->latest('id')
                ->first();

            if (!$last instanceof SportsBotTelegramMessage || !$last->telegram_message_id) {
                return null;
            }

            $ok = false;
            if ($card !== null && !empty($card['path'])) {
                $ok = $this->notifier->editMessageMedia(
                    $chatId,
                    $last->telegram_message_id,
                    (string) $card['path'],
                    mb_substr('🔴 Live Now · updated ' . now()->format('g:i A'), 0, 1000),
                    (array) ($options['reply_markup'] ?? [])
                );
            }

            if (!$ok) {
                $ok = $this->notifier->editMessageText(
                    $chatId,
                    $last->telegram_message_id,
                    $message,
                    (array) ($options['reply_markup'] ?? [])
                );
            }

            if (!$ok) {
                return null;
            }

            $last->update([
                'payload' => array_merge((array) $last->payload, [
                    'edited_at' => now()->toIso8601String(),
                    'edit_source' => $options['payload']['source'] ?? 'publisher',
                ]),
                'sent_at' => now(),
            ]);

            $results[] = [
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
                'message_id' => $last->telegram_message_id,
                'route_key' => 'LIVE_NOW',
                'edited' => true,
            ];
        }

        return $results;
    }
}
