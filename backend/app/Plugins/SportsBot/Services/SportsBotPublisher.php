<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Contracts\SportsBotContentModuleInterface;
use App\Plugins\SportsBot\Models\SportsBotTelegramMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class SportsBotPublisher
{
    public function __construct(
        private readonly TelegramRoutingService $routingService = new TelegramRoutingService(),
        private readonly TelegramNotifier $notifier = new TelegramNotifier(),
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
        } catch (Throwable $error) {
            Log::error('sportsbot.publisher.send_failed', [
                'content_key' => $module->key(),
                'route_key' => $module->routeKey(),
                'error' => $error->getMessage(),
            ]);

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
                    mb_substr('🔴 Live Now · updated ' . now()->format('H:i'), 0, 1000),
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
