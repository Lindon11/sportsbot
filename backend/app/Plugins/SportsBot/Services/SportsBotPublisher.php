<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use App\Plugins\SportsBot\Support\SportsFixtureConfig;
use App\Plugins\SportsBot\Support\TelegramRouteKeys;
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
                'card_version' => $config['default_card_version'] ?? 'v3',
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
            $isFixtureModule = in_array($module->key(), ['FOOTBALL_FIXTURES', 'BASKETBALL_FIXTURES', 'BASEBALL_FIXTURES', 'AMERICAN_FOOTBALL_FIXTURES', 'TENNIS_FIXTURES', 'RUGBY_FIXTURES', 'CRICKET_FIXTURES', 'FIGHT_FIXTURES', 'MOTORSPORT_FIXTURES', 'ICE_HOCKEY_FIXTURES', 'GOLF_FIXTURES'], true);
            if ($isFixtureModule) {
                $results = $this->sendFixtureCards($summary, $message, $options);
            } elseif ($module->key() === 'HIGHLIGHTS' && method_exists($module, 'renderCards')) {
                $cards = $module->renderCards($summary);
                $results = [];

                foreach ($cards as $card) {
                    if (empty($card['path']) || !is_file($card['path'])) {
                        continue;
                    }
                    $cardOptions = $options;
                    $cardType = $card['type'] ?? 'result';

                    if ($cardType === 'result') {
                        $videoUrl = trim((string) ($card['video_url'] ?? ''));
                        if ($videoUrl !== '') {
                            $cardOptions['reply_markup'] = [
                                'inline_keyboard' => [[
                                    ['text' => '▶️ Watch Highlights', 'url' => $videoUrl],
                                ]],
                            ];
                            $cardOptions['embed_url'] = $videoUrl;
                            $cardOptions['embed_title'] = '▶ Watch Highlights';
                            $cardOptions['embed_footer'] = 'Highlights';
                        } else {
                            $cardOptions['reply_markup'] = [
                                'inline_keyboard' => [[
                                    ['text' => '❌ No Highlights Available', 'callback_data' => 'none'],
                                ]],
                            ];
                        }
                    }

                    foreach ($this->notifier->sendPhoto((string) $card['path'], '', $cardOptions) as $result) {
                        $results[] = $result;
                    }
                }

                if ($results === []) {
                    $results = $this->notifier->send($message, $options);
                }
            } else {
                $card = $this->renderCard($module->key(), $summary);
                if ($card !== null) {
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

        $routeKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? ''));
        $brandGroups = $this->resolveBrandGroups($routeKey);
        $cardVersion = $this->fixtureCardVersionFromOptions($options);
        $captionsEnabled = $this->fixtureCaptionsFromOptions($options);
        $results = [];

        $sendFixtures = function (array $groupTargets, array $branding) use ($fixtures, $summary, $options, $cardVersion, $captionsEnabled, &$results): void {
            $hasCustomBranding = $branding !== [];
            $currentLeague = null;

            foreach ($fixtures as $fixture) {
                $leagueName = trim((string) ($fixture['league'] ?? $fixture['strLeague'] ?? ''));

                if ($leagueName !== '' && $leagueName !== $currentLeague) {
                    $currentLeague = $leagueName;

                    $leagueInfo = $this->leagueInfoFromFixture($fixture);
                    $leagueRouteKey = $this->routeKeyForFixture($fixture, $options);
                    $leagueCard = $this->cards->leagueCard($leagueInfo, $cardVersion, array_merge(
                        ['route_key' => $leagueRouteKey],
                        $hasCustomBranding ? ['branding' => $branding] : [],
                    ));
                    $leagueIdempotencyKey = $this->leagueHeaderIdempotencyKey($leagueRouteKey, $leagueName, $summary, $options);
                    $leagueOptions = $options;
                    unset($leagueOptions['reply_markup']);
                    $leagueOptions['route_key'] = $leagueRouteKey;
                    $leagueOptions['idempotency_key'] = $leagueIdempotencyKey;
                    $leagueOptions['payload'] = array_merge((array) ($options['payload'] ?? []), [
                        'idempotency_key' => $leagueIdempotencyKey,
                        'event_id' => '',
                        'card_version' => $cardVersion,
                        'type' => 'LEAGUE_HEADER',
                        'league' => $leagueName,
                        'fixture' => [
                            'time' => '',
                            'league' => $leagueName,
                            'home_team' => '',
                            'away_team' => '',
                            'tv_channel' => '',
                        ],
                    ]);

                    if ($hasCustomBranding && $groupTargets !== []) {
                        foreach ($this->notifier->sendPhotoToTargets((string) $leagueCard['path'], '', $leagueOptions, $groupTargets) as $result) {
                            $results[] = $result;
                        }
                    } else {
                        foreach ($this->notifier->sendPhoto((string) $leagueCard['path'], '', $leagueOptions) as $result) {
                            $results[] = $result;
                        }
                    }
                }

                $renderOptions = $hasCustomBranding ? ['branding' => $branding] : [];
                $card = $this->cards->fixtureCard($fixture, $cardVersion, $renderOptions);
                $fixtureOptions = $options;
                unset($fixtureOptions['reply_markup']);
                $fixtureOptions['route_key'] = $this->routeKeyForFixture($fixture, $options);
                $fixtureIdempotencyKey = $this->fixtureIdempotencyKey($fixture, $fixtureOptions['route_key'], $options);
                $fixtureOptions['idempotency_key'] = $fixtureIdempotencyKey;
                $fixtureOptions['payload'] = array_merge((array) ($options['payload'] ?? []), [
                    'idempotency_key' => $fixtureIdempotencyKey,
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
                if ($hasCustomBranding && $groupTargets !== []) {
                    foreach ($this->notifier->sendPhotoToTargets((string) $card['path'], $caption, $fixtureOptions, $groupTargets) as $result) {
                        $results[] = $result;
                    }
                } else {
                    foreach ($this->notifier->sendPhoto((string) $card['path'], $caption, $fixtureOptions) as $result) {
                        $results[] = $result;
                    }
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
            throw new RuntimeException('No fixture cards were sent. Check card rendering and route configuration.');
        }

        return $results;
    }

    /**
     * @return array<int, array{branding:array<string,mixed>,targets:array<int,array{chat_id:string,message_thread_id:int|null}>}>
     */
    private function resolveBrandGroups(string $routeKey): array
    {
        if ($routeKey === '') {
            return [];
        }

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

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $options
     */
    private function routeKeyForFixture(array $fixture, array $options): string
    {
        $explicitRoute = trim((string) ($fixture['route_key'] ?? ''));
        if ($explicitRoute !== '') {
            $routeKey = TelegramRouteKeys::normalize($explicitRoute);
            if (in_array($routeKey, TelegramRouteKeys::all(), true)) {
                return $routeKey;
            }
        }

        $sportKey = trim((string) ($options['payload']['sport_key'] ?? $fixture['sport_key'] ?? ''));
        if ($sportKey !== '') {
            return SportsFixtureConfig::routeKeyForFixture($sportKey, $fixture);
        }

        $routeKey = TelegramRouteKeys::normalize((string) ($options['route_key'] ?? ''));

        return in_array($routeKey, TelegramRouteKeys::all(), true) ? $routeKey : '';
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $options
     */
    private function fixtureIdempotencyKey(array $fixture, string $routeKey, array $options): string
    {
        $eventId = (string) ($fixture['event_id'] ?? $fixture['idEvent'] ?? '');
        $date = (string) ($fixture['publish_date'] ?? $fixture['date'] ?? $fixture['date_label'] ?? $fixture['dateEvent'] ?? '');
        $sportKey = (string) ($options['payload']['sport_key'] ?? $fixture['sport_key'] ?? $fixture['sport'] ?? '');
        $fallbackName = implode('|', [
            (string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? ''),
            (string) ($fixture['home_team'] ?? ''),
            (string) ($fixture['away_team'] ?? ''),
            (string) ($fixture['time'] ?? ''),
        ]);

        return 'fixture:' . sha1(implode('|', [$sportKey, $date, $routeKey, $eventId !== '' ? $eventId : $fallbackName]));
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $options
     */
    private function leagueHeaderIdempotencyKey(string $routeKey, string $leagueName, array $summary, array $options): string
    {
        $date = (string) ($summary['date'] ?? $summary['date_label'] ?? now()->toDateString());
        $sportKey = (string) ($options['payload']['sport_key'] ?? $summary['sport_filter'] ?? $summary['sport_key'] ?? '');

        return 'league_header:' . sha1(implode('|', [$sportKey, $date, $routeKey, $leagueName]));
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
            'BASKETBALL_FIXTURES' => 'basketball',
            'BASEBALL_FIXTURES' => 'baseball',
            'AMERICAN_FOOTBALL_FIXTURES' => 'american_football',
            'TENNIS_FIXTURES' => 'tennis',
            'RUGBY_FIXTURES' => 'rugby',
            'CRICKET_FIXTURES' => 'cricket',
            'FIGHT_FIXTURES' => 'fights',
            'MOTORSPORT_FIXTURES' => 'formula_1',
            'ICE_HOCKEY_FIXTURES' => 'ice_hockey',
            'GOLF_FIXTURES' => 'golf',
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
     * @return array<string, mixed>
     */
    private function leagueInfoFromFixture(array $fixture): array
    {
        $date = trim((string) ($fixture['date_label'] ?? $fixture['date'] ?? $fixture['dateEvent'] ?? ''));
        $formattedDate = $date !== '' ? $date : now()->format('j M Y');

        return [
            'name' => $fixture['league'] ?? $fixture['strLeague'] ?? 'League',
            'sport' => $fixture['sport'] ?? $fixture['strSport'] ?? $fixture['sport_key'] ?? '',
            'badge' => $fixture['league_badge'] ?? $fixture['strLeagueBadge'] ?? '',
            'logo' => $fixture['league_logo'] ?? $fixture['strLeagueLogo'] ?? '',
            'date' => $formattedDate,
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function fixtureCaption(array $fixture, string $contentKey = ''): string
    {
        $key = strtoupper($contentKey);
        $sportMap = [
            'FOOTBALL_FIXTURES' => 'football',
            'BASKETBALL_FIXTURES' => 'basketball',
            'BASEBALL_FIXTURES' => 'baseball',
            'AMERICAN_FOOTBALL_FIXTURES' => 'american_football',
            'TENNIS_FIXTURES' => 'tennis',
            'RUGBY_FIXTURES' => 'rugby',
            'CRICKET_FIXTURES' => 'cricket',
            'FIGHT_FIXTURES' => 'fights',
            'MMA_FIXTURES' => 'mma',
            'BOXING_FIXTURES' => 'boxing',
            'MOTORSPORT_FIXTURES' => 'formula_1',
            'ICE_HOCKEY_FIXTURES' => 'ice_hockey',
            'GOLF_FIXTURES' => 'golf',
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
                'HIGHLIGHTS' => $this->firstResultCard($summary),
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
    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    private function firstResultCard(array $summary): ?array
    {
        $highlights = (array) ($summary['highlights'] ?? []);

        foreach ($highlights as $h) {
            if (is_array($h)) {
                return $this->cards->fixtureCard($h, 'v3', ['kind' => 'result']);
            }
        }

        return null;
    }

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

    private function captionFor(SportsBotContentModuleInterface $module, array $summary, string $message): string
    {
        $caption = match ($module->key()) {
            'FIXTURES_TODAY' => '📋 Today\'s Fixtures · ' . (int) ($summary['fixtures_total'] ?? 0) . ' events',
            default => $message,
        };

        return mb_substr($caption, 0, 1000);
    }
}
